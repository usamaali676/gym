# IronPulse mobile: implementation audit and plan

Status: Phase 1 (audit and architecture). Written against the repository at commit `edae3c7`
(Laravel 12.69.3 on `main` + the 3D-demo fix). Everything below was read from the code, not assumed from
the earlier web specification. Statements about what was *built or tested* live in
[`PROGRESS.md`](PROGRESS.md), not here.

---

## 0. Environment facts that shape the plan

| Question | Finding (probed in this build environment) | Consequence |
| --- | --- | --- |
| Flutter / Dart | Not preinstalled. Flutter **3.47.7** (Dart 3.13.5) downloaded from `storage.googleapis.com` and runs. `pub.dev` is reachable. | `flutter analyze`, `flutter test` (unit + widget + golden) **can** be run and are the verification gate for the Flutter code. |
| Android SDK | Not installed. `dl.google.com` (the only place the SDK platform / build-tools are published) returns HTTP 403 from this network. | An APK / AAB **cannot be built in this environment**. A GitHub Actions job (Ubuntu runners ship the Android SDK) builds them; its result is the evidence, and nothing is claimed before it has run. |
| iOS toolchain | Linux host, no Xcode. | No iOS binary here, ever. iOS source is complete; a `macos-latest` CI job runs `flutter build ios --no-codesign`; signing / TestFlight is documented for a Mac. |
| Real devices | None. | Camera, pose inference, haptics, push, IAP and background/lifecycle behaviour are **not testable here**; they get a written device test plan and are labelled "unverified on device" until someone runs it. |
| Database | Dev and tests run on SQLite. Production target (per brief) is MySQL / MariaDB. | A MySQL service job is added to CI so the new migrations and queries are exercised on the production engine too. |
| Disk | ~9 GB free after cache cleanup. | Flutter SDK (2.5 GB) fits; heavy Android toolchains do not. |

---

## 1. What already exists

Inspection checklist from the brief, answered from the repository.

| Item | Finding |
| --- | --- |
| Laravel / PHP | Laravel **12.69.3** (the first brief said 11; 12 was chosen for security advisories). PHP `^8.2`, CI matrix 8.2 / 8.3 / 8.4, `config.platform.php = 8.2.0`. Pest 3, Pint. |
| Database | 40+ tables (see §3). SQLite dev, MySQL/MariaDB compatible schema (no SQLite-only SQL; date columns queried with `whereDate`). Seeded: 1 plan, 7 plan days, 85 exercises (31 plan + alternatives/helpers/mobility), 35 posture rules, 176 foods, 32 badges. |
| Authentication | Laravel Breeze-style **session** auth (`routes/auth.php`), guest accounts (`is_guest`, claimable), onboarding gate (`onboarded` middleware). **No Sanctum, no tokens, no API auth of any kind.** |
| Roles / permissions | `users.role` enum `user` / `admin` (`App\Enums\Role`), `admin` middleware, policies for workout sessions, food, food logs, plan items, progress photos. |
| Membership / subscriptions / payments | **None.** No plans, subscriptions, entitlements or payment code anywhere (grepped `app`, `config`, `routes`, `database`, `composer.json`). There is nothing to reuse; the payment abstraction in §3 is new. |
| Programs | One `plans` row ("Mujtaba Ahssan PPL", `version`, `rules` JSON) → 7 `plan_days` (ISO weekday, push/pull/legs/rest) → `plan_day_exercises` (sets, rep range, focus, notes, rest override). `profiles.plan_id` + `plan_started_on` select and anchor it. **There is no sprint / phase / week hierarchy in the data**: the source PDF is one repeating weekly split with progressive overload applied by the app. |
| Exercises + media | `exercises` (slug, kind plan/alternative/helper/mobility, muscle group, regions, equipment, cues, mistakes, breathing, rest, `is_active`, `demo_key`, `glb_path`). **`glb_path` is empty for all 85**: the demos are *procedural*: `humanoid.js` rig + `animations.js` keyframe specs rendered by Three.js in the browser. There are no model/video/image files to ship. |
| Nutrition | `foods` (+ `food_servings`), `food_logs` (snapshot of values), `diet_plans`, `meal_templates`, `water_logs`; `DietCalculatorService` (Mifflin-St Jeor etc.), `NutritionTotalsService`, `FoodLogService`, `FoodSearchService`. Values are snapshotted server-side at log time. |
| Profile / goals | `profiles` (body, goal, diet preference, equipment, units, timezone, water/sleep/creatine goals, opt-ins) + `notification_settings` (reminder times, digest). |
| History / progress | `workout_sessions` (+ `client_uuid`, `autosave_state`), `session_exercises` (snapshot targets), `session_sets`, `personal_records`, `progression_targets`, `measurements`, `progress_photos` (private disk, re-encoded), `daily_logs`, `weekly_reports` (+ share links, PDF). |
| Gamification | `xp_events` (unique per source → idempotent), levels, `StreakService`, 32 `badges` + `user_badges`, leaderboard (opt-in). All server-side. |
| Posture detection | Browser MediaPipe Pose Landmarker (self-hosted WASM + `pose_landmarker` model), **JSON rule engine** (schema 1) + One-Euro filter + rep state machine in JS; `PostureRuleValidator` in PHP; 35 versioned rules; `posture_sessions` stores only numeric summaries (no video leaves the device). |
| Frontend / design system | Blade + Alpine + Tailwind 4 `@theme` tokens in `resources/css/app.css` (dark + neon green). PWA (manifest, service worker). |
| Existing API | `routes/api.php`: 31 **same-origin, session-cookie + CSRF** JSON routes for the web pages (`/api/workout-sessions/...`, `/api/foods/...`, `/api/habits`, ...). Not usable by a native client (no token auth, CSRF-bound, responses shaped for page scripts). |
| Dependencies | Server: laravel/framework, dompdf, anthropic-ai/sdk, tinker. No queue worker assumed (`QUEUE_CONNECTION=database` configured but unused except digests). |
| Deployment | Unknown / none in the repo (README targets Laragon and generic PHP hosting). No CDN, object storage, queue worker or HTTPS termination is assumed. |

