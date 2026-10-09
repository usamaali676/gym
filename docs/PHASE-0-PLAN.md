# IronPulse: Phase 0 (confirmation, tree, schema, phase plan)

Nothing in this document is application code yet. It is the contract for Phases 1-7.
The machine-readable seed lives in `database/seeders/data/plan.json` and is the only place the PDF plan is written down.

---

## 1. Decisions I need from you (defaults apply if you just say "continue")

| # | Question | Default if you say nothing |
|---|---|---|
| 1 | **Laravel version.** Your spec fixes Laravel 11. Packagist currently serves 11.x, 12.x and 13.x (13.x, first released 2026-03-17, needs PHP 8.3+; 11 and 12 need PHP 8.2+). 11.x is still getting releases (11.57.0 on 2026-09-29), but it is the oldest of the three. I could not open laravel.com from this sandbox to re-check the official support windows, so please check them: a new app with logins and user data is better off on a line that is clearly supported. The slim Laravel 11 skeleton is the same in 12 and 13, so code written for 11 carries over. | **Laravel 11**, exactly as specified. Tell me "use 12" or "use 13" and I switch before Phase 1 starts. |
| 2 | **Sex is not in the PDF** and your profile line omits it. Mifflin-St Jeor needs it. I will not guess it from a name. | Seed `sex = null`; the diet engine asks once. Tell me the value if you want it seeded. |
| 3 | **Lat Pulldown rest.** Your rule lists the compounds and says "the rest are isolation", which makes Lat Pulldown (and Shrugs, Face Pull, etc.) isolation = 60-90 s. Lat Pulldown is a compound lift in most programs. | Follow your rule literally (isolation, 75 s default). Editable per exercise in admin. |
| 4 | **Cable Overhead Tricep Extension** is in neither of your posture lists (full rules vs machine/cable). | Put it in the **simplified** (cable) tier. |
| 5 | **Delivery format.** You asked for every file pasted in full into chat. I am working inside your git repo, so I write each file to disk, commit and push to `claude/exciting-mayer-i78ysz`, and list the files, commands and a test checklist at the end of each phase. | Files on disk, not pasted. Tell me if you also want full bodies pasted into chat. |
| 6 | **Alternative exercises for swaps.** The plan stays exactly as the PDF. For "swap for an alternative (same muscle group)" I need a few extra library exercises (e.g. push-up, dumbbell bench press) flagged `kind = alternative`; they never enter the default plan. | ~20 alternatives + 4 warm-up helpers + ~12 mobility moves, all outside the plan. |

---

## 2. Plan seed confirmation

Compare against the PDF. All names are the PDF's exact strings. Shared rows are one `exercises` row linked from several days.

