<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Guest mode
    |--------------------------------------------------------------------------
    | Lets a visitor try the app without registering. A guest is a real user
    | row (is_guest = true) that can later be "claimed" with an email/password.
    */
    /*
    |--------------------------------------------------------------------------
    | Password policy
    |--------------------------------------------------------------------------
    | One definition for the website, the API and the apps (published in /api/v1/app/config so the apps can explain it
    | before sending anything). The server always re-checks.
    */
    'password' => [
        'min' => 8,
        'letters' => true,
        'numbers' => true,
    ],

    'guest_mode' => (bool) env('IRONPULSE_GUEST_MODE', true),
    // Guests nobody has come back to for this many days are deleted by `ironpulse:prune-guests`.
    'guest_ttl_days' => (int) env('IRONPULSE_GUEST_TTL_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | XP and levels
    |--------------------------------------------------------------------------
    | level = floor(sqrt(xp / level_base)) + 1
    */
    'xp' => [
        'level_base' => 100,
        'set_completed' => 5,
        'workout_completed' => 50,
        'exercise_completed' => 5,
        'personal_record' => 25,
        'posture_session' => 20,
        'posture_good_rep' => 1,
        // Posture scores are computed on the user's device, so XP from them is capped rather than trusted.
        'posture_good_rep_session_cap' => 30,
        'posture_good_rep_daily_cap' => 100,
        'posture_session_min_good_reps' => 3,
        'posture_session_min_hold_seconds' => 15,
        'water_goal' => 10,
        'creatine' => 5,
        'sleep_goal' => 10,
        'protein_goal' => 15,
        'streak_milestones' => [3 => 25, 7 => 75, 14 => 150, 30 => 400],
    ],

    /*
    |--------------------------------------------------------------------------
    | Posture Lab
    |--------------------------------------------------------------------------
    | Where `npm run vendor:mediapipe` installs the self-hosted MediaPipe runtime and model.
    */
    'posture' => [
        'assets_dir' => public_path('vendor/mediapipe'),
        'assets_url' => '/vendor/mediapipe',
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile API (/api/v1)
    |--------------------------------------------------------------------------
    | Bearer-token API used by the Android and iOS apps. Web sessions are unaffected.
    */
    'mobile' => [
        // Absolute lifetime of an access token. The app refreshes it (rotating the token) while it is still valid.
        'token_ttl_days' => (int) env('IRONPULSE_TOKEN_TTL_DAYS', 60),
        // The app is told to refresh when fewer than this many days remain.
        'refresh_window_days' => (int) env('IRONPULSE_TOKEN_REFRESH_WINDOW_DAYS', 30),
        // Signed-in devices kept per account; the oldest session is revoked beyond this.
        'max_devices' => (int) env('IRONPULSE_MAX_DEVICES', 10),
        'page_size' => ['default' => 20, 'max' => 100],
        // Idempotency keys (and their stored responses) are kept this many hours.
        'idempotency_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Membership payments
    |--------------------------------------------------------------------------
    | Digital subscriptions bought inside the mobile apps must go through Apple / Google billing. The app sends the
    | store receipt; a verifier here confirms it with the store. A store only appears as "available" once its
    | verifier reports that its server credentials are configured. No verifier ships enabled: see
    | docs/mobile/payments.md for what each store needs.
    */
    'payments' => [
        'verifiers' => [
            // 'apple' => \App\Services\Membership\AppleStoreVerifier::class,
            // 'google' => \App\Services\Membership\GooglePlayVerifier::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Push notifications (FCM)
    |--------------------------------------------------------------------------
    | Off until a Firebase project is configured. Without it the apps keep working and use on-device reminders only.
    */
    'push' => [
        'enabled' => (bool) env('FCM_PROJECT_ID') && (bool) env('FCM_CREDENTIALS_PATH'),
        'project_id' => env('FCM_PROJECT_ID'),
        // Path to the Firebase service-account JSON on the server. Never commit it, never ship it in an app.
        'credentials_path' => env('FCM_CREDENTIALS_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Progressive overload
    |--------------------------------------------------------------------------
    | The plan says: add 1-2 reps OR add 2.5-5 kg each week.
    */
    'overload' => [
        'upper_increment_kg' => 2.5,
        'lower_increment_kg' => 5.0,
        'lower_groups' => ['quads', 'hamstrings', 'glutes', 'calves'],
        'rep_step_min' => 1,
        'rep_step_max' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Workout timing
    |--------------------------------------------------------------------------
    | Used to estimate session length: seconds of work per set.
    */
    'seconds_per_set' => 40,
    'warmup_minutes' => 8,

    /*
    |--------------------------------------------------------------------------
    | Nutrition
    |--------------------------------------------------------------------------
    */
    'water' => [
        'ml_per_kg' => 35,
        'training_day_bonus_ml' => 500,
        'default_glass_ml' => 250,
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional AI coach (server-side only; the app works fully without it)
    |--------------------------------------------------------------------------
    */
    'coach' => [
        'enabled' => (bool) env('COACH_ENABLED', false),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('COACH_MODEL'),
        'daily_message_limit' => (int) env('COACH_DAILY_MESSAGE_LIMIT', 20),
        // Models that think before answering spend part of this on thinking, so keep it generous.
        'max_output_tokens' => (int) env('COACH_MAX_OUTPUT_TOKENS', 1500),
        // Optional reasoning effort (low, medium, high). Leave blank for models that do not take it.
        'effort' => env('COACH_EFFORT'),
        'history_messages' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Equipment a user can tick during onboarding (profile -> exercise tags)
    |--------------------------------------------------------------------------
    */
    'equipment' => [
        'barbell' => 'Barbell and plates',
        'dumbbell' => 'Dumbbells',
        'cable' => 'Cable machine',
        'machine' => 'Weight machines',
        'bench' => 'Bench (flat / incline)',
        'squat_rack' => 'Squat rack',
        'pull_up_bar' => 'Pull-up bar',
        'bodyweight' => 'Bodyweight only',
    ],

    /** Exercise equipment tags that are satisfied by a profile equipment key. */
    'equipment_map' => [
        'rope' => 'cable',
        'incline_bench' => 'bench',
        'preacher_bench' => 'bench',
        'assist_machine' => 'machine',
    ],

    /** Display names for the muscle regions used by the 3D model and exercise content. */
    'regions' => [
        'chest_upper' => 'Upper chest', 'chest_lower' => 'Lower chest', 'delt_front' => 'Front delts',
        'delt_side' => 'Side delts', 'delt_rear' => 'Rear delts', 'biceps' => 'Biceps', 'triceps' => 'Triceps',
        'forearms' => 'Forearms', 'lats' => 'Lats', 'mid_back' => 'Mid-back', 'traps' => 'Traps',
        'lower_back' => 'Lower back', 'abs' => 'Abs', 'glutes' => 'Glutes', 'quads' => 'Quads',
        'hamstrings' => 'Hamstrings', 'calves' => 'Calves',
    ],

    /*
    |--------------------------------------------------------------------------
    | Motivation
    |--------------------------------------------------------------------------
    */
    'quotes' => [
        'Muscle is built during recovery, not in the gym.',
        'Add a rep or a plate. Progress is the whole game.',
        'You do not need to feel ready. You just need to start the first set.',
        'Consistency beats intensity. Show up, then do it again tomorrow.',
        'Strong is built one honest rep at a time.',
        'Your only competition is who you were last week.',
        'Sleep, eat, lift, repeat. Boring works.',
        'The last two reps are where the growth is.',
        'Control the weight. Do not let it control you.',
        'Small weekly progress becomes a different body in a year.',
        'Discipline is choosing what you want most over what you want now.',
        'Warm up well, train hard, recover harder.',
        'Form first. Load second. Ego never.',
        'A short workout beats a skipped workout.',
        'Today is a training day for the person you are becoming.',
        'Hydrate. Your muscles are mostly water.',
        'Earn your rest day.',
        'Track it, beat it, repeat it.',
        'You are one workout away from a better mood.',
        'Progressive overload: the number one driver of muscle growth.',
        'Eat enough protein, lift heavy things, be patient.',
        'Make the plan boring and the results exciting.',
        'Every expert was once a beginner who kept showing up.',
        'Breathe out on the effort, in on the way back.',
        'Recovery is part of the program.',
        'Stay consistent with your creatine and your sleep.',
        'Pain of discipline weighs ounces. Regret weighs tons.',
        'Leave the gym knowing you left nothing on the bench.',
        'Make every rep look the same.',
        'Be the person your future self thanks.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation (an item is shown only when its route exists)
    |--------------------------------------------------------------------------
    */
    'nav' => [
        ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'tab' => true],
        ['route' => 'plan.index', 'label' => 'Plan', 'icon' => 'calendar', 'tab' => true],
        ['route' => 'workout.start', 'label' => 'Train', 'icon' => 'dumbbell', 'tab' => 'center', 'match' => 'workout.*'],
        ['route' => 'posture.index', 'label' => 'Posture Lab', 'icon' => 'scan', 'tab' => true, 'match' => 'posture.*'],
        ['route' => 'diet.index', 'label' => 'Diet', 'icon' => 'utensils', 'match' => 'diet.*'],
        ['route' => 'food.index', 'label' => 'Food log', 'icon' => 'apple', 'match' => 'food.*'],
        ['route' => 'progress.index', 'label' => 'Progress', 'icon' => 'chart', 'match' => 'progress.*'],
        ['route' => 'library.index', 'label' => 'Exercise library', 'icon' => 'book', 'match' => 'library.*'],
        ['route' => 'recovery.index', 'label' => 'Recovery', 'icon' => 'moon', 'match' => 'recovery.*'],
        ['route' => 'badges.index', 'label' => 'Badges', 'icon' => 'trophy', 'match' => 'badges.*'],
        ['route' => 'reports.index', 'label' => 'Weekly report', 'icon' => 'file', 'match' => 'reports.*'],
        ['route' => 'coach.index', 'label' => 'AI coach', 'icon' => 'message', 'match' => 'coach.*', 'feature' => 'coach'],
        ['route' => 'profile.edit', 'label' => 'Profile', 'icon' => 'user', 'match' => 'profile.*'],
        ['route' => 'admin.index', 'label' => 'Admin', 'icon' => 'shield', 'match' => 'admin.*', 'admin' => true],
    ],
];
