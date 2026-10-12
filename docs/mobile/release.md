# Releasing the mobile app

**Status: nothing here has been done for real.** No store account, signing key, TestFlight build or Play track exists. This
page is the procedure, the answers to the store questionnaires as far as the code decides them, and an honest list of what is
still missing. Read [posture-coach.md](posture-coach.md), [offline-sync.md](offline-sync.md) and [payments.md](payments.md)
first: each has "never run on a device" or "not built" caveats that matter for a release decision.

## 0. What CI proves, and what it does not

| Check | Where | Meaning |
| --- | --- | --- |
| `flutter analyze`, all tests, goldens | CI job "Flutter app" | the code and the screens' look |
| debug APK builds | same | the Android project compiles with every plugin |
| release AAB + per-ABI APKs build (R8, debug-signed) | same | the release configuration works; sizes are in the job summary |
| iOS release build, **no code signing** | CI job "iOS build" (macOS 15 runner) | the iOS project, Podfile and plugins compile for iOS 15.5+ |
| live contract test against a real server | CI job "App against the real API" | the app's data layer and the API agree |

It does **not** prove: that the app runs on any phone, that the camera coach works with a real camera, that signing works,
that a store accepts the build, or that in-app purchase works (there is none).

## 1. Decisions to make before the first upload (they cannot be changed later)

| Decision | Where it is set | Current value |
| --- | --- | --- |
| Android `applicationId` | `mobile/android/app/build.gradle.kts` (`applicationId`, `namespace`) and the Kotlin package of `MainActivity` | `com.ironpulse.app` (**placeholder**) |
| iOS bundle id | `PRODUCT_BUNDLE_IDENTIFIER` in `mobile/ios/Runner.xcodeproj/project.pbxproj` (Runner and RunnerTests) | `com.ironpulse.app` (**placeholder**) |
| App name shown on the phone | `android:label` in `AndroidManifest.xml`; `CFBundleDisplayName` in `Info.plist` | IronPulse |
| Version | `mobile/pubspec.yaml` `version: 1.0.0+1` (name + build number; the build number must rise with every upload) | 1.0.0+1 |
| API address | `--dart-define=API_BASE_URL=https://…` at build time (must be HTTPS for a release build) | none baked in |

Changing the id means renaming the Kotlin package directory and updating the three places on iOS; do it once, early.

## 2. Android (Google Play)

1. **Play Console account** (one-time fee), create the app, choose the default language, free/paid, and declare it a fitness app.
2. **Upload key** (never commit it):

   ```bash
   keytool -genkey -v -keystore ~/ironpulse-upload.jks -keyalg RSA -keysize 2048 -validity 10000 -alias upload
   ```

   Create `mobile/android/key.properties` (git-ignored):

   ```properties
   storeFile=/home/you/ironpulse-upload.jks
   storePassword=…
   keyAlias=upload
   keyPassword=…
   ```

   Without this file a release build is signed with the debug key, which Google Play rejects (deliberately). Enrol in **Play
   App Signing** so Google holds the app signing key and the upload key can be reset.
3. **Build:** `cd mobile && flutter build appbundle --release --dart-define=API_BASE_URL=https://your.server`
   → `build/app/outputs/bundle/release/app-release.aab`. Upload it to **Internal testing** first and install it from the Play
   link on a real phone.
4. **Store listing:** short and full description, icon (512 px), feature graphic, at least two phone screenshots, the
   privacy-policy URL (required), a contact email.
5. **App content:** *Data safety* form (section 5), *Content rating* questionnaire, *Target audience* (not for children),
   *App access*: give the reviewers a working account (the server has a demo seeder; do not use a real member), *Health apps*
   declaration, and the account-deletion URL (the app deletes accounts in-app; the website offers it too).
6. **Permissions requested by the app:** `INTERNET`, `CAMERA` (camera form coach; the camera hardware is *not* required to
   install). No location, contacts, storage, microphone or notification permission is requested today.
7. Before production: run the device checklists in [posture-coach.md](posture-coach.md) and the section 7 smoke test below.

## 3. iOS (App Store)

Needs a Mac with Xcode and an Apple Developer Program membership. Nothing below has been run.

1. In App Store Connect create the app record with the bundle id from section 1. Register the App ID (no extra capabilities are
   needed today; add *Push Notifications* only when FCM/APNs is wired).
2. Open `mobile/ios/Runner.xcworkspace` in Xcode, select the *Runner* target → *Signing & Capabilities* → your team,
   automatic signing. Minimum iOS is **15.5** (Google ML Kit, used by the camera coach).
3. `cd mobile && flutter build ipa --release --dart-define=API_BASE_URL=https://your.server`, then upload with Xcode Organizer or
   Transporter. The first `pod install` happens during the build (`ios/Podfile` sets the 15.5 platform).
4. **TestFlight** internal testing first. If a simulator build on Apple Silicon fails to link ML Kit, test on a device.
5. **App Store Connect:** screenshots for the required device sizes, description, keywords, support URL, privacy-policy URL,
   age rating, *App Privacy* answers (section 5), export compliance (the app uses HTTPS only; answer accordingly), and review
   notes with a demo account.