| Day | Type | # | Exercise | Sets | Reps | Focus | Rest class |
|---|---|---|---|---|---|---|---|
| Mon | PUSH, Chest | 1 | Flat Barbell Bench Press | 3 | 6-10 | Overall chest mass | compound |
| | | 2 | Incline Dumbbell Press | 3 | 8-12 | Upper chest | compound |
| | | 3 | Cable Lower Chest Fly | 3 | 12-15 | Lower chest definition | isolation |
| | | 4 | Seated DB Overhead Press **(shared Thu)** | 3 | 8-12 | Front + side delts | compound |
| | | 5 | Side Lateral Raise **(shared Thu)** | 3 | 12-15 | Shoulder width | isolation |
| | | 6 | Skull Crushers | 3 | 8-12 | Tricep mass | isolation |
| | | 7 | Rope Tricep Pushdown | 3 | 12-15 | Tricep isolation | isolation |
| Tue | PULL, Back | 1 | Barbell Row | 3 | 6-10 | Back thickness | compound |
| | | 2 | Lat Pulldown **(shared Fri)** | 3 | 8-12 | Lat width | isolation (see Q3) |
| | | 3 | Seated Cable Row **(shared Fri)** | 3 | 8-12 | Mid-back | compound |
| | | 4 | Straight Arm Pulldown | 3 | 12-15 | Lat isolation | isolation |
| | | 5 | Face Pull | 3 | 15-20 | Rear delts + rotator cuff | isolation |
| | | 6 | Barbell Curl | 3 | 8-12 | Bicep mass | isolation |
| | | 7 | Dumbbell Hammer Curl | 3 | 10-12 | Brachialis + forearms | isolation |
| Wed | LEGS | 1 | Barbell Squat | 3 | 6-10 | Overall leg mass | compound |
| | | 2 | Leg Press | 3 | 10-12 | Quad volume | compound |
| | | 3 | Romanian Deadlift | 3 | 8-10 | Hamstrings + glutes | compound |
| | | 4 | Hamstring Curl | 3 | 10-12 | Hamstring isolation | isolation |
| | | 5 | Leg Extension | 3 | 12-15 | Quad isolation | isolation |
| | | 6 | Standing Calf Raise | 3 | 15-20 | Calf size | isolation |
| Thu | PUSH, Shoulders | 1 | Seated DB Overhead Press **(shared Mon)** | 3 | 8-12 | Shoulder mass | compound |
| | | 2 | Arnold Press | 3 | 10-12 | Full delt activation | compound |
| | | 3 | Side Lateral Raise **(shared Mon)** | 3 | 12-15 | Shoulder width | isolation |
| | | 4 | Reverse Pec Deck (Rear Delts) | 3 | 12-15 | Rear delt | isolation |
| | | 5 | Incline Bench Press | 3 | 8-12 | Upper chest | compound |
| | | 6 | Butterfly Machine | 3 | 12-15 | Chest squeeze | isolation |
| | | 7 | Cable Overhead Tricep Extension | 3 | 10-12 | Tricep long head | isolation |
| | | 8 | Reverse Grip Tricep Pushdown | 3 | 12-15 | Tricep outer head | isolation |
| Fri | PULL, Biceps | 1 | Pull-Ups / Assisted Pull-Ups | 3 | 6-10 | Lat width + strength | compound |
| | | 2 | Single Arm Dumbbell Row | 3 | 8-12 | Back thickness | compound |
| | | 3 | Lat Pulldown **(shared Tue; printed "Lat Pulldown (Wide Grip)")** | 3 | 8-12 | Lat spread | isolation (see Q3) |
| | | 4 | Seated Cable Row **(shared Tue)** | 3 | 10-12 | Mid-back | compound |
| | | 5 | Shrugs (DB or Barbell) | 3 | 12-15 | Traps | isolation |
| | | 6 | Spider Curl | 3 | 10-12 | Bicep peak | isolation |
| | | 7 | Preacher Curl | 3 | 8-10 | Lower bicep | isolation |
| Sat, Sun | REST | | recovery days | | | | |

**Verified by script** (`validate-plan.mjs`, run against `plan.json`): 7 day rows (5 training + 2 rest), **35** plan rows (7, 7, 6, 8, 7), **31** unique exercises, 4 shared exercises, every reference resolves, no inverted rep ranges, positions contiguous, compound set equals your list exactly (12 compound, 19 isolation).

**How shared exercises keep their per-day differences.** Weekday-specific data lives on the `plan_day_exercises` link row, not on `exercises`:
- reps (Seated Cable Row is 8-12 Tue, 10-12 Fri) and focus text (Seated DB Overhead Press: "Front + side delts" Mon, "Shoulder mass" Thu);
- `label_override`, used once: Friday prints "Lat Pulldown (Wide Grip)", the exercise row is plain "Lat Pulldown" so history and PRs are shared across both days.

**Derived, not stored per row:** rest seconds come from `mechanic` (compound 120-180 s, default 150; isolation 60-90 s, default 75) via `rest_defaults` in the JSON; `exercises` gets the resolved values at seed time and a day row can override them. Estimated session duration is computed (sets x (work time + rest)), never typed in.

**Plan rules** (progressive overload, creatine 5 g, sleep 7-8 h and the recovery quote) are in the JSON under `rules` and are copied into `plans.rules`. No Blade file contains plan text.

**Posture tier per exercise (also in the JSON):** 18 `full` (all of your "full rules" list, counting both incline presses) and 13 `simplified` (your machine/cable list plus Cable Overhead Tricep Extension, see Q4).

---

## 3. Assumptions

