# Mobile conversion: progress log

Only things that were actually executed are marked as done. "Test results" quote real runs.
Environment limits (no Android SDK, no Xcode, no device) are in [01-implementation-audit.md](01-implementation-audit.md) §0.

## Phase 1: Audit and architecture

**Status:** done.

| | |
| --- | --- |
| Tasks | Repository inspected; audit written; design tokens exported from the web CSS; token drift test; Flutter SDK 3.47.7 installed in the build environment. |
| Files | `docs/mobile/01-implementation-audit.md`, `docs/mobile/PROGRESS.md`, `scripts/export-design-tokens.mjs`, `design/tokens.json`, `mobile/lib/app/theme/tokens.g.dart`, `tests/js/design-tokens.test.mjs`, `resources/css/app.css` (+1 token) |
| Database changes | none |
| API changes | none |
| Dependencies | none added to the app |
| Acceptance | audit reviewed; token files regenerate byte-identically from `app.css`; web unchanged apart from one new CSS variable (`--color-warn`). |
| Test results | `npm run test:js`: 153 passed, 0 failed (150 existing + 3 new). |
| Outstanding | none |

## Phase 2: Backend API (`/api/v1`)

**Status:** done for the scope below; store billing adapters are deliberately not included (see Outstanding).

| | |
| --- | --- |
| Tasks | Sanctum bearer-token auth with per-install `devices` and token rotation; guest accounts; error envelope, request ids, idempotency keys, rate limits, `no-store` headers; membership plans, entitlements and subscriptions with server-side enforcement (web and API, also per operation inside `/sync/batch`); payment-verifier abstraction and `/membership/purchases/verify` + `/restore`; content publishing (draft/published/archived) with a change feed (manifest, snapshot, incremental changes, tombstones, cursor expiry); per-member rendering of locked content; signed expiring media URLs; announcements, banners, help, app settings; app-version policy (min/latest/recommended, release notes, maintenance); workout sessions and set logging with resume state; nutrition (server-side calculator: 150 g × 20 g/100 g = 30 g), food log, favorites, saved meals; progress, measurements, photos, habits, achievements, posture sessions; notification preferences (quiet hours in the member's time zone, generic lock-screen text by default) and FCM provider (off unless configured); offline batch sync with `op_id` dedupe and conflict reporting; OpenAPI 3.1 spec; admin screens for plans, subscriptions, programs/phases, exercise media, announcements, banners, help, settings, app releases; scheduled housekeeping. |
| Files | `routes/api_v1.php`, `app/Http/Controllers/Api/V1/*`, `app/Http/Resources/V1/*`, `app/Http/Requests/Api/V1/*`, `app/Http/Middleware/**`, `app/Services/{Membership,Content,App,Sync,Push,Api}/*`, `app/Support/{Entitlement,Entitlements,MediaUrls,AppSettingKeys}.php`, `app/Models/*` (new + patched), `app/Http/Controllers/Admin/*` + `resources/views/admin/*`, `config/{sanctum,ironpulse,filesystems}.php`, `bootstrap/app.php`, `docs/mobile/{openapi.yaml,openapi-src/*,payments.md}`, `.github/workflows/ci.yml`, `tests/Feature/Api/V1/*`, `tests/Feature/Admin/*` |
| Database changes | 10 additive migrations (`2026_10_12_000001`–`000010`): `personal_access_tokens`; `users.status`; `devices`; `membership_plans`, `subscriptions`, `subscription_events` (+ a default `free` plan row); publishing columns, `program_phases`, `exercise_media`, `announcements`, `banners`, `help_articles`, `app_settings`, `content_changes`; `app_version_policies`, `app_releases`; `food_favorites`, `saved_meals`; `idempotency_keys`, `sync_operations`; push preference columns; announcement push marker. Nothing dropped; existing rows keep working (the web app is unchanged). |
| API changes | 112 routes under `/api/v1` (all in `docs/mobile/openapi.yaml`). Public: app config, guest/register/login, refresh handled with a valid token, public help/announcements. Everything else needs a bearer token; plan-gated routes also need the matching entitlement. |
| Dependencies | `laravel/sanctum` ^4.3 (composer). CI-only: PyYAML + `openapi-spec-validator`. |
| Acceptance | Every protected route answers 401 with the error envelope without a token, with a garbage token and with only a web session; every entitled route answers 403 `entitlement_required` when the plan grants nothing and works when it does; other members' records answer 404; spec and routes agree and live responses validate against the schemas; admins can change plans/content without a code change. |
| Test results (executed) | `./vendor/bin/pest` on SQLite: **850 passed, 20289 assertions, 0 failed** (3 min). The same suite on MariaDB 10.11: 847 passed, 3 failed — all three were old tests that only held on SQLite (`SUM()` returned a string; a hard-coded exercise id `1`). Fixed the tests; the three files then passed on MariaDB (47 tests). The complete suite was not re-run on MariaDB after those test fixes; CI now has a MySQL 8 job, whose first result is still to be read. `npm run test:js`: 153 passed. `vendor/bin/pint`: clean. `docs/mobile/openapi.yaml` regenerates with no diff and validates with `openapi-spec-validator`. |
| Outstanding | (1) No Apple/Google verifier or store webhook controller: see `payments.md`; nothing was run against a store. (2) `FcmPushProvider` is tested with a faked HTTP transport only. (3) Push is implemented for announcements, membership expiry reminders and (via the preference model) reminders; achievements are shown by the app as local notifications from API data, not pushed. (4) Email verification is not implemented, because the website has none either. (5) `POST /sync/batch` resolves conflicts as "server newer wins" and reports them; there is no merge UI. (6) Interactive 3D inside the app is not planned; the API serves rendered demo clips instead (Phase 5). (7) MySQL 8 and the OpenAPI-drift job were added to CI but had not run when this was written. |