6. **Privacy manifest:** `ios/Runner/PrivacyInfo.xcprivacy` declares no tracking, the data types in section 5 and the
   `UserDefaults` required-reason API. Plugins ship their own manifests. Keep it in step with your answers.

## 4. In-app purchase / subscriptions

**Not built.** The app shows the member's plan and what it includes and has no purchase button, because no store verifier
exists on the server ([payments.md](payments.md)). Memberships sold at the gym or on the web are used in the app as before.
Digital memberships sold *inside* the iOS/Android app must use Apple In-App Purchase / Google Play Billing; do not add a link
or button to a web checkout for the same digital goods inside the app. The store review will ask how members get a membership,
so decide this model before submitting.

## 5. Privacy and data-safety answers (derived from the code)

Confirm every row against the live product and your privacy policy before you submit; this is an engineering inventory, not
legal advice.

| Data | Collected? | Linked to the member | Purpose | Notes |
| --- | --- | --- | --- | --- |
| Name, email address | yes (account) | yes | app functionality, account management | guests have no email |
| Password | yes | yes | authentication | stored hashed on the server; never stored on the phone (only a session token, in the OS keychain/keystore) |
| Age, sex, height, weight, activity level, goal, diet preference, equipment, training days | yes (onboarding) | yes | personalised plan and targets | health and fitness data |
| Workouts, sets, personal records, XP, streaks, badges | yes | yes | app functionality | |
| Body measurements, notes | yes | yes | app functionality | health data |
| Food log and nutrition targets | yes | yes | app functionality | |
| Camera pictures | **no**: analysed in memory on the phone, never recorded, stored or sent | n/a | — | only counts, scores and error ids of a set are sent |
| Pose landmarks | **no**: never sent | n/a | — | |
| Device id (a random installation id), device model, OS version, app version, locale, time zone | yes | yes | security (one token per install), compatibility, scheduling | no advertising id is read |
| Crash or analytics data | **no SDK**: nothing is collected | n/a | — | add a disclosure if you add one |
| Precise or coarse location, contacts, photos library, microphone | not accessed | n/a | — | |

* **Tracking:** none. No third-party advertising or analytics SDK. Data is not sold or shared for advertising.
* **Third-party SDK:** Google ML Kit (pose detection, bundled model, runs on the phone). Google's own documentation says ML Kit
  may send usage/diagnostic data to Google; **read Google's current ML Kit data disclosure and reflect it in the forms**: this
  was not verified here.
* **Encryption in transit:** HTTPS only in release builds (cleartext is refused). No certificate pinning (a deliberate
  trade-off: pinning breaks when the certificate rotates and there is no way to ship a new pin without an app update).
* **At rest on the phone:** the session token is in the platform's secure storage. Cached answers and the offline queue are in
  the app's private preferences storage **unencrypted** (the app's private sandbox; Android auto-backup is off). On a rooted or
  jailbroken device or in an unencrypted device backup this data is readable. The cache is cleared on sign-out and on account
  deletion; the offline queue is kept on sign-out (see offline-sync.md) and cleared on deletion.
* **Deletion:** Account → *Delete account* deletes the account and its data on the server immediately and clears the phone.
  Subscriptions bought through a store are not cancelled by deletion (the screen says so).
* **Retention and backups on the server** are the gym's responsibility; state them in the privacy policy.

## 6. Push notifications and local notifications

* **Push (FCM/APNs):** the server has an FCM provider that is off unless configured and is tested with a faked transport
  only. The app does not register for push or request the notification permission. To enable it: a Firebase project,
  `google-services.json` / `GoogleService-Info.plist`, an APNs key, the `firebase_messaging` plugin, and the permission prompt.
  None of that exists.
* **Local notifications** (for example "rest over" while the app is in the background): not built. Timers are derived from
  absolute time, so the next screen is right when the member returns, but nothing buzzes in the background.

## 7. Smoke test on a real build (before any release)

1. Fresh install from the store test track (not a debug build). Sign up, onboard, land on the dashboard.
2. Start today's workout, log a set, **turn on airplane mode**, log two more sets, finish; see the "waiting to sync" strip and
   the honest summary; turn airplane mode off; see the strip clear and the real summary appear.
3. Camera coach: permission prompt → a squat set → summary → saved (see posture-coach.md checklist).
4. Fuel: search a food, log 150 g, confirm the calories equal what the website shows for the same food.
5. Progress: add a weigh-in; Account → Sync shows all saved; Delete account on a throwaway account.
6. Put the server in maintenance mode from the admin: the app shows the maintenance screen; a workout in progress can still be
   continued.
7. Install a build with a lower version number and set a higher minimum in the admin: the update-required screen appears.

## 8. Known gaps that affect a release

* The camera + ML Kit layer, the offline queue and everything else have only run on a desktop test host and in CI (and the debug
  APK/iOS builds were compiled, never run).
* No in-app purchase, no push, no local notifications, demo clips exist only after you run the render pipeline ([demo-clips.md](demo-clips.md)), no progress photos, no offline food log.
* `applicationId` / bundle id are placeholders; signing is not set up; no store listing assets exist (icon, screenshots).
* The launcher icon and splash are the Flutter defaults until you add your own.
* Accessibility has had automated checks only (see the accessibility notes in the progress log), not a screen-reader session on a device.