1. **Auth and guests.** Breeze (Blade). A guest is a real `users` row with `is_guest = true`, null email and password; "claiming" the account fills in email and password on the same row, so no data migrates.
2. **Weekday logic.** "Today's workout" uses the user's `profiles.timezone` and ISO weekday. The streak counts consecutive *scheduled training days* completed; Sat/Sun never break it.
3. **Week number** for progressive overload is counted from `profiles.plan_started_on`.
4. **Progressive overload rule.** Compare the last session of that exercise (shared across days) with the day's rep range. If every set reached `reps_max`: suggest +2.5 kg (upper body, isolation) or +5 kg (lower body, compound) and reset to `reps_min`. Otherwise suggest +1-2 reps at the same weight. Rules are config in `config/ironpulse.php` and unit-tested.
5. **Age** is stored as typed (spec says "age 27"); it does not auto-increment.
6. **Sex values** are `male`, `female`, `unspecified`. `unspecified` uses the midpoint of the two Mifflin-St Jeor constants. The seeded user is `null` until answered (Q2).
7. **Diet maths.** TDEE multipliers 1.2 / 1.375 / 1.55 / 1.725 / 1.9 for sedentary / light / moderate / active / very active. Calorie cycling is weekly-energy-neutral: rest days are set from a lower multiplier and training days absorb the difference, so the weekly average equals the goal target. Body recomposition = weekly average at about maintenance (0 to -5%), higher on training days. Protein bands (g/kg): cutting 2.2-2.4, recomposition 2.0-2.2, bulking 1.8-2.0, maintenance 1.8; fat 20-30% of kcal; fibre 14 g per 1000 kcal; carbs = remainder; water about 35 ml/kg plus a training-day bonus. All of it lives in `DietCalculatorService` with unit tests and a `formula_version` stamped on every saved plan.
8. **MediaPipe model file.** `@mediapipe/tasks-vision` ships the WASM runtime but **not** the `.task` pose model. A one-time install script (`npm run vendor:mediapipe`) copies the WASM from `node_modules` and downloads `pose_landmarker_lite.task`, verifying a SHA-256, into `public/vendor/mediapipe/`. At runtime the browser only talks to your own origin. I confirmed this container can reach the model host, so I can run the script here.
9. **Fonts** via `@fontsource/orbitron` (headings) and `@fontsource/inter` (body), bundled by Vite.
10. **Tailwind.** Version is pinned in Phase 1 to whatever the Breeze stubs support. Design tokens are CSS variables, so the palette does not depend on the Tailwind major.
11. **AI coach.** Off by default (`COACH_ENABLED=false`). Server-side only: key in `.env`, model in `COACH_MODEL`, called from `CoachService`, with a per-user rate limit and a token cap. Nothing in the frontend ever sees the key.
12. **Seeded accounts (dev only).** `mujtaba@example.com` (the plan owner) and `admin@example.com`, password from `SEED_PASSWORD` in `.env` (default `password`, local only). Production seeding must set both.
13. **Progress photos** are written to a private disk and streamed through an authorised controller, never to `public/`.
14. **Web push** needs VAPID keys and a push service, which is outside "no third-party requests", so reminders use the in-page Notification API + service worker while the app is open, plus the scheduled email digest.
15. **Foods.** The 150+ food values are typical reference values (USDA-style, per 100 g, cooked/raw stated in the name). They are good for tracking, not lab-grade, and the UI says so.

### Honest limitations to show in the UI
- Pose accuracy depends on lighting, camera height and angle, loose clothing and how much of the body is in frame.
- Machines and cables hide limbs, so those exercises use the simplified rule set and are labelled lower accuracy.
- Rep counting from landmarks is verified in unit tests against synthetic landmark sequences. Real-world accuracy can only be judged with a real camera on a real lifter.

---

## 4. Folder / file tree

`[P#]` = the phase that creates the file. Laravel/Breeze boilerplate not listed (`artisan`, `composer.json`, default middleware, etc.).

