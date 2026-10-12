# IronPulse

A Laravel gym web app. It turns the **Mujtaba Ahssan 5-day Push / Pull / Legs plan** into a guided, game-like
training experience, checks lifting form with an on-device AI posture checker, and covers food, recovery and
progress. Dark and neon green, mobile-first, installable as an app.

- Laravel **12**, PHP 8.2+, Blade, Alpine.js, Tailwind CSS 4, Vite
- Three.js (3D exercise demos), MediaPipe Pose Landmarker (self-hosted), Chart.js
- SQLite for development, MySQL / PostgreSQL compatible
- **No third-party requests at runtime.** Fonts, scripts, the pose model and its WebAssembly runtime are all served
  from your own origin (the Content-Security-Policy enforces `connect-src 'self'`).

> **Laravel version.** The brief said Laravel 11. Laravel 11 has known security advisories, so the project is on the
> supported Laravel 12 line. Nothing in the code depends on the difference.

## Contents

1. [What is in it](#what-is-in-it)
2. [Quick start](#quick-start)
3. [Configuration](#configuration)
4. [The plan data](#the-plan-data)
5. [Posture Lab](#posture-lab)
6. [Nutrition](#nutrition)
7. [Progress, recovery and gamification](#progress-recovery-and-gamification)
8. [Reports, email and the scheduler](#reports-email-and-the-scheduler)
9. [Installable app, offline use and reminders](#installable-app-offline-use-and-reminders)
10. [AI coach (optional)](#ai-coach-optional)
11. [Mobile apps and the `/api/v1` API](#mobile-apps-and-the-apiv1-api)
12. [Admin panel](#admin-panel)
13. [Privacy and security](#privacy-and-security)
14. [Tests and CI](#tests-and-ci)
15. [Deploying](#deploying)
16. [Project layout](#project-layout)
17. [Known limitations](#known-limitations)

## What is in it

| Area | What you get |
|---|---|
| Onboarding | Short wizard (body, goal, level, equipment, diet, training days). The seeded Mujtaba account opens pre-filled from the PDF. |
| Plan | The PDF plan, day by day: sets, reps, focus, rest rules, creatine and sleep rules. Swap an exercise for an alternative (once, or for good). Mark a day done or skipped. |
| Workout (game mode) | Guided session: set and rep logging, rest timer, XP, streaks, personal records, summary and share card. Works through a flaky connection: sets are queued and sent when you are back online. |
| Exercise library | 85 exercises (plan, alternatives, helpers, mobility) with form cues, common mistakes, breathing and a 3D demo that highlights the muscles worked. |
| Posture Lab | Camera-based form checking for 35 exercises: live skeleton overlay with red bones where form breaks, rep counting, voice and sound cues, a score per set. Runs entirely in the browser. |
| Diet | Mifflin-St Jeor + activity targets for cutting, bulking, maintenance or recomposition; macro split, fibre, water, optional calorie cycling; a one-day meal plan. |
| Food log | 176 foods plus your own, serving sizes, quick add, meal templates, "what fits my remaining protein", copy a previous day. |
| Progress | Weight and measurement log, BMI, body-fat estimate, strength curves, personal records, private progress photos. |
| Recovery | Water, creatine and sleep tracking with streaks, generated warm-up / cool-down routines for the day's muscles. |
| Gamification | XP and levels, 32 badges, a leaderboard you opt in to. |
| Weekly report | One page per week, PDF export, a share link (training only, expires), optional Sunday email digest. |
| App | Installable (PWA), works offline for the plan, library and a running workout, browser reminders. |
| Admin | Edit the plan, exercises, posture rules and foods; manage accounts. |
| AI coach | Optional, off by default, server-side only. |

## Quick start

Requirements: PHP 8.2+ with `gd`, `exif`, `mbstring`, `pdo_sqlite`, `dom`, `zip`; Composer; Node 20+ (22 recommended).

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed

npm install
npm run vendor:mediapipe     # copies the pose runtime and downloads the pose model (see below)
npm run build

php artisan serve            # http://127.0.0.1:8000
```

`composer setup` does the first block for you. For development with hot reload run `npm run dev` next to
`php artisan serve` (the strict Content-Security-Policy is only sent for built assets, because Vite's dev server
needs its own origin).

### Seeded accounts

The password comes from `SEED_PASSWORD` (default `password`). **Change it before you seed anything that is
reachable from the internet.**

| Account | Notes |
|---|---|
| `mujtaba@example.com` | The profile from the plan PDF. The wizard asks for what the PDF does not contain: sex, activity level, fitness level, diet preference and equipment. |
| `admin@example.com` | Fully onboarded, can open the admin panel. |

Visitors can also press **Try as guest**. A guest is a real account with no email; it can be claimed later with an
email and password without losing data. Switch this off with `IRONPULSE_GUEST_MODE=false`.

### Self-hosted pose model

The Posture Lab never talks to a CDN. `npm run vendor:mediapipe`:

- copies the WebAssembly runtime from the `@mediapipe/tasks-vision` npm package into `public/vendor/mediapipe/wasm/`
- downloads `pose_landmarker_lite.task` once into `public/vendor/mediapipe/models/` and verifies its SHA-256 against
  `scripts/mediapipe-assets.lock.json`

The files are git-ignored (the model is about 6 MB). If your network blocks the download, fetch the file yourself,
put it at that path and run the script with `--trust-existing`. Until the assets exist the Posture Lab page explains
what is missing instead of failing silently, and the admin overview shows the same check.

## Configuration

Everything is in `.env`. The important keys beyond Laravel's own:

| Key | Default | Meaning |
|---|---|---|
| `SEED_PASSWORD` | `password` | Password for the seeded accounts. |
| `IRONPULSE_GUEST_MODE` | `true` | Let visitors try the app without registering. |
| `IRONPULSE_GUEST_TTL_DAYS` | `30` | `ironpulse:prune-guests` deletes guests unused for this long. |
| `MAIL_*` | `MAIL_MAILER=log` | The weekly digest and password resets. With `log` nothing is sent, it is written to `storage/logs`. |
| `COACH_ENABLED` | `false` | Turns the AI coach on (it also needs the next two). |
| `ANTHROPIC_API_KEY` | empty | Server-side key for the coach. Never sent to the browser. |
| `COACH_MODEL` | empty | Model name to use. There is deliberately no default. |
| `COACH_DAILY_MESSAGE_LIMIT` | `20` | Messages per user per day (the user's own midnight). |
| `COACH_EFFORT` | empty | Optional reasoning effort (`low`, `medium`, `high`) for models that take it. |
| `COACH_MAX_OUTPUT_TOKENS` | `1500` | Cap on a reply, including any thinking. |

Other tunables (XP values, level curve, posture XP caps, water formula, guest lifetime) live in `config/ironpulse.php`.

## The plan data

The plan comes from `database/seeders/data/plan.json`, copied from the PDF and covered by tests
(`tests/Feature/PlanSeedTest.php`): Monday push (chest), Tuesday pull (back), Wednesday legs, Thursday push (shoulders),
Friday pull (biceps), Saturday and Sunday rest, with the sets, reps and focus text per exercise and the PDF's rules
(progressive overload, 2-3 minute rests for compounds, 60-90 seconds for isolation, 5 g creatine, 7-8 hours of sleep).

An exercise that appears on more than one day (for example Face Pull) is **one** row in `exercises`, linked from each
day, so progress, records and posture rules follow the exercise, not the day. Exercises outside the PDF (alternatives,
helpers, mobility) are tagged by `kind` and never appear in the plan unless an admin adds them.

## Posture Lab

How it works, end to end:

1. `getUserMedia` opens the camera on your device. Frames never leave the browser.
2. MediaPipe Pose Landmarker (lite, GPU with a CPU fallback) returns 33 landmarks per frame.
3. A One Euro filter smooths them, and a rule engine evaluates the **rule JSON** for the exercise: joint angles, trunk
   inclination, offsets, ratios, left/right differences, with hysteresis so a rep is not counted twice.
4. A rep state machine counts reps (or holds for timed moves) and judges each rep against the rules, with a short settle
   window so lockout is judged at lockout.
5. The overlay draws the skeleton, turning the bones involved in a mistake red, and cues are spoken or shown.
6. A set summary is saved when you finish: reps, good reps, score, the mistakes seen.

Rules are data. Each is a versioned JSON document (schema 1) in `database/seeders/data/posture-rules/` and
editable in the admin panel. The PHP validator (`PostureRuleValidator`) and the browser's `validateRule()` give
identical messages; shared fixtures keep them from drifting apart. A rule that saves in the admin panel is guaranteed
to compile in the browser.

Honest notes:

- Thresholds are biomechanical approximations, set from how each lift is normally coached, and tested against
  **synthetic landmark sequences** and a fake camera feed. They have **not** been validated against real lifters or
  with a real camera. Treat the Lab as a coaching aid, not a judge, and tune a rule in the admin panel after you have
  tried it on yourself. Each exercise page lists its camera set-up and what it can and cannot see.
- Scores are computed on the user's device, so XP from the Lab is capped per session and per day rather than trusted.
- A side view is needed for most lifts; one camera cannot see everything.

## Nutrition

`DietCalculatorService` is a pure class with unit tests (`tests/Unit/DietCalculatorServiceTest.php`):

- BMR: Mifflin-St Jeor; TDEE from the activity multiplier.
- Cutting: 20% below TDEE, never below BMR, 1200 kcal (female) or 1500 kcal (male). Bulking: +200 to +500 kcal.
  Recomposition: 5% below. Maintenance: TDEE.
- Protein by goal and body weight (capped at 40% of calories), fat 20-30% of calories, carbs the remainder (at least
  100 g), fibre 14 g per 1000 kcal, water 35 ml/kg plus 500 ml on training days.
- Optional calorie cycling that stays weekly-neutral: rest days are lower, training days higher.

The profile is the single source of truth for goal and diet preference, so the diet page, the food log and the
weekly report always agree. Food values are **typical estimates** per 100 g, not lab data; admins can correct them and
mark a food as verified.

## Progress, recovery and gamification

- **Progress**: one weigh-in or tape entry per day (re-saving a day merges), BMI, a US Navy body-fat estimate from neck,
  waist (and hips), strength curves from logged sets, personal records (heaviest, most reps, estimated 1RM, best set).
  Both BMI and the tape estimate are population formulas and the page says so.
- **Photos** are re-encoded to JPEG (this strips EXIF and GPS), stored on the private disk and only ever streamed to
  their owner. They are deleted with the account.
- **XP** is awarded idempotently (a retried request cannot pay twice). **Badges** are rows of data with a `metric` and a
  threshold, evaluated after the events that can change them.
- **Water, creatine and sleep** feed streaks and the weekly report. Creatine streaks count back from yesterday until you
  tick today, so you are never punished for opening the app in the morning.

## Reports, email and the scheduler

- `/reports` shows a Monday-to-Sunday report for any of the last weeks. A finished week is stored as a snapshot; the
  running week is always rebuilt. **PDF** export uses dompdf with remote fetching switched off.
- **Share links** are random 32-character tokens with an expiry (7, 14 or 30 days) and can be revoked. A shared page
  shows training, records, posture and XP only: weight, food, water, creatine and sleep are removed from the data, not
  just hidden, and the page is `noindex` with no referrer.
- The **email digest** is opt-in. It is sent at 18:00 in each user's own timezone on their digest day, once per week,
  never to guests, with a signed one-click unsubscribe link (and the `List-Unsubscribe` header).

Add one cron entry for the scheduler:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

It runs `ironpulse:send-digests` hourly and `ironpulse:prune-guests` daily. Both can be run by hand; `--dry-run` shows
what would happen.

## Installable app, offline use and reminders

The app has a manifest, icons (including a maskable one) and a service worker.

- **Caches**: built assets, fonts and the pose model (cache-first, they are versioned), and a copy of the dashboard, plan,
  library and workout pages so a workout can be resumed with no signal. Sets logged offline are queued by the page and
  sent when the connection returns.
- **Never cached**: the API, login and logout, progress photos, the admin panel, shared report links and any non-GET
  request. Cached pages are dropped on logout and whenever a signed-out page loads.
- **Reminders** (workout, creatine, bedtime, water) are browser notifications raised by a timer **while IronPulse is open**
  in a tab or as an installed app. There is no push server, so **nothing is delivered when the app is closed**. This is
  stated next to the setting. Times are interpreted in the profile's timezone.
- Install prompts appear where the browser offers one; on iOS the page explains Share, then Add to Home Screen.

## AI coach (optional)

Off by default; the app is complete without it. To enable it:

```env
COACH_ENABLED=true
ANTHROPIC_API_KEY=your-key
COACH_MODEL=the-model-you-want-to-use
```

Then each user has to opt in on the coach page (or in Profile). What happens:

- The question goes to the Anthropic API **from your server** using the official PHP SDK. The key and the model name are
  never exposed to the browser.
- Sent with each question: the message, the last few turns of that user's own conversation, and a short summary of
  goal, level, age, sex, height, weight, equipment, diet type, today's plan, weekly progress, recent records, nutrition
  targets and average posture score. **Never sent:** name, email, photos, food log, measurements, timezone.
- A safety system prompt keeps it to training, recovery and nutrition, refuses to diagnose or treat injuries, and
  treats whatever the user types as a question, not as instructions.
- Limits: `COACH_DAILY_MESSAGE_LIMIT` per user per day, 10 requests a minute, 1000 characters a message. A failed
  attempt does not use up a message. Provider errors are logged without the conversation and shown to the user in plain
  words.
- Conversations are saved so the user can scroll back; switching the coach off deletes them.
- You pay for usage on your own Anthropic account. Replies come from an AI and can be wrong; the page says so.

## Mobile apps and the `/api/v1` API

The same Laravel app and database also serve native Android and iOS apps (Flutter, in `mobile/`; being built in
phases, see [docs/mobile/PROGRESS.md](docs/mobile/PROGRESS.md)). The website is unchanged: it keeps session auth.
The apps use bearer tokens (Laravel Sanctum) against a versioned JSON API.

- **Contract:** [docs/mobile/openapi.yaml](docs/mobile/openapi.yaml) (OpenAPI 3.1, generated by
  `python3 docs/mobile/openapi-src/generate.py`; PHP tests fail if a route and the spec disagree or if a live response
  breaks its schema).
- **Design and decisions:** [docs/mobile/01-implementation-audit.md](docs/mobile/01-implementation-audit.md).
- **Payments:** [docs/mobile/payments.md](docs/mobile/payments.md). Membership plans and what each unlocks are edited in
  Admin → Memberships and enforced on the server for every protected endpoint; no store adapter ships.
- **Content:** programs, exercises, nutrition, announcements, banners, help and app settings are managed in the admin
  panel with a draft/published workflow and reach the apps through an incremental sync feed, with no app release needed.
  Admin → App releases sets the minimum/recommended app version and a maintenance switch.

| Key | Default | Meaning |
|---|---|---|
| `IRONPULSE_TOKEN_TTL_DAYS` | `60` | Lifetime of a mobile access token. |
| `IRONPULSE_TOKEN_REFRESH_WINDOW_DAYS` | `30` | The app is told to rotate its token when fewer days remain. |
| `IRONPULSE_MAX_DEVICES` | `10` | Signed-in devices per account; the oldest is signed out beyond this. |
| `MEDIA_DISK_DRIVER` | `local` | `s3` keeps exercise videos/images in object storage. Files are private and served through signed, expiring URLs. |
| `FCM_PROJECT_ID`, `FCM_CREDENTIALS_PATH` | empty | Firebase push. Without both, push is simply off and the apps use on-device reminders. |

Operations: run the scheduler (`php artisan schedule:run` every minute) and a queue worker if `QUEUE_CONNECTION` is not
`sync`. They expire memberships, send membership/announcement pushes and prune idempotency keys, old sync records and
dead device tokens. Serve the API over HTTPS only.

What is **not** done or **not verified** (kept honest on purpose):

- No Android SDK, Xcode or phone was available while this was written. The PHP API is tested (see below); Flutter code is
  checked with `flutter analyze` and `flutter test` only where the progress log says so. APK/AAB files are produced by the
  GitHub Actions workflow, and an iOS build needs macOS with Xcode. Nothing in this repository claims a store release.
- Apple and Google purchase verification is an interface with a test double; real verification needs store credentials.
- FCM delivery is tested against a faked HTTP transport, not against Firebase.
- Posture thresholds have not been validated on real lifters, and the camera coach is a training aid, not medical advice.

## Admin panel

`/admin`, for accounts with the admin role (create one by changing a user's role in the panel, or in a seeder).

- **Overview**: counts and system checks (pose model installed, mail driver, coach configured, debug mode).
- **Users**: search, change roles (not your own, not a guest, never the last admin), delete with a typed confirmation.
  Admins see counts only, never anyone's food, photos, measurements or coach messages.
- **Plan**: edit a day's details, add, edit, reorder and remove exercises. Rows keep their ids, so history follows.
- **Exercises**: create and edit everything the library shows. Exercises used by the plan or by anyone's history are hidden
  rather than deleted.
- **Posture rules**: JSON editor with live validation, **versioned** saves (an edit writes version N+1 and old sessions keep
  the version they were scored with), restore any earlier version as a new one. Re-seeding never overwrites an exercise
  an admin has edited.
- **Foods**: the shared database with serving sizes. People's private custom foods are not listed.
- **3D demos**: every exercise at four points of its loop, from a 45 degree, side or front camera, for reviewing the animations. A JS test (`tests/js/demo-clearance.test.mjs`) measures overlap between the figure and its equipment for every demo, so a changed pose or prop that makes the figure sink into a bench, pad or bar fails the build.

Every admin change is written to the application log as `admin.*` with the admin's id.

## Privacy and security

- Strict CSP for built assets (`default-src 'self'`; scripts need `unsafe-eval` for Alpine and `wasm-unsafe-eval` for
  MediaPipe), `X-Frame-Options: DENY`, `nosniff`, a referrer policy, HSTS over HTTPS, camera allowed for the app's own
  origin only.
- CSRF everywhere, form requests for validation, policies on photos and food logs, rate limits on login, guest creation,
  uploads, the API and the coach. Role and XP columns are not mass-assignable.
- Passwords are hashed; guests have no password at all. Account deletion removes the data and the files.
- Camera frames are processed in the browser and never uploaded.
- Numbers that are estimates are labelled as such (BMI, body fat, food values).

## Tests and CI

```bash
php artisan test        # PHP: unit, feature and service tests (in-memory SQLite, no build needed)
npm run test:js         # JS: rule engine, rep counter, filter, geometry, service worker, reminders
vendor/bin/pint --test  # code style
```

The PHP suite covers the diet maths, the plan seed, auth, onboarding, workouts, posture rules and sessions, the food
database and logger, XP and badges, habits, progress and photo privacy, reports and share links, the digest and pruning,
the PWA files, the whole admin panel and the AI coach (a fake model, plus the real adapter over a stubbed HTTP
transport). The JS suite drives the posture engine with synthetic landmark sequences, and the service worker with a
simulated worker environment.

`.github/workflows/ci.yml` runs Pint, a fresh migrate-and-seed and the PHP tests on PHP 8.2, 8.3 and 8.4 (SQLite), the
PHP tests again on MySQL 8, a check that `docs/mobile/openapi.yaml` is regenerated and valid, and the JS tests and a
production build on Node 22.

Beyond the automated tests, each page was driven in headless Chromium (login, forms, charts, offline behaviour with the
server stopped, the coach against a stub of the AI service), checked at 390 px for horizontal overflow, and scanned with
axe-core for WCAG 2.2 A and AA violations in their initial state.

## Deploying

1. Point the web root at `public/`. Use HTTPS (the service worker, camera and installability need it).
2. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_KEY`, `APP_URL`, a real database, `MAIL_*`, a non-default
   `SEED_PASSWORD` if you seed.
3. `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build && npm run vendor:mediapipe`.
4. `php artisan migrate --force`, then `php artisan config:cache route:cache view:cache event:cache`.
5. Run a queue worker if you set `QUEUE_CONNECTION` to anything but `sync`, and add the scheduler cron entry above.
6. Back up the database **and** `storage/app/private` (progress photos).
7. Behind a proxy or load balancer, configure trusted proxies so HTTPS and client IPs are detected correctly.

## Project layout

```
app/Services/            XP, badges, streaks, plan, diet, food, reports, habits, progress, coach ...
app/Services/Coach/      CoachModel interface, Anthropic adapter, context and prompt
app/Http/Controllers/    web pages; Api/ = the small same-origin JSON API (session + CSRF)
app/Http/Controllers/Admin/
database/seeders/data/   plan, exercises, posture rules, foods, meal templates, badges (JSON)
resources/js/modules/    browser logic: workout engine, posture engine, rule engine, charts, 3D demos
resources/js/pages/      one entry per page (`<body data-page="...">`), code-split
public/sw.js             the service worker
tests/                   Pest tests (PHP) and node:test tests (tests/js)
docs/PHASE-0-PLAN.md     the original plan, schema and phase breakdown
```

Browser code follows one pattern: a page declares `data-page`, `resources/js/pages/<name>.js` registers its Alpine
components before Alpine starts, heavy libraries are imported lazily, and components read their settings from a JSON
`<script>` block in the page.

## Known limitations

- **Posture accuracy is unproven on real people.** See [Posture Lab](#posture-lab). The 3D demos are stylised figures, not
  motion capture.
- **Web reminders only fire while the page is open.** Push exists only for the mobile apps and only once Firebase is configured (see above).
- **Food values are typical estimates**, BMI and the Navy body-fat figure are rough formulas.
- **Scores are computed on the user's device** and are therefore not tamper-proof; that is why XP from them is capped.
- The accessibility scan covers each page's initial state. Open dialogs, the live Posture Lab HUD and the 3D canvas were
  not scanned, and nothing was tested with a screen reader.
- Offline support covers reading the plan and library and logging a running workout. Everything else needs a connection.
- The AI coach has been tested against a stub of the AI service, not against a live key.
