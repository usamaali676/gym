# Working offline (mobile)

**Status: built and tested on a desktop test host and against a real local server. Never tried on a phone in a real
basement gym.** This page says what works without a connection, what does not, and how the pieces fit.

## What works without a connection

| Area | Offline behaviour |
| --- | --- |
| Reading (dashboard, plan, programmes, exercises, nutrition day, progress, badges, membership, posture rules) | The last answer the server gave is shown, with an "Offline. Showing data saved at 18:04" strip. Nothing is shown that was not once received from the server. |
| A workout already started | Timers, the engine and the screens work from the phone's own clock and saved state. **Sets, skips, finish and abandon are kept in the offline queue** and the workout moves on at once; each queued set is marked "waiting to sync". |
| Camera form coach | The set is judged on the phone. If the result cannot be saved it is kept in the queue. A rule already fetched can be used offline. |
| Body measurement | Kept in the queue; the form closes, nothing to retype. |

## What needs a connection

* **Starting a workout.** The server builds the session from the member's plan and history (suggestions, last results).
* Signing in, registering, onboarding, password reset, membership changes.
* **Food logging and the food calculator.** The app does no food maths; the server does (150 g × 20 g/100 g = 30 g), so an
  offline entry would have no numbers. Food logging, habits and water are therefore online-only for now. (The server's batch
  endpoint accepts `food.log.*`, `water.*` and `habit.*`; the app does not use them yet.)
* Starting a camera set for an exercise whose rule was never fetched.
* Anything that shows live server results: the workout **results** (XP, personal records, streak, badges) are computed by the
  server, so a workout finished offline shows only what the phone knows and says the rest appears after it syncs.

## How the queue works

```text
member does something offline
   └─► PendingOp {op_id (uuid), type, payload, created_at, label}        core/sync/pending_op.dart
        └─► stored per account on the phone                               core/sync/pending_store.dart
             └─► POST /sync/batch  (in order, 100 at a time)               core/sync/offline_queue.dart
                  ├─ applied    → removed; screens that show the result are refreshed
                  ├─ duplicate  → the server had it already; treated as what it decided the first time
                  ├─ conflict   → kept, with the server's copy, for the member to decide (Sync screen)
                  ├─ rejected   → kept with the server's reason; never retried (Sync screen: Dismiss)
                  └─ error / not_processed / no connection → stays queued, retried later
```

* **Online first.** With a connection nothing changes: the call goes straight to the server and the screen waits for its
  answer. The queue is only used when the call fails because the phone cannot reach the server, or when earlier changes to the
  *same workout* are still waiting (so the server always sees sets in the order they were done).
* **Idempotent.** Each change has a client-generated op id. The server applies an op id once and answers a repeat with
  `duplicate`, so a lost response or a retry cannot double-log a set. The batch request itself also carries an
  `Idempotency-Key`.
* **When it is sent.** Straight after a change, when the app returns to the foreground, when the member presses Sync now,
  and on a timer after a failure: 15 s, 30 s, 1 min, 2 min, then every 5 min. Only the timer's own retries lengthen the wait,
  so a phone that regains signal is not left waiting because several sets were logged while it was offline.
* **Same checks as online.** The server runs each queued change through the same validation as the online endpoint and checks
  the member's plan **per change** (a queued change is never a way around a paywall); an unexpected server error stops the
  batch so ordering is kept.
* **Conflicts.** State-like records (a body measurement, a corrected set) are compared by time: if the server holds a *newer*
  version of the same record the change comes back as a conflict and is never silently overwritten either way. The Sync screen
  shows both and offers **Keep server's** (drops the change) or **Use mine** (sends it again as a new change stamped now; it
  needs a new op id because the server remembers the first as a conflict).
* **Per account, durable.** Stored under the signed-in account's id in the phone's preferences. A different account never
  sees or sends it. Sign-out does **not** delete it (that would silently lose a workout); the sign-out dialog says how many
  changes have not reached the server, and they are sent the next time that account signs in.
* **Where to see it.** A strip above the tab bar ("2 changes are saved on this phone and waiting to sync") appears only when
  something is waiting; Account → Sync lists every change, when it was made and what the server said.

## Maintenance mode and forced updates

A workout in progress on the phone is **not** blocked by maintenance or by a required update: the gate screens offer
**Continue my workout**, the workout opens from saved data, and everything done goes to the queue. After a required update the
queue is sent by the new version (an app update keeps local storage). Everything else stays blocked as before.

## Server contract

`POST /sync/batch` (docs/mobile/openapi.yaml, schema `SyncBatch`) takes up to 100 operations
`{op_id, type, payload, client_created_at}`. Types the app uses: `workout.set.log`, `workout.exercise.skip` (added for the
app), `workout.finish`, `workout.abandon`, `measurement.save`, `posture.session.save`. The server also supports
`workout.set.delete`, `workout.state.save`, `food.log.*`, `water.*`, `habit.*`, which the app does not queue yet. Resume state
(`workout.state.save`) is deliberately not queued: the phone keeps its own copy and the server's is best-effort.

## How it is verified

* `test/core/offline_queue_test.dart` (22 tests): persistence across restarts, order and timestamps, every server result
  status, no-connection and expired-sign-in behaviour, a malformed batch, one pass at a time, batches of 100, conflict
  decisions, account isolation, the backoff schedule.
* `test/features/workout_offline_test.dart` (12 tests) and the posture and progress tests: log a set offline and carry on; the
  mark and the strip; later sets queue behind the first; refusal shown with its reason; a plain server error is *not* queued;
  skip, finish (honest summary), abandon; reopening the app; Account and the sign-out warning; conflict choices; maintenance
  and a workout in progress.
* **Mutation check:** five deliberate breaks (reusing the op id on "use mine", treating a replayed refusal as applied, letting
  a new set skip the line, not writing the queue to disk, escalating the retry wait on every attempt) each made tests fail.
* `test/live/live_api_test.dart` runs the queue against the **real Laravel server**: two sets, a skip, a measurement, the
  finish and one change for someone else's workout are sent in one batch; five are applied, the foreign one is refused with
  `not_found`, the server's records match, and replaying an op id returns `duplicate`.
* PHP: `SyncApiTest` (including the new `workout.exercise.skip`).

## Known limits

1. Never run on a device or with a real flaky network (the tests use scripted failures).
2. Only workouts, measurements and camera sets queue. Food, water and habits do not.
3. A set dismissed from the Sync screen after being refused stays visible on that workout screen until it is reloaded.
4. The retry timer only runs while the app is alive; if the app is closed the queue is sent the next time it opens (there is
   no background sync service).
5. Preferences storage is used for the queue (the same store as the read cache); it is durable for these small lists but is not
   a database. A phone with thousands of queued changes is not a case this was built or tested for.
6. The queue does not merge. A conflict is decided by the member; there is no field-level merge.