```text
gym/
├── .env.example                                   [P1]  keys listed in section 6
├── README.md                                      [P7]
├── package.json  vite.config.js                   [P1]
├── docs/PHASE-0-PLAN.md                           [P0]  this file
├── scripts/
│   ├── vendor-mediapipe.mjs                       [P4]  copy WASM + download/verify pose model
│   └── make-icons.mjs                             [P6]  generate PWA icons from one SVG
├── config/
│   └── ironpulse.php                              [P1]  XP table, overload rules, rest defaults, coach toggle
├── app/
│   ├── Enums/                                     [P1]  Goal, Sex, ActivityLevel, FitnessLevel, DietPreference,
│   │                                                    SessionType, MuscleGroup, Mechanic, SessionStatus, Meal, ...
│   ├── Models/                                    [P1-6] User, Profile, NotificationSetting, Plan, PlanDay, Exercise,
│   │                                                    PlanDayExercise, PostureRule, UserExerciseSwap, WorkoutSession,
│   │                                                    SessionExercise, SessionSet, PostureSession, PersonalRecord,
│   │                                                    ProgressionTarget, DietPlan, Food, FoodServing, FoodLog,
│   │                                                    MealTemplate, WaterLog, Measurement, ProgressPhoto, DailyLog,
│   │                                                    Badge, UserBadge, XpEvent, CoachMessage, WeeklyReport
│   ├── Policies/                                  [P1-6] one per user-owned model + Exercise/Food/PostureRule (admin)
│   ├── Http/
│   │   ├── Middleware/                            [P1]  EnsureOnboarded, EnsureAdmin, SecurityHeaders
│   │   ├── Requests/                              [P1-6] Form Requests, one per write action
│   │   └── Controllers/
│   │       ├── DashboardController.php            [P1]
│   │       ├── OnboardingController.php           [P1]
│   │       ├── ProfileController.php              [P1]
│   │       ├── PlanController.php                 [P2]  week view, day detail, complete/skip, swap
│   │       ├── ExerciseLibraryController.php      [P2]
│   │       ├── WorkoutController.php              [P3]  game mode shell, summary
│   │       ├── PostureLabController.php           [P4]
│   │       ├── DietController.php                 [P5]
│   │       ├── FoodLogController.php              [P5]
│   │       ├── FoodController.php                 [P5]  custom foods
│   │       ├── ProgressController.php             [P6]  measurements, photos, charts, 1RM, BMI/body-fat
│   │       ├── WaterController.php                [P6]
│   │       ├── DailyLogController.php             [P6]  creatine, sleep
│   │       ├── RecoveryController.php             [P6]  stretching, mobility timers
│   │       ├── WarmupController.php               [P6]
│   │       ├── BadgeController.php  LeaderboardController.php   [P6]
│   │       ├── ReportController.php               [P6]  weekly report + PDF
│   │       ├── CoachController.php                [P6]
│   │       ├── Api/
│   │       │   ├── WorkoutSessionController.php   [P3]  autosave (idempotent via client_uuid), set log, finish
│   │       │   ├── PostureSessionController.php   [P4]
│   │       │   ├── FoodSearchController.php       [P5]  autocomplete
│   │       │   └── WaterLogController.php         [P6]
│   │       └── Admin/                             [P6]  PlanDay, Exercise, PostureRule (JSON editor + validator),
│   │                                                    Food, User, Badge
│   ├── Services/
│   │   ├── PlanService.php                        [P2]  today's day, week status, estimated duration
│   │   ├── WorkoutSessionService.php              [P3]  start/resume/finish, snapshot plan into session_exercises
│   │   ├── ProgressiveOverloadService.php         [P3]
│   │   ├── PersonalRecordService.php  OneRepMaxService.php   [P3/P6]
│   │   ├── XpService.php  StreakService.php  BadgeService.php   [P3/P6]
│   │   ├── PostureRuleValidator.php               [P4]  JSON-schema check for rule configs
│   │   ├── DietCalculatorService.php              [P5]  BMR, TDEE, cycling, macros, water
│   │   ├── MealPlanService.php  FoodSwapService.php   [P5]
│   │   ├── NutritionTotalsService.php             [P5]
│   │   ├── WarmupGeneratorService.php             [P6]
│   │   ├── WeeklyReportService.php                [P6]
│   │   └── CoachService.php                       [P6]  Claude API, server-side only
│   ├── Console/Commands/                          [P6]  ironpulse:send-digests
│   └── Mail/WeeklyDigest.php                      [P6]
├── routes/
│   ├── web.php  api.php  console.php  auth.php    [P1-6]  api.php: small JSON API, session-auth, throttled
├── database/
│   ├── migrations/                                [P1-6] one per table in section 5
│   ├── factories/                                 [P1-6]
│   └── seeders/
│       ├── DatabaseSeeder.php                     [P2]
│       ├── PlanSeeder.php                         [P2]  reads data/plan.json
│       ├── ExerciseLibrarySeeder.php              [P2]  alternatives, helpers, mobility, form cues
│       ├── PostureRuleSeeder.php                  [P4]
│       ├── FoodSeeder.php  MealTemplateSeeder.php [P5]
│       ├── BadgeSeeder.php                        [P6]
│       ├── DemoUserSeeder.php                     [P1]
│       └── data/
│           ├── plan.json                          [P0]  ✔ in this commit
│           ├── exercise-library.json              [P2]
│           ├── exercise-content.json              [P3]  form cues, mistakes, breathing, 3D keys
│           ├── posture-rules/*.json               [P4]  one file per exercise
│           ├── foods.json  meal-templates.json    [P5]
│           └── badges.json                        [P6]
├── resources/
│   ├── css/app.css                                [P1]  tokens, glass cards, neon utilities, fonts
│   ├── js/
│   │   ├── app.js                                 [P1]  Alpine bootstrap, page-level dynamic imports
│   │   ├── modules/
│   │   │   ├── timer.js                           [P3]  timestamp-based timers (survive tab switches)
│   │   │   ├── audio.js  voice-coach.js           [P3]  Web Audio cues, Web Speech prompts
│   │   │   ├── workout-session.js                 [P3]  state machine + autosave
│   │   │   ├── exercise-3d.js                     [P3]  Three.js humanoid, keyframes, .glb loader
│   │   │   ├── pose-engine.js                     [P4]  camera, MediaPipe, skeleton overlay
│   │   │   ├── rule-engine.js                     [P4]  evaluates posture_rules JSON per frame
│   │   │   ├── rep-counter.js                     [P4]  hysteresis state machine
│   │   │   ├── one-euro-filter.js  geometry.js    [P4]
│   │   │   ├── charts.js                          [P5/6] Chart.js wrappers, neon theme
│   │   │   ├── effects.js                         [P1]  count-up, rings, confetti, reduced-motion guard
│   │   │   └── pwa.js                             [P6]
│   │   └── pages/                                 [P1-6] one entry per heavy page (code-split)
│   ├── views/
│   │   ├── layouts/ (app, guest, game)            [P1]
│   │   ├── components/                            [P1]  card, ring, button, nav, bottom-tabbar, stat, ...
│   │   ├── onboarding/ dashboard/ profile/        [P1]
│   │   ├── plan/ library/                         [P2]
│   │   ├── workout/                               [P3]
│   │   ├── posture/                               [P4]
│   │   ├── diet/ food/                            [P5]
│   │   ├── progress/ recovery/ reports/ badges/ coach/ admin/   [P6]
│   │   └── offline.blade.php                      [P6]
├── public/
│   ├── vendor/mediapipe/{wasm/, models/pose_landmarker_lite.task}   [P4]  self-hosted
│   ├── manifest.webmanifest  sw.js  icons/        [P6]
└── tests/
    ├── Unit/  DietCalculatorServiceTest, ProgressiveOverloadServiceTest, OneRepMaxServiceTest, PlanSeedTest   [P2-5]
    ├── Feature/  Auth, Onboarding, Plan, WorkoutSessionApi, PostureSessionApi, FoodLog, Admin authorization   [P1-6]
    └── js/  rep-counter.test.mjs, rule-engine.test.mjs, timer.test.mjs   [P4]  Node test runner, synthetic landmarks
```

