# Security notes: mobile app and API

**Status: reviewed by the author of the code, with automated checks. Not an external security review and not a penetration
test.** Read the last section for what that leaves open.

The rule behind everything: **the app is an untrusted client.** Anyone can decompile it, run a modified copy or call the API
with their own program. So nothing that matters is decided in the app; the app asks, the server decides.

## What the server decides (and how it is checked)

| Decision | Where | Evidence |
| --- | --- | --- |
| Who the caller is | Sanctum bearer token, one per installation, revocable (sign-out; a password change signs out the other devices; account deletion removes all) | `AuthTest`, `SecurityTest` |
| What a membership includes | Entitlements checked on every route and inside every offline-sync operation | `SecurityTest` walks **every** `/api/v1` route (anonymous gets 401; a member without the entitlement gets 403 or a locked teaser; another member's ids get 404); `SyncApiTest` per-operation checks |
| Whose data it is | Every query is scoped to the token's user; foreign ids answer 404, not 403 | `SecurityTest` isolation checks; the live test sends a change for someone else's workout and sees `not_found` |
| XP, levels, streaks, badges, personal records | Computed from stored sets by the server | `WorkoutApiTest`, gamification tests |
| Calories and macros | Computed by the server from food records; the app sends a food id, an amount and a unit, never a number to store | `NutritionApiTest`; the app has no food maths |
| Prices and "purchase succeeded" | Nothing is accepted from the client: there is no store verifier, so there is no purchase flow | `docs/mobile/payments.md` |
| Duplicate submissions | Idempotency keys and per-operation ids; a replay returns the first answer | `ContentTest`, `SyncApiTest`, live test |
| Rate limits | Separate limiters for public, auth, general, sync, uploads and purchases | `routes/api_v1.php` |
| Response hygiene | `Cache-Control: no-store`, `X-Content-Type-Options: nosniff` on every API answer; errors never carry stack traces | `SecurityTest` |
| Media | Private disk, 6-hour signed URLs, uploads checked by real content type and renamed, never served from the web root; a teaser carries no media | `ContentTest` media tests, `DemoClipsTest` |

### Self-reported data: the camera form coach

The pose analysis runs **on the phone**, so the server cannot verify it. Posture sets, scores and the posture badges derived
from them are therefore **self-reported**. The server validates the shape and refuses impossible numbers (good + bad = total,
scores in range, started within the last two days, and, added during this review, more than two reps per second of set time,
which is what a modified app posting "500 reps in 5 seconds" would send). A determined cheater can still post plausible numbers
and earn a posture badge. Nothing competitive or paid depends on posture data; if that changes, it needs a different design.

## What the app does

| Area | Behaviour | Evidence |
| --- | --- | --- |
| Token storage | Keychain (iOS, this device only, after first unlock) / Keystore-backed storage (Android); never in preferences, logs or URLs; one header builder (plus the refresh call) | `source_hygiene_test.dart`; `core/storage/secure_store.dart` |
| Token lifetime | Rotation with a single in-flight refresh; a rejected refresh signs the member out and wipes the cache | `session_manager` tests |
| Transport | HTTPS only in release builds (cleartext refused at startup); Android cleartext allowed in the debug manifest only; iOS has no arbitrary-loads exception. **No certificate pinning** (deliberate: a pinned certificate that rotates would lock every installed app out until a store update) | `source_hygiene_test.dart`; `environment.dart` |
| Logging | The app writes **nothing** to the console or any log: no `print`, no `debugPrint`, no `dart:developer`. A test fails if one appears. No crash or analytics SDK, so no third party receives anything | `source_hygiene_test.dart` |
| Credentials in the app | None: no API keys, secrets or private keys in source (a test scans for key shapes and secret-looking assignments) and none in the build (the only build input is the public API address) | `source_hygiene_test.dart` |
| What the server can make the app open | The store address comes from the server. Only `https://host/...` is opened; `tel:`, `sms:`, `intent:`, `javascript:`, `file:`, custom schemes, `http:` and addresses with embedded credentials are refused, and a malformed address never throws. Found during this review: before it, any string was passed to the system launcher | `store_link_test.dart` |
| WebView, deep links | None. Every screen is native; the Android manifest has only the launcher intent filter; iOS registers no URL scheme | `source_hygiene_test.dart` |
| Camera | Frames are analysed in memory and dropped; landmarks and pictures are never stored or sent (only the numbers of a finished set) | `docs/mobile/posture-coach.md` |
| Backups | Android auto-backup off; the iOS keychain item is not synced to other devices | `AndroidManifest.xml`, `secure_store.dart` |
| Account deletion | In the app, server-side delete of the account and its data, then the phone forgets the session, cache and unsent changes | `delete_account_test.dart`, live test |
| Different person on the same phone | Cache and offline queue are stored per account id; sign-out purges the cache; a pending notification is cancelled | `cache` and `offline_queue` tests, `rest_alert_flow_test.dart` |

## Known weaknesses and trade-offs

1. **Cached answers and the offline queue are unencrypted** in the app's private preferences storage. On a rooted or
   jailbroken phone, or in an unencrypted device backup (iOS), they are readable. They contain the member's own plan, food log,
   measurements and unsent sets. The cache is cleared on sign-out; the queue is deliberately **kept** on sign-out (clearing it
   would lose a workout) and cleared on account deletion. A database encrypted with a key held in the Keystore/Keychain would
   close this; it is not built.
2. **No root or jailbreak detection, no screenshot blocking, no app-switcher privacy cover.** Root detection can be bypassed and
   gives false confidence; the design does not rely on the device being honest. The app-switcher snapshot may show health data.
3. **No certificate pinning** (above). A phone that trusts a malicious root certificate can be intercepted.
4. **A stolen unlocked phone is a stolen session.** There is no app lock or biometric gate.
5. **Rendered clips and media are streamed from signed URLs** that anyone holding the URL can use for six hours.
6. **No crash reporting**, so a field failure is invisible to the gym until someone reports it. Adding one means disclosing it
   in the store privacy forms.
7. **Dependencies are not audited.** `flutter pub outdated` shows 14 packages with newer incompatible versions; no
   vulnerability scan of the Dart or PHP dependency trees has been run in CI.
8. **The Google ML Kit pose model may send diagnostics to Google**; this was not verified (see `release.md`).
9. **Admin accounts** use the same API; an admin token is as powerful as in the web app. Admin-only routes are gated by role on
   the server (`SecurityTest`).

## What was not done

* An independent security review or penetration test of the API or the apps.
* Dynamic testing of the apps on a device (traffic interception, storage inspection, tampering).
* Dependency vulnerability scanning in CI (`composer audit`, a Dart advisory check).
* A formal threat model workshop; the table above is the author's own list.

## How the guards run

* `mobile/test/app/source_hygiene_test.dart` (10 tests): no console logging, no WebView, no cleartext literals outside the
  environment file, no disabled certificate checks, one place that builds the Authorization header, system launcher only through
  the checked helper, no key- or secret-shaped strings, Android release manifest (no cleartext, backups off, only the launcher
  filter) and iOS plist (no arbitrary loads, no URL schemes). A probe file containing a `print` and a key-shaped string made two
  of them fail; removing it made them pass.
* `mobile/test/core/store_link_test.dart` (5 tests; 2 mutations caught: no scheme check, no userinfo check).
* PHP: `SecurityTest`, `AuthTest`, `SyncApiTest`, and the posture plausibility cases in `PostureSessionApiTest`.