### Gaps against the brief (things the brief assumes that do not exist)

1. Token authentication, device records, token revocation.
2. Memberships, subscriptions, entitlements, any payment record.
3. Sprints / phases above the weekly template.
4. Real media (video / image / 3D file) for exercises.
5. A draft/published workflow (only `exercises.is_active`).
6. Announcements, banners, help articles, app settings, featured content.
7. App-version policy and maintenance mode.
8. Push tokens and notification categories (only browser-reminder times exist).
9. A content change feed for incremental sync.
10. Idempotency on set / food-log / water writes (only `workout_sessions.client_uuid` and `posture_sessions.client_uuid` exist).

---

## 2. Reusable modules (nothing below gets rewritten)

The mobile API is a **new transport over the existing services**. Business rules stay where they are.

| Capability | Reused as-is |
| --- | --- |
| Plan resolution, week overview, effective items, swaps | `PlanService`, `SwapService`, `UserExerciseSwap` |
| Progressive overload suggestions | `ProgressiveOverloadService`, `OneRepMaxService`, `PersonalRecordService` |
| Session lifecycle (start, autosave, log set, finish, abandon, XP, PRs, badges) | `WorkoutSessionService`, `WorkoutPageData`, `WorkoutSessionPolicy` |
| XP, levels, streaks, badges, leaderboard | `XpService`, `StreakService`, `BadgeService`, `BadgeMetrics`, `LeaderboardService` |
| Diet targets and meal plans | `DietCalculatorService`, `DietPlanService`, `MealPlanService`, `ProteinGapService` |
| Food search, logging, daily totals | `FoodSearchService`, `FoodLogService`, `NutritionTotalsService`, `NutritionDayService`, `FoodPolicy` / `FoodLogPolicy` |
| Water / sleep / creatine / mobility | `WaterService`, `HabitService`, `DailyLogService` |
| Body metrics, measurements, photos | `BodyMetricsService`, `ProgressService`, `ProgressPhotoService` (re-encode, private disk) |
| Weekly report + PDF + share | `WeeklyReportService`, `ReportController` logic |
| Posture rules | `posture_rules` rows + `PostureRuleValidator` (the rule JSON is the shared contract with the Dart engine) |
| Profile + onboarding | `ProfileService`, `OnboardingController` logic (extracted into the API controllers' calls) |
| Admin audit trail | `AdminAudit` |
| Account deletion | `User::deleting` hook removes photos; the same call is used by the API |

Reused **front-end assets**: design tokens (`@theme`), icon vocabulary, copy, rule JSON, animation specs (to render demo clips, §6).

---

## 3. Backend changes required

### 3.1 Authentication (decision: Laravel Sanctum, personal access tokens)

Evaluated: **Sanctum** (first-party, maintained, hashed token storage, abilities, expiry, per-device naming, fits Laravel 12), **Passport** (full OAuth2 server: unnecessary weight for a first-party app), **custom JWT** (hand-rolled revocation/rotation is exactly what Sanctum already provides). Sanctum wins.

* `laravel/sanctum` added; mobile routes use `auth:sanctum` with **bearer tokens only** (no stateful domains configured, so cookies cannot authenticate `/api/v1`). Web session auth is untouched.
* Token per device, `expires_at` (default 60 days), `POST /auth/refresh` rotates (old token revoked, new issued), `POST /auth/logout` revokes the current token, `DELETE /auth/sessions/{id}` revokes another, logout-all supported.
* Account status check: new `users.status` (`active` / `suspended`); a suspended account's tokens are rejected (`401 account_suspended`).
* Login throttle per email+IP; registration reuses `RegisteredUserController` validation rules; password reset reuses Laravel's broker (the reset link opens a hosted page, the app also accepts the token in-app).
* Email verification: supported but **config-gated** (`IRONPULSE_REQUIRE_EMAIL_VERIFICATION`, default off to match the web app today).
* Guest start: `POST /auth/guest` reuses `GuestController`'s creation logic (optional, same limits/prune job).
* Account deletion: `DELETE /me` (password required) → the existing cascade.

### 3.2 Memberships and entitlements (new, admin-managed)

* Admin "Membership" section: plans (name, description, price, currency, billing interval, duration, trial days, feature bullets, entitlement matrix, limits, active/draft, renewal + expiry/grace rules, store product ids), user subscriptions (grant, extend, revoke, promo access), audit log.
* **Entitlement keys are defined in code** (`App\Support\Entitlement`), because enforcement is code: `workouts`, `programs.premium`, `library.premium`, `nutrition`, `posture_coach`, `analytics`, `coach.ai`, `progress_photos`, plus numeric limits (e.g. `custom_foods`). The admin chooses which plan grants which key.
* `EntitlementService::for($user)` computes effective access from the *current* DB row on every call (no long-lived cache): active/trialing/grace subscription → its plan; otherwise the default (free) plan.
* Enforcement: route middleware `entitled:<key>` on every protected `/api/v1` route **and** the same middleware on the matching web routes, plus resource-level `locked` flags so a list can show a teaser without leaking premium payloads. Default seed = Free plan grants everything the app does today, so **no existing behaviour is removed**; restricting it is an explicit admin action.
* Errors: `403 entitlement_required` with `required_entitlement`, `current_plan`, `upgrade` info (the client may show a paywall, but the server never relies on it).
* **Payments** (nothing exists): a `PaymentProvider` abstraction with `ManualProvider` (admin grant), and store verifiers behind an interface for Apple (App Store Server API v2 / signed transactions) and Google (Play Developer API purchases.subscriptionsv2 + RTDN). Rules honoured: digital subscriptions sold inside the iOS/Android apps must use StoreKit / Play Billing, so the mobile paywall uses `in_app_purchase`; the client sends only the store receipt/purchase token, **the backend verifies it with the store and decides the entitlement, never the client's price or "success" flag**; webhooks (App Store Server Notifications V2, Play RTDN) are idempotent via `subscription_events.external_event_id`. The store adapters need credentials I do not have; they ship as interfaces + fakes + documented setup and are labelled **unverified against real stores**.

### 3.3 Content management and sync (new)

* `status` (`draft` / `published` / `archived`) + `published_at` on programs (existing `plans`), exercises, and the new content types; clients only ever see `published`. Existing rows are migrated to `published` (nothing disappears). `exercises.is_active` remains the web's switch and is kept in sync with `status`.
* Content **change feed** (`content_changes`): monotonic id, `collection`, `entity_id`, `action` (upsert|delete). Model observers append rows when published content changes (including unpublish/delete → tombstones). Clients sync with `GET /content/changes?after=<cursor>`; first install uses paginated snapshots and starts from the cursor read *before* the snapshot (race-free). Server clock skew is irrelevant (cursor, not timestamp).
* New content models: `announcements`, `banners` (+ featured), `help_articles`, `app_settings` (public subset only), `exercise_media`, `program_phases`.
* Historical records are never rewritten: `session_exercises` already snapshots targets, `exercise_id` is `restrictOnDelete`; the admin delete path will archive instead of delete when history exists.

### 3.4 App version policy and maintenance

* `app_version_policies` per platform: `min_supported`, `recommended`, `latest`, release notes, store URL, maintenance flag/message/window; `app_releases` for the notes history.
* Middleware reads `X-App-Platform`, `X-App-Version`, `X-App-Build`. Below `min_supported` → `426 upgrade_required` (the config endpoint stays reachable so the client can show *why*). Maintenance → `503 maintenance` with `Retry-After`.

### 3.5 Offline sync / idempotency

* `Idempotency-Key` middleware (per user + key + request fingerprint, stored response, 24 h) on mutating endpoints.
* `POST /sync/batch`: ordered operations `{op_id, type, payload, client_created_at, base_version?}` for set logs, set deletes, session finish, food logs, water, habits, measurements. Results per op: `applied | duplicate | conflict | rejected` with server state on conflict. Append-only logs dedupe on `client_uuid` (new columns on `session_sets`, `food_logs`, `water_logs`); state-like writes use last-writer-wins guarded by `base_updated_at`, never silently overwriting newer server data.

### 3.6 Notifications

* `devices` (platform, app version, push token + provider, locale, timezone, last seen, linked token) and notification **categories** on `notification_settings` (workout reminders, membership, achievements, announcements, nutrition, `lock_screen_details` default **off**).
* `PushProvider` interface; `FcmProvider` (HTTP v1) activates **only** when `FCM_PROJECT_ID` + service-account credentials are configured, otherwise a `NullProvider` records nothing and the API reports `push: unavailable`. APNs is reached through FCM (APNs auth key uploaded to Firebase); no direct APNs code.
* Jobs: membership-expiry reminder, announcement fan-out (queue worker + scheduler required, documented).

### 3.7 API plumbing

`/api/v1` (file `routes/api_v1.php`, names `v1.*`), API Resources, FormRequests, Policies reuse, rate limiters (`v1`, `v1-auth`, `v1-sync`), cursor/offset pagination with a hard cap, consistent error envelope `{error:{code,message,details,request_id}}`, `X-Request-Id`, ETag/`If-None-Match` on content, OpenAPI 3.1 spec at `docs/mobile/openapi.yaml` with a test that fails when a route and the spec disagree.

---

## 4. Database changes (all additive; no column or table is dropped or renamed)

| Migration | Change |
| --- | --- |
| `users` | `+ status` (active/suspended, default active), `+ suspended_reason` |
| `personal_access_tokens` | Sanctum table (`expires_at` used) |
| `devices` | user, token link, platform, device name, app version/build, OS, push provider+token (encrypted), locale, timezone, `last_seen_at` |
| `membership_plans` | slug, name, description, `price_minor`, `currency`, interval + count, duration days, trial days, `features` JSON, `entitlements` JSON, `limits` JSON, renewal rule, grace days, store product ids JSON, `is_default`, sort, `status`, `published_at` |
| `subscriptions` | user, plan, status (trialing/active/grace/canceled/expired/revoked), source (admin/promo/apple/google), external id, period + trial + end timestamps, auto-renew, granted_by, notes |
| `subscription_events` | idempotent store/webhook/admin event log (`external_event_id` unique) |
| `plans` | `+ status`, `+ published_at`, `+ description`, `+ cover_path`, `+ required_entitlement` |
| `program_phases` | plan, kind (sprint/phase), name, summary, `starts_week`, `ends_week`, settings JSON, status, sort |
| `exercises` | `+ status`, `+ published_at`, `+ required_entitlement` |
| `exercise_media` | exercise, kind (demo_video/image/thumbnail/instruction_video/model), `view` (angle/side/front), disk+path, mime, bytes, checksum, duration, size, status, sort |
| `announcements`, `banners`, `help_articles`, `app_settings` | admin content with status + schedule window + audience (plan keys) |
| `content_changes` | change feed (see 3.3) |
| `app_version_policies`, `app_releases` | see 3.4 |
| `notification_settings` | `+` category booleans, `lock_screen_details`, quiet hours |
| `session_sets`, `food_logs`, `water_logs` | `+ client_uuid` (unique per user scope) for idempotent offline writes |
| `idempotency_keys` | user, key, fingerprint, response code/body, expires_at |

Existing data is preserved by defaulting new status columns to `published` and creating the default Free plan + an active subscription-less fallback rather than touching users.

---

## 5. Proposed `/api/v1` endpoint list

Base: `https://<host>/api/v1`. Bearer token unless marked *public*. 🔒 = entitlement-checked on the server.

**App / meta (public)**: `GET /app/config` (version policy, maintenance, feature flags, public settings, server time) · `GET /health`

**Auth**: `POST /auth/register` · `POST /auth/login` · `POST /auth/guest` · `POST /auth/refresh` · `POST /auth/logout` · `POST /auth/logout-all` · `POST /auth/forgot-password` · `POST /auth/reset-password` · `POST /auth/email/resend` · `GET /auth/sessions` · `DELETE /auth/sessions/{id}`

**Account**: `GET /me` · `PATCH /me` (name/email) · `PUT /me/password` · `DELETE /me` · `GET/PUT /me/profile` · `POST /me/onboarding` · `GET/PUT /me/settings` · `GET/PUT /me/notifications` · `POST/DELETE /me/devices` (push token)

**Membership**: `GET /membership/plans` · `GET /membership` (current plan + status + entitlements) · `GET /membership/entitlements` · `POST /membership/purchases/verify` (store receipt → server verification) · `POST /membership/restore`

**Content**: `GET /content/manifest` (collection versions + latest cursor) · `GET /content/changes?after=` · `GET /content/{collection}` (paginated snapshot) · `GET /announcements` · `GET /banners` · `GET /help` · `GET /help/{slug}`

**Programs**: `GET /programs` · `GET /programs/{id}` (phases + week template) 🔒 · `GET /programs/{id}/weeks/{n}` · `GET /programs/{id}/days/{weekday}` · `POST /me/program` (select) · `GET /plan/week?date=` · `GET /plan/day/{weekday}?date=` · `POST /plan/day/{weekday}/skip|unskip|complete` · `GET/POST/DELETE /plan-items/{id}/swap`

**Exercises**: `GET /exercises?search&group&equipment&kind&cursor` · `GET /exercises/{slug}` · `GET /exercises/{slug}/media` · `GET /exercises/{slug}/alternatives`

**Workout sessions**: `POST /workout-sessions` (start/resume for a day; idempotent on `client_uuid`) · `GET /workout-sessions/active` · `GET /workout-sessions/{id}` · `PUT /workout-sessions/{id}/state` · `POST /workout-sessions/{id}/sets` · `DELETE /workout-sessions/{id}/sets/{set}` · `POST .../exercises/{id}/skip|swap` · `GET .../exercises/{id}/alternatives` · `POST /workout-sessions/{id}/finish` · `POST /workout-sessions/{id}/abandon` · `GET /workout-sessions?cursor` (history) · `GET /workout-sessions/{id}/summary`

**Posture** 🔒: `GET /posture/exercises` (supported list + rule version) · `GET /posture/rules/{exercise}` · `POST /posture-sessions` (numeric summary only)

**Nutrition** 🔒: `GET /nutrition/targets` · `POST /nutrition/targets/recalculate` · `GET /nutrition/day?date=` · `GET /nutrition/history?from&to` · `GET /nutrition/meal-plan` · `GET /foods/search` · `GET /foods/{id}` · `POST /foods` (custom) · `DELETE /foods/{id}` · `POST /nutrition/calculate` (server-side grams × per-100 g, serving conversion) · `POST /food-logs` · `POST /food-logs/batch` · `POST /food-logs/copy` · `PATCH/DELETE /food-logs/{id}` · `GET/POST/DELETE /foods/favorites` · `GET/POST/DELETE /saved-meals` · `GET /habits` · `POST/DELETE /water` · `PUT /creatine|sleep|mobility`

**Progress** 🔒: `GET /progress/summary` · `GET/POST /progress/measurements` · `DELETE /progress/measurements/{id}` · `GET /progress/strength/{exercise}` · `GET /progress/records` · `GET/POST/DELETE /progress/photos` (+ image stream) · `GET /achievements` (badges, XP, level, streak) · `GET /leaderboard` · `GET /reports` · `GET /reports/{week}`

**Sync**: `POST /sync/batch`

**Coach (optional)**: `POST /coach/messages` · `DELETE /coach/messages` (only when the server enables it)

Everything not listed above that the web does (weekly PDF, share links) is deferred and documented, not stubbed.

---

## 6. Technical decisions

| Topic | Decision | Why |
| --- | --- | --- |
| State management | **Riverpod 3** (`flutter_riverpod`), no code generation | One approach, testable with `ProviderContainer`, no `build_runner` step to break the build or CI. |
| Routing | `go_router` (shell route + redirect guard for auth / onboarding / forced-update) | Declarative redirects fit the gating states. |
| HTTP | `dio` with interceptors: auth header, request-id, safe-retry (GET/HEAD/idempotent-keyed only, exponential backoff + jitter), error mapping, 401 → single-flight refresh → logout, 426/503 → policy state | Centralised, per the brief. |
| Secure storage | `flutter_secure_storage` (Keychain / EncryptedSharedPreferences-Keystore) for tokens only; non-secret cache in SQLite | Tokens never in plain preferences. |
| Local DB | `sqflite` (JSON documents + pending-op queue); tests use `sqflite_common_ffi` on the host | Durable queue/cache without codegen. |
| Charts | `fl_chart` | Matches neon style via theme. |
| **Exercise demos** | **Rendered clips**: the existing Three.js rig is rendered offline (headless Chromium + the real `ExerciseViewer`) into short looping videos per camera view (angle / side / front), uploaded as `exercise_media`, played with `video_player`; muscle highlighting is baked in because the same viewer draws it. Interactive in-app 3D is **not** promised. | Evaluated: `three_dart` (abandoned 2022), `model_viewer_plus` / `flutter_3d_controller` (WebView-based: against rule 4), `flutter_scene` (experimental, Impeller-only, no GLB assets exist to feed it). Video is reliable on every device, honest ("rendered demonstration"), admin-replaceable, and needs no model files. Orbit/rotate in the app is therefore a **documented limitation**; front/side/angle switching, play, pause, replay are real. |
| **Posture coach** | **On-device ML Kit Pose Detection** (`google_mlkit_pose_detection`, BlazePose 33-landmark topology, same indices the JSON rules already use) + `camera`. The rule engine, One-Euro filter and rep state machine are **ported to Dart** and verified against the *same* fixtures as the JS tests (parity tests). | There is no official Flutter binding for MediaPipe Tasks Pose Landmarker; ML Kit is Google's maintained on-device runtime with the same landmark model family and no server round-trip. Platform support is checked against pub.dev metadata at implementation time. Camera video never leaves the device. A manual rep counter is the fallback. |
| Payments | `in_app_purchase` client + server-side verification | Store policy (see 3.2). |
| Push | `firebase_messaging` initialised **only** if the build includes Firebase config; local reminders via `flutter_local_notifications` otherwise | Don't promise push before the project exists. |

### Flutter project structure (`mobile/`)

```
mobile/
  pubspec.yaml
  analysis_options.yaml
  assets/fonts/            # Orbitron, Rajdhani, Inter (converted from the web's @fontsource files, OFL)
  lib/
    main.dart
    app/
      app.dart             # MaterialApp.router, lifecycle hooks (resume refresh)
      router.dart          # go_router + guards (auth, onboarding, update-required, maintenance)
      config/              # Environment (API base URL via --dart-define), feature flags
      theme/               # tokens.g.dart (generated from CSS), theme.dart, typography
    core/
      api/                 # ApiClient (dio), interceptors, ApiException, pagination, error codes
      authentication/      # TokenStore (secure), AuthController, refresh single-flight
      storage/             # LocalDb (sqflite), CacheRepository, PendingOps queue
      networking/          # connectivity, online/offline banner, sync engine
      errors/              # Failure types, user-facing messages
      widgets/             # NeonButton, GlassCard, NeonInput, Gauge, Bar, Chip, EmptyState, ErrorState, Skeleton, ...
      utilities/           # time (persisted-timestamp timers), units, haptics/sound
    features/
      onboarding/  authentication/  dashboard/  memberships/  workout_programs/
      exercise_library/  workout_sessions/  posture_coach/  nutrition/  food_calculator/
      progress/  achievements/  profile/  notifications/  settings/  help_announcements/
        each: data/ (repository + DTOs) · domain/ (small models) · presentation/ (screens, controllers)
  test/                    # unit, widget, golden (design QA), parity fixtures
  android/  ios/           # generated platform projects + permission strings, signing placeholders
```

---

## 7. How the web design is reproduced natively

The web's `@theme` block is the **single source of truth**. A script (`scripts/export-design-tokens.mjs`) parses it and writes `design/tokens.json` and `mobile/lib/app/theme/tokens.g.dart`; a test fails when either file is stale, so web and mobile cannot drift apart.

| Token | Value (from `resources/css/app.css`) | Flutter |
| --- | --- | --- |
| near-black background | `--color-bg0 #07090a` | `IpColors.bg0` → scaffold |
| charcoal secondary | `--color-bg1 #0d1210` | gradient end / sheets |
| graphite cards | `--color-surface #111815`, `--color-surface2 #16201b` | `GlassCard` gradient (`rgba(24,36,29,.78)→rgba(13,20,16,.78)`) |
| neon green | `--color-neon #39ff14`, `--color-neon2 #00e676`, `--color-neon-dim #1fa80d` | primary / gradients / glow |
| text | `--color-ink #e8f5e9` (the web's "white"), `--color-muted #8fa39a` | `onSurface`, secondary |
| error / incorrect posture | `--color-danger #ff3b3b` (+ `#ff8a8a` text on tinted fills) | error |
| lines | `rgba(57,255,20,.15)` / `.4` | borders |
| shadows | `--shadow-neon`, `--shadow-neon-sm` | `BoxShadow` lists |
| fonts | Orbitron (display), Rajdhani (numbers), Inter (body) | bundled `.ttf` (converted from the web's own `@fontsource` files with `fontTools`) so nothing loads from the network |
| radii | cards 1.1 rem, buttons 0.8 rem (lg 1 rem), inputs 0.8 rem, chips pill | same, in logical px |
| buttons | min height 44 px (lg 56), uppercase Orbitron 700, letter-spacing .08 em; primary = neon fill + `#041006` text; ghost; danger | `NeonButton` variants |
| success green | neon green (the web has no separate success colour) | reuse neon |
| **amber warning** | **the web defines none.** The brief requires an amber state (uncertain tracking). One token is added to the web `@theme` (`--color-warn: #ffb020`) so both platforms share it; nothing else in the palette changes. | `IpColors.warn` |

Design QA: golden screenshot tests of the shared widgets in Flutter; Playwright screenshots of the web equivalents; both stored for side-by-side review in `docs/mobile/design-qa/` (colour values are additionally asserted numerically against the token file).

---

## 8. Phases and acceptance criteria

| Phase | Scope | Acceptance (all must be demonstrated by a run, not asserted) |
| --- | --- | --- |
| **1 Audit + architecture** | This document, design-token export + drift test, API contract skeleton | Doc reviewed; `npm run test:js` includes token drift test and passes. |
| **2 Backend API** | Sanctum auth, devices, memberships + entitlements (+ admin UI), content status + change feed, program phases, exercise media, announcements/banners/help/settings, app policy + maintenance, idempotency + sync batch, notification prefs, resources, errors, OpenAPI | Pest suite green on SQLite **and MySQL (CI)**; tests prove: token expiry/revocation/rotation, suspended user blocked, cross-user isolation on every resource, entitlement 403 for every gated route (route-list test enumerates gated routes), draft content invisible, tombstones in change feed, duplicate op idempotent, 426/503 behaviour; web suite unchanged and green; OpenAPI ↔ routes parity test. |
| **3 Flutter foundation** | Project, theme, tokens, shared widgets, API client, secure token store, auth flow, router guards, app-config gate | `flutter analyze` clean; unit tests (client retry rules, refresh single-flight, error mapping, token store), widget tests (login validation, guard redirects, update-required screen), golden tests for core widgets; CI builds a debug APK. |
| **4 Core fitness** | Dashboard, membership/paywall screen, program browser (sprint/phase/week/day when present), exercise library, workout session engine | Timer maths proven from persisted timestamps (tests simulate backgrounding/clock jumps); unfinished session resumes; entitlement-locked items show locked state and the API actually refuses; widget tests for each screen state (loading / data / empty / error / offline). |
| **5 Advanced** | Demo clip pipeline + player, Dart posture engine + camera screen, nutrition, calculator, progress, achievements | Dart rule engine passes the **same JSON fixtures** as JS; 150 g × 20 g/100 g = 30 g asserted on server and client display; chart/golden tests; camera + pose path flagged **device-unverified** with a test script. |
| **6 Sync + notifications** | Cache, pending-op queue, update/maintenance UX, local reminders, optional FCM | Queue tests: offline writes persist across restart, retried with same key, duplicates not double-applied, conflicts surfaced; local notifications scheduled from backend prefs; FCM labelled "requires project configuration". |
| **7 Testing + optimisation** | Security pass, full regression, manual device plan, design QA | All suites green; manual plan written; no claim beyond what ran. |
| **8 Release prep** | CI APK/AAB/iOS(no-codesign), signing + TestFlight + Play docs, privacy disclosures, store listings checklist | CI artifacts produced (or the failure recorded honestly); docs complete. |

Every phase appends a checklist (tasks, files, DB, API, dependencies, acceptance, **test results**, outstanding issues) to [`PROGRESS.md`](PROGRESS.md).

---

## 9. Infrastructure required (and what is not available here)

| Area | Needed | Status |
| --- | --- | --- |
| Hosting | HTTPS, PHP ≥ 8.2, MySQL/MariaDB, scheduler (cron `schedule:run`), **queue worker** (push + digests), writable storage / object storage + CDN for media | Unknown; documented in README additions. |
| Camera / pose | Physical device; ML Kit model is bundled in the app (no download at runtime); Android minSdk 21+, iOS 15.5+ (ML Kit requirement); camera permission strings | **Cannot be run here.** Parity-tested rule logic only; device test plan provided. |
| Notifications | Local: none. Remote: Firebase project, `google-services.json` / `GoogleService-Info.plist` (kept out of git), **APNs auth key (.p8) uploaded to Firebase**, Apple push capability, FCM service-account on the server | Not configured; feature stays off and is reported as unavailable. |
| Payments | Apple Developer Program + App Store Connect subscriptions + App Store Server API key; Google Play Console + Play Billing products + service account + Pub/Sub for RTDN | Not available; verifier adapters ship as interfaces/fakes, **unverified against real stores**. |
| Android builds | Android SDK (CI has it), upload keystore, Play App Signing | Local: impossible (SDK host blocked). CI: planned. |
| iOS builds | macOS + Xcode, Apple signing certificate + provisioning profile, bundle id | CI `macos-latest` unsigned build; real signing needs the owner's Apple account. |
| Media | ffmpeg + headless Chromium to render demo clips (pipeline script) | Chromium available; ffmpeg availability checked in Phase 5. |

---

## 10. Known risks and deliberate non-goals (to be kept current)

* No payment is processed, and no store purchase verified, until real store credentials exist.
* Interactive/rotatable 3D in the app is not delivered (rendered clips instead; see §6).
* Sprint/phase navigation is data-driven: the seeded PDF plan has none, so the app shows week/day for it and phases only for programs an admin defines. No phases are invented.
* Web weekly PDF / public share links are not mirrored in the app in this effort.
* Posture thresholds remain unvalidated on real lifters (same as the web); feedback is guidance, not medical advice.