---

## 5. Database schema

Conventions: `id` = unsigned bigint PK; every table has `created_at`/`updated_at` unless noted; `→x` is a foreign key to `x.id`; user-owned rows cascade on user delete; enums are PHP backed enums mapped to Laravel `enum()`; JSON columns use Laravel `json` (works on MySQL and SQLite); **no FULLTEXT** so SQLite and MySQL behave the same (food autocomplete uses an indexed lowercase prefix column). 29 tables: your 17 suggested ones plus 12 additions, marked **(+)** with the reason.

### Identity and settings
### `users`
- name str(120) · email str(190) unique **nullable** (null for guests) · email_verified_at ts? · password str? · remember_token
- is_guest bool=false · role enum(user, admin)=user
- xp_total uint=0 · level uint=1 · workout_streak uint=0 · longest_workout_streak uint=0 · last_workout_on date?
- onboarded_at ts?
- idx: is_guest · xp_total (leaderboard)

### `profiles` (1:1)
- user_id →users unique cascade · plan_id →plans? null-on-delete
- public_name str(60)? (leaderboard alias) · age utinyint? · sex enum(male, female, unspecified)?
- height_cm dec(5,1)? · weight_kg dec(5,2)? · target_weight_kg dec(5,2)?
- activity_level enum(sedentary, light, moderate, active, very_active)?
- fitness_level enum(beginner, intermediate, advanced)? · goal enum(cutting, bulking, maintenance, recomposition)?
- diet_preference enum(nonveg, vegetarian, vegan, eggetarian)?
- equipment json (tags) · days_per_week utinyint=5 · training_days json=[1,2,3,4,5] (ISO)
- unit_system enum(metric, imperial)=metric · timezone str(64)=UTC · plan_started_on date?
- water_goal_ml uint? · sleep_goal_hours dec(3,1)=8.0 · creatine_dose_g dec(3,1)=5.0
- sound_enabled bool=true · voice_coach bool=false · leaderboard_opt_in bool=false · ai_coach_opt_in bool=false
- onboarding_step utinyint=0

