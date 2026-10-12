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
  features/<name>/           authentication, onboarding, home, app_gate (splash / maintenance / update required)
test/                        mirrors lib/; test/support has a fake server and an in-memory world
assets/fonts/                Orbitron, Rajdhani, Inter (SIL OFL), converted from the website's @fontsource files
```

## Design tokens and fonts

The website's CSS (`resources/css/app.css`, the `@theme` block) is the only source of colours, radii and font stacks.
`node scripts/export-design-tokens.mjs` regenerates `design/tokens.json` and `lib/app/theme/tokens.g.dart`; a JS test and
`test/app/design_tokens_test.dart` fail when they drift. `python3 scripts/export-mobile-fonts.py` rebuilds the `.ttf`
files from `node_modules/@fontsource`.

## What works today, and what does not

Works (and is covered by tests): sign-in, registration, guest mode (when the server allows it), forgot-password request,
onboarding with server-provided choices, session restore, token rotation and revocation handling, offline start with a
cached account, version/maintenance gates, sign-out. The rest of the product (workouts, nutrition, posture coach,
progress, membership screens, offline queue, notifications) is built in later phases; see the progress log.

Nothing in this folder has been run on a physical phone or an iOS device by the author of these changes; the Android
debug APK is built by GitHub Actions, and iOS needs macOS with Xcode.
