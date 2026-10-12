# IronPulse mobile app (Android + iOS)

One Flutter codebase, native UI (no WebView), talking to the same Laravel backend and database as the website through
the versioned `/api/v1` API ([contract](../docs/mobile/openapi.yaml), [plan](../docs/mobile/01-implementation-audit.md),
[progress log](../docs/mobile/PROGRESS.md)).

## Run it

```bash
# 1. the backend (from the repository root)
php artisan serve --host=0.0.0.0 --port=8000

# 2. the app
cd mobile
flutter pub get
flutter run                                   # debug build; Android emulator -> http://10.0.2.2:8000, iOS simulator -> http://localhost:8000
flutter run --dart-define=API_BASE_URL=http://192.168.1.20:8000     # a real phone on your Wi-Fi
```

Release builds **must** be given the server, and it must be HTTPS:

```bash
flutter build apk --release --dart-define=API_BASE_URL=https://gym.example.com
flutter build appbundle --release --dart-define=API_BASE_URL=https://gym.example.com
```

Debug builds allow clear-text HTTP to your own machine only. Release builds refuse it (Android's default) and
`AppEnvironment.isSecureEnough` is false for a non-HTTPS URL in release mode.

No secret ever goes into the app: only the public API address. Store and push credentials live on the server.

## Checks

```bash
flutter analyze
flutter test                                  # unit + widget + golden tests
flutter test --update-goldens test/goldens    # after an intentional visual change; review the PNGs first

# against a real Laravel server (the app's own repositories, real payloads; also a CI job)
php artisan migrate:fresh --seed && php artisan serve --port=8123
flutter test test/live --dart-define=LIVE_API_URL=http://127.0.0.1:8123

# the camera coach's Dart engine is checked against the website's engine; regenerate after changing either one
node scripts/export-posture-parity.mjs        # writes test/fixtures/posture_parity.json.gz (CI runs it with --check)
```

Widget tests load the real brand fonts and the Material icon font (`test/flutter_test_config.dart`), so golden images
look like the phone, not like boxes.

## Where things are

```
lib/
  main.dart, app/            app widget, router + guards, theme (tokens.g.dart is generated from the website's CSS)
  core/api/                  ApiClient (dio): headers, retry rules, error mapping, 426/503 signals
  core/authentication/       Session, TokenStore (secure storage), SessionManager (rotation, single-flight refresh)
  core/policy/               /app/config: version policy, maintenance, feature switches, profile choices
  core/widgets/              NeonButton, GlassCard, NeonTextField, StatusChip, state views, ...
  features/<name>/           authentication, onboarding, home, app_gate, dashboard, membership, workout_programs,
                             exercise_library, workout_sessions, nutrition, progress, posture
test/                        mirrors lib/; test/support has a fake server and an in-memory world
assets/fonts/                Orbitron, Rajdhani, Inter (SIL OFL), converted from the website's @fontsource files
```

## Design tokens and fonts

The website's CSS (`resources/css/app.css`, the `@theme` block) is the only source of colours, radii and font stacks.
`node scripts/export-design-tokens.mjs` regenerates `design/tokens.json` and `lib/app/theme/tokens.g.dart`; a JS test and
`test/app/design_tokens_test.dart` fail when they drift. `python3 scripts/export-mobile-fonts.py` rebuilds the `.ttf`
files from `node_modules/@fontsource`.

## What works today, and what does not

Built, and covered by tests (see the [progress log](../docs/mobile/PROGRESS.md) for the counts and CI runs):

* sign-in, registration, guest mode (when the server allows it), forgot-password request, onboarding with server-provided
  choices, session restore, token rotation and revocation handling, offline start with a cached account, version and
  maintenance gates, sign-out
* dashboard, membership screen (shows plan and entitlements; no purchase button, see [payments](../docs/mobile/payments.md)),
  Train (week, day, programmes), exercise library, the Start Workout engine with persisted-timestamp timers and
  server-computed results, Fuel (server-side food maths, logging), Progress (trend, measurements, records, badges)
* the camera form coach: the website's engine ported to Dart and verified against it frame by frame
  ([details and limits](../docs/mobile/posture-coach.md))
* every screen has a loading, error, offline (cached) and locked-by-plan state

* the offline queue: sets, skips, finish and abandon of a workout in progress, camera sets and body measurements are kept on
  the phone without a connection and sent in order, once, when it returns ([how it works](../docs/mobile/offline-sync.md))

* exercise demonstration clips: rendered loops of the website's 3D figure (not footage of a person), played on the exercise
  page when the server has one ([pipeline and limits](../docs/mobile/demo-clips.md))

* an optional rest-timer alert: a local notification while the app is in the background during a rest, off until the member
  turns it on ([details and limits](../docs/mobile/notifications.md))

Not built yet: offline food/water/habit logging, progress photos, push notifications (FCM/APNs), in-app purchase.

**Nothing in this folder has been run on a physical phone, an emulator or an iOS device by the author of these changes.**
The tests run on a desktop Flutter test host; the Android debug APK is built by GitHub Actions and never installed; iOS needs
macOS with Xcode and has never been built. The camera coach's camera and pose-model layer in particular has never executed.