### `notification_settings` **(+ keeps profile slim)**
- user_id →users unique cascade
- workout_reminder_at time? · water_reminders bool · water_interval_minutes smallint=90
- creatine_reminder_at time? · sleep_reminder_at time?
- browser_notifications bool · email_digest bool · digest_day utinyint=7

### Plan and content
### `plans` **(+ lets the plan be data, not code; one default row today)**
- slug str unique · name str · source str? · version uint=1 · is_default bool · rules json (progressive_overload, creatine, sleep)

### `plan_days`
- plan_id →plans cascade · day_of_week utinyint (1 = Mon ... 7 = Sun) · slug str · name str(20)
- session_type enum(push, pull, legs, rest) · label str(30) · focus str(60) · is_rest bool
- description text? · recovery_note text?
- unique: (plan_id, day_of_week) · (plan_id, slug)

### `exercises`
- slug str unique · name str(120) idx
- kind enum(plan, alternative, helper, mobility)
- muscle_group enum(chest, back, shoulders, biceps, triceps, quads, hamstrings, glutes, calves, traps, forearms, core, full_body)
- secondary_groups json? · target_regions json? (3D highlight keys, Phase 3)
- mechanic enum(compound, isolation) · equipment json · difficulty enum(beginner, intermediate, advanced)
- is_timed bool=false · rest_min_seconds / rest_max_seconds / default_rest_seconds smallint
- demo_key str? (keyframe set for the 3D model) · glb_path str? (optional model) 
- description text? · form_cues json? · common_mistakes json? · breathing text? · is_active bool=true
- idx: (kind, muscle_group) · difficulty

### `plan_day_exercises` (the link; holds everything that differs by weekday)
- plan_day_id →plan_days cascade · exercise_id →exercises restrict
- position utinyint · sets utinyint · reps_min utinyint · reps_max utinyint · focus str(120)
- label_override str(120)? · notes str? · rest_seconds_override smallint?
- unique: (plan_day_id, position) · (plan_day_id, exercise_id)

### `posture_rules`
- exercise_id →exercises cascade · tier enum(full, simplified, generic) · camera_view enum(side, front, three_quarter)
- config json (joints, phase angle ranges, tolerances, smoothing, named error checks, rep state-machine params)
- guidance json (camera setup text, accuracy note) · version uint=1 · is_active bool=true · updated_by →users? null-on-delete
- unique: (exercise_id, version)

### `user_exercise_swaps` **(+ per-user swap persistence)**
- user_id →users cascade · plan_day_exercise_id →plan_day_exercises cascade · replacement_exercise_id →exercises cascade
- is_permanent bool=false · unique: (user_id, plan_day_exercise_id)

### Training
### `workout_sessions`
- user_id →users cascade · plan_day_id →plan_days? null-on-delete · scheduled_for date
- source enum(game, manual) · status enum(in_progress, paused, completed, skipped, abandoned) · week_number uint
- started_at ts? · ended_at ts? · active_seconds uint=0 · paused_seconds uint=0
- volume_kg dec(10,2)=0 · sets_completed uint=0 · xp_earned uint=0 · rating utinyint? · notes text?
- autosave_state json? (position + timer *timestamps*) · client_uuid uuid unique (idempotent autosave)
- idx: (user_id, scheduled_for) · (user_id, status)

### `session_exercises` **(+ snapshot so history survives admin edits and swaps)**
- workout_session_id →workout_sessions cascade · exercise_id →exercises · original_exercise_id →exercises? (set when swapped)
- plan_day_exercise_id →plan_day_exercises? null-on-delete · position utinyint
- target_sets utinyint · target_reps_min utinyint · target_reps_max utinyint · target_weight_kg dec(6,2)? (the overload suggestion shown)
- status enum(pending, done, skipped)

### `session_sets`
- session_exercise_id →session_exercises cascade · workout_session_id →workout_sessions cascade · exercise_id →exercises
- set_number utinyint · weight_kg dec(6,2)? · reps utinyint? · duration_seconds uint? · rpe dec(3,1)?
- is_warmup bool=false · is_pr bool=false · completed_at ts
- idx: (exercise_id, completed_at)

