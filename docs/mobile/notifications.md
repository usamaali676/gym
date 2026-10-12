# Notifications (mobile)

**Status: one local notification is built and tested without a phone. It has never been shown on a device. Push
(Firebase / APNs) is not built.** Nothing here pretends otherwise: the app does not register for push, does not ask for the
push permission and does not claim a notification will arrive from the server.

## What exists: the "rest is over" alert

While the app is in the background during a rest between sets, the phone itself shows "Rest is over. Next: Bench press, set 2
of 3" at the moment the rest ends. There is no server involved and no push service.

* **Off until the member turns it on** (Account → *Rest timer alert*). Turning it on shows the system permission prompt at
  that moment (Android 13+: notifications; iOS: alert and sound). If the member refuses, the switch stays off and the screen
  says how to allow it in the phone's settings. Until it is switched on the app never calls the notification system at all.
* **Only while the app is in the background.** In the app the screen already counts down, buzzes and shows the next set, so a
  notification would be a duplicate. When the app comes back to the foreground the alert is cancelled.
* **Driven by the workout engine's own absolute end time**, not by counting seconds. The alert is rescheduled when the member
  adds 30 s, and removed when the rest is skipped, paused, over, or the workout is finished or abandoned. A paused rest has no
  alert because it does not end by itself.
* **One alert id.** A new schedule replaces the old one; two alerts can never stack.
* **Stale alerts are cleaned up.** If the app is closed during a rest the alert stays scheduled (that is the point). The next
  time the app comes to the foreground with the feature on, any alert still waiting is cancelled. Signing out and switching the
  feature off cancel it too, so a different person is never shown the previous member's alert.
* **A failure never reaches the workout.** If the system refuses to schedule, the error is swallowed and the next change
  tries again; the workout screen behaves exactly as before.
* **Content**: the exercise name and set number only (it appears on the lock screen). No weights, no health numbers.

### Platform notes

| | |
| --- | --- |
| Android | `POST_NOTIFICATIONS` (asked when the switch is turned on, not at start-up) and `SCHEDULE_EXACT_ALARM`. Without exact timing the system may deliver the alert minutes late, so Account explains this and offers *Allow exact timing* (it opens the system's "Alarms & reminders" screen); the alert still works, late, if the member declines. **Google Play requires a declaration for apps that use exact alarms**; a rest timer is a timer, but Google decides. If the declaration is refused, remove `SCHEDULE_EXACT_ALARM` from `AndroidManifest.xml`: the alert then uses an inexact window (see `PlatformLocalAlerts.schedule`). The plugin needs core-library desugaring (enabled in `android/app/build.gradle.kts`) and its scheduled-notification receiver (declared in the manifest). The boot receiver is not declared on purpose: a rest lasts minutes, so a reboot cancelling it is acceptable. |
| iOS | Nothing to add to `Info.plist`. Notifications are not asked for at start-up. Time Sensitive delivery is not used (it needs an entitlement). A scheduled local notification is delivered by the system even if the app is killed. |

## How it is verified

* `test/core/rest_alerts_test.dart` (13 tests, a fake notification centre): scheduled only when switched on, in the background
  and a rest is running; exact time and text; not duplicated, replaced when the rest is extended; a rest already over is not
  scheduled; skipping, pausing, finishing or returning cancels it; an alert left by an earlier run is removed on return;
  turned off or never used touches nothing; a failing system does not throw and the next change retries; calls reach the
  system one at a time and the last word wins; the setting is off by default, asks permission, is remembered, and a refusal
  leaves it off.
* `test/features/rest_alert_flow_test.dart` (10 tests): the real workout screen and app lifecycle: leaving during a rest
  schedules the alert for the engine's end time, coming back cancels it; off by default means zero calls; +30 s moves it; a
  paused rest has none; skipping removes it; signing out removes a waiting alert; the Account switch (off by default, no
  calls on merely opening Account, permission prompt on turning on, refusal message, the late-delivery note and the
  exact-timing button).
* **Mutation check:** four deliberate breaks (not clearing on sign-out, scheduling while in the foreground, scheduling a
  paused rest, not cancelling a stale alert on return) each made tests fail.
* The Android and iOS builds in CI compile the plugin.

## Not verified

* That a notification actually appears on a real Android or iOS device, at the right time, with the app backgrounded, killed,
  in Doze / low-power mode, or with the phone in Do Not Disturb.
* That `PlatformLocalAlerts` uses the plugin's current API correctly beyond compiling (the permission prompts and exact-alarm
  screen especially).
* Android battery-optimisation behaviour on manufacturer skins.
* Exact-alarm approval by Google Play.

## Not built

* **Push notifications (FCM / APNs).** The server has an FCM provider that is off unless configured and is tested with a faked
  transport only. The app has no `firebase_messaging`, no `google-services.json` / `GoogleService-Info.plist`, no APNs key, no
  push-token registration (`POST /me/devices` with a token), and does not ask for push permission. To enable it you need a
  Firebase project and keys; then add the plugin, the config files and the permission flow, and test on devices.
* Notification categories and quiet hours on the phone (the server stores the settings; the app does not show them yet).
* Reminders for workouts or meals, and announcements as notifications.