### `posture_sessions` (no video or images are ever stored)
- user_id →users cascade · exercise_id →exercises · posture_rule_id →posture_rules? null-on-delete · workout_session_id →workout_sessions? null-on-delete
- rule_version uint · camera_view str · started_at ts · duration_seconds uint
- total_reps uint · good_reps uint · bad_reps uint · hold_seconds uint? (plank)
- form_score_avg utinyint · form_score_min utinyint · error_counts json · rep_log json
- avg_fps dec(4,1)? · model_variant str(20)
- idx: (user_id, started_at) · (user_id, exercise_id)

### `personal_records` **(+ fast PR lookup and "new PR" detection)**
- user_id →users cascade · exercise_id →exercises · type enum(max_weight, max_reps, est_1rm, best_set_volume)
- value dec(8,2) · weight_kg dec(6,2)? · reps utinyint? · session_set_id →session_sets? null-on-delete · achieved_at ts
- unique: (user_id, exercise_id, type)

### `progression_targets` **(+ next-week suggestions, per day row because rep ranges differ by day)**
- user_id →users cascade · plan_day_exercise_id →plan_day_exercises cascade · exercise_id →exercises
- target_weight_kg dec(6,2)? · target_reps utinyint · reason str(160) · based_on_session_id →workout_sessions? null-on-delete · computed_at ts
- unique: (user_id, plan_day_exercise_id) · idx: (user_id, exercise_id)

### Nutrition
### `diet_plans` (history kept; one active)
- user_id →users cascade · goal enum · diet_preference enum
- inputs snapshot: age utinyint · sex enum · height_cm dec(5,1) · weight_kg dec(5,2) · activity_level enum · training_days_per_week utinyint
- calorie_cycling bool · bmr uint · tdee uint
- training-day targets: calories uint · protein_g uint · carbs_g uint · fat_g uint · fibre_g uint · water_ml uint
- rest_day_targets json? ({calories, protein_g, carbs_g, fat_g, fibre_g}) · protein_g_per_kg dec(3,2)
- formula_version str(20) · is_active bool · idx: (user_id, is_active)

### `foods`
- user_id →users? cascade (null = global seeded; set = custom food)
- name str(160) · name_search str(160) idx (lowercased/normalised) · brand str(80)? · category str(40) idx
- diet_type enum(nonveg, eggetarian, vegetarian, vegan)
- kcal_100g dec(6,1) · protein_100g dec(5,1) · carbs_100g dec(5,1) · fat_100g dec(5,1) · fibre_100g dec(5,1)
- is_public bool · is_verified bool

### `food_servings` **(+ egg, slice, cup, scoop...)**
- food_id →foods cascade · label str(60) · grams dec(7,1) · is_default bool

### `food_logs` (nutrient snapshot so editing a food never rewrites history)
- user_id →users cascade · food_id →foods? null-on-delete · logged_on date · meal enum(breakfast, lunch, snack, dinner)
- name_snapshot str(160) · quantity dec(8,2) · unit enum(grams, serving) · serving_label str(60)? · grams dec(8,1)
- kcal dec(7,1) · protein_g dec(7,1) · carbs_g dec(7,1) · fat_g dec(7,1) · fibre_g dec(7,1)
- idx: (user_id, logged_on)

### `meal_templates` **(+ sample-day meal plans; curated, deterministic)**
- diet_preference enum · goal enum? · slot enum(breakfast, lunch, snack, dinner) · name str
- items json ([{food, grams}]) · kcal uint · protein_g uint · carbs_g uint · fat_g uint · fibre_g uint · tags json?

### `water_logs`
- user_id →users cascade · logged_on date · amount_ml uint · logged_at ts · idx: (user_id, logged_on)

### Body and recovery
### `measurements`
- user_id →users cascade · measured_on date · weight_kg dec(5,2)? · body_fat_pct dec(4,1)?
- neck_cm · shoulders_cm · chest_cm · waist_cm · hips_cm · bicep_left_cm · bicep_right_cm · thigh_cm · calf_cm (all dec(5,1)?) · notes str?
- unique: (user_id, measured_on)

### `progress_photos` **(+ optional photos, private disk)**
- user_id →users cascade · taken_on date · pose enum(front, side, back) · path str · mime str(40) · size_bytes uint · notes str?

### `daily_logs` **(+ creatine checkbox + sleep log + mobility minutes; one row per day)**
- user_id →users cascade · log_date date
- creatine_taken bool=false · creatine_taken_at ts?
- sleep_hours dec(3,1)? · sleep_quality utinyint? · bedtime time? · wake_time time?
- mobility_minutes uint=0 · notes str?
- unique: (user_id, log_date)

### Gamification
### `badges`
- slug str unique · name · description · icon str · category str(30) · tier enum(bronze, silver, gold, special)
- xp_reward uint · criteria json ({type, threshold}) · is_active bool

### `user_badges`
- user_id →users cascade · badge_id →badges cascade · awarded_at ts · context json?
- unique: (user_id, badge_id)

### `xp_events` (append-only; no `updated_at`)
- user_id →users cascade · type str(40) · amount int · source_type str(40)? · source_id unsigned bigint? · description str(160)
- created_at ts · unique: (user_id, type, source_type, source_id) (idempotent awards on retry) · idx: (user_id, created_at)

### Optional
### `coach_messages` **(+ only used when the AI coach is enabled)**
- user_id →users cascade · role enum(user, assistant) · content text · input_tokens uint? · output_tokens uint?

### `weekly_reports` **(+ cached payload and share link)**
- user_id →users cascade · week_start date · payload json · share_token str(40) unique? · shared_until ts?
- unique: (user_id, week_start)

### Laravel built-ins
`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.

### Relationship summary
`User 1-1 Profile`, `User 1-1 NotificationSetting`, `Plan 1-n PlanDay`, `PlanDay n-n Exercise` (through `plan_day_exercises`), `Exercise 1-n PostureRule`, `User 1-n WorkoutSession 1-n SessionExercise 1-n SessionSet`, `User 1-n PostureSession`, `User 1-n DietPlan`, `Food 1-n FoodServing`, `User 1-n FoodLog`, `User n-n Badge` (through `user_badges`).

---

## 6. Phase plan

| Phase | Scope | What I will actually verify before I stop |
|---|---|---|
| **1** | Laravel scaffold, Breeze, Vite, design system (tokens, glass cards, neon, fonts, bottom tab bar + sidebar), guest mode, onboarding wizard, dashboard shell, core migrations (users, profiles, notification_settings, plans/plan_days/exercises/plan_day_exercises), `DemoUserSeeder` | `composer install`, `npm ci && npm run build`, `php artisan migrate --seed`, Pest feature tests, headless Chromium screenshots at 360 px and 1440 px |
| **2** | `PlanSeeder` from `plan.json`, exercise library seed, week/day pages, swap, complete/skip, exercise library search + filters | Seed test asserting 31 / 35 / 4-shared against the JSON; page tests |
| **3** | Game mode: countdown, timers, set/rep tracker, autosave/resume, summary, 3D humanoid + keyframes + `.glb` loader, XP/PR/progressive overload | Timer + overload unit tests, session API tests (idempotent autosave), Chromium run-through with WebGL screenshot |
| **4** | Posture Lab: self-hosted MediaPipe, skeleton overlay, red/green segments, rule engine, rep counter + One-Euro filter, history | JS tests on synthetic landmark sequences (good/bad reps, hysteresis, min rep time); Chromium with a **fake camera feed** to confirm the pipeline boots and draws. Real-lifter accuracy needs your camera. |
| **5** | `DietCalculatorService` (+ tests), diet UI + donut, 150+ foods, food logger, running totals, gap hints, charts | Unit tests with hand-computed expected values; autocomplete query tests |
| **6** | Gamification, progress, PRs/1RM, water, creatine/sleep, recovery, warm-up generator, PWA, weekly report PDF, reminders + digest, admin, AI coach | Feature + policy tests (non-admin blocked), `config`-gated coach test, offline shell check |
| **7** | Test sweep, README, deployment notes, final fresh-clone install run | Fresh clone -> install -> migrate -> seed -> test -> build, exactly as the README says |

### `.env` keys (introduced progressively)
`APP_NAME=IronPulse`, `DB_CONNECTION=sqlite` (or `mysql` + `DB_*`), `SEED_PASSWORD`, `IRONPULSE_GUEST_MODE=true`, `MAIL_*` (digest), `COACH_ENABLED=false`, `ANTHROPIC_API_KEY`, `COACH_MODEL`, `COACH_DAILY_MESSAGE_LIMIT`.
