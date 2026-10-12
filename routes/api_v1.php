<?php

use App\Http\Controllers\Api\V1\AchievementController;
use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExerciseController;
use App\Http\Controllers\Api\V1\FoodController;
use App\Http\Controllers\Api\V1\FoodLogController;
use App\Http\Controllers\Api\V1\HabitController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NutritionController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\PostureController;
use App\Http\Controllers\Api\V1\ProgramController;
use App\Http\Controllers\Api\V1\ProgressController;
use App\Http\Controllers\Api\V1\PublicContentController;
use App\Http\Controllers\Api\V1\SavedMealController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\WorkoutSessionController;
use Illuminate\Support\Facades\Route;

/*
| Mobile API, version 1. Mounted at /api/v1 with names prefixed "v1." (see bootstrap/app.php).
| Bearer-token (Sanctum) authentication only; no session, no CSRF. Every response is JSON.
|
| Every protected route is checked on the server: `entitled:<key>` enforces the member's plan, and ids in URLs are
| always looked up among the caller's own records. Keep docs/mobile/openapi.yaml in step:
| tests/Feature/Api/V1/OpenApiParityTest.php fails when the routes and the spec disagree.
*/

// ---------------------------------------------------------------------------------------------- public
Route::middleware('throttle:v1-public')->group(function () {
    Route::get('/app/config', AppConfigController::class)->name('app.config');
    Route::get('/membership/plans', [MembershipController::class, 'plans'])->name('membership.plans');

    // Media behind signed, expiring URLs (issued only to members allowed to see the item)
    Route::middleware('signed')->prefix('media')->name('media.')->group(function () {
        Route::get('/exercise/{media}', [MediaController::class, 'exercise'])->whereNumber('media')->name('exercise');
        Route::get('/banner/{banner}', [MediaController::class, 'banner'])->whereNumber('banner')->name('banner');
    });
});

Route::middleware('throttle:v1-auth')->prefix('auth')->name('auth.')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/guest', [AuthController::class, 'guest'])->name('guest');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');
});

// ---------------------------------------------------------------------------------------------- signed in
Route::middleware(['auth:sanctum', 'api.active', 'api.touch', 'api.idempotent', 'throttle:v1'])->group(function () {
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('refresh');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
        Route::get('/sessions', [AuthController::class, 'sessions'])->name('sessions');
        Route::delete('/sessions/{device}', [AuthController::class, 'revokeSession'])->whereNumber('device')->name('sessions.destroy');
    });

    Route::prefix('me')->name('me.')->group(function () {
        Route::get('/', [MeController::class, 'show'])->name('show');
        Route::patch('/', [MeController::class, 'update'])->name('update');
        Route::put('/password', [MeController::class, 'updatePassword'])->name('password');
        Route::delete('/', [MeController::class, 'destroy'])->name('destroy');
        Route::post('/claim', [MeController::class, 'claim'])->name('claim');
        Route::post('/onboarding', [MeController::class, 'onboarding'])->name('onboarding');
        Route::get('/profile', [MeController::class, 'profile'])->name('profile');
        Route::get('/notifications', [NotificationController::class, 'show'])->name('notifications');
        Route::put('/notifications', [NotificationController::class, 'update'])->name('notifications.update');
        Route::put('/device/push', [NotificationController::class, 'registerPush'])->name('device.push');
        Route::delete('/device/push', [NotificationController::class, 'unregisterPush'])->name('device.push.destroy');
    });

    Route::prefix('membership')->name('membership.')->group(function () {
        Route::get('/', [MembershipController::class, 'show'])->name('show');
        Route::get('/entitlements', [MembershipController::class, 'entitlements'])->name('entitlements');
        Route::post('/purchases/verify', [MembershipController::class, 'verify'])->middleware('throttle:v1-purchases')->name('verify');
        Route::post('/restore', [MembershipController::class, 'restore'])->middleware('throttle:v1-purchases')->name('restore');
    });

    // Content: browse, cache and sync
    Route::prefix('content')->name('content.')->group(function () {
        Route::get('/manifest', [ContentController::class, 'manifest'])->name('manifest');
        Route::get('/changes', [ContentController::class, 'changes'])->name('changes');
        Route::get('/{collection}', [ContentController::class, 'snapshot'])->where('collection', '[a-z_]+')->name('snapshot');
    });
    Route::get('/programs', [ProgramController::class, 'index'])->name('programs.index');
    Route::get('/programs/{plan}', [ProgramController::class, 'show'])->whereNumber('plan')->name('programs.show');
    Route::get('/exercises', [ExerciseController::class, 'index'])->name('exercises.index');
    Route::get('/exercises/{exercise}', [ExerciseController::class, 'show'])->where('exercise', '[a-z0-9-]+')->name('exercises.show');
    Route::get('/announcements', [PublicContentController::class, 'announcements'])->name('announcements');
    Route::get('/banners', [PublicContentController::class, 'banners'])->name('banners');
    Route::get('/help', [PublicContentController::class, 'help'])->name('help.index');
    Route::get('/help/{slug}', [PublicContentController::class, 'helpArticle'])->where('slug', '[a-z0-9-]+')->name('help.show');

    // ------------------------------------------------------------------------------------------ needs a finished profile
    Route::middleware('api.onboarded')->group(function () {
        Route::put('/me/profile', [MeController::class, 'updateProfile'])->name('me.profile.update');
        Route::get('/me/settings', [MeController::class, 'settings'])->name('me.settings');
        Route::put('/me/settings', [MeController::class, 'updateSettings'])->name('me.settings.update');
        Route::post('/me/program', [ProgramController::class, 'select'])->name('me.program');
        Route::get('/exercises/{exercise}/alternatives', [ExerciseController::class, 'alternatives'])->where('exercise', '[a-z0-9-]+')->name('exercises.alternatives');

        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // Habits that are free for everyone (water belongs to nutrition below)
        Route::get('/habits', [HabitController::class, 'show'])->name('habits.show');
        Route::put('/habits/creatine', [HabitController::class, 'creatine'])->name('habits.creatine');
        Route::put('/habits/sleep', [HabitController::class, 'sleep'])->name('habits.sleep');
        Route::put('/habits/mobility', [HabitController::class, 'mobility'])->name('habits.mobility');

        // Achievements and the leaderboard are computed on the server only
        Route::get('/achievements', [AchievementController::class, 'index'])->name('achievements');
        Route::get('/leaderboard', [AchievementController::class, 'leaderboard'])->name('leaderboard');

        // Body measurements are free; the charts and reports built from them are "analytics"
        Route::get('/progress/measurements', [ProgressController::class, 'measurements'])->name('progress.measurements');
        Route::post('/progress/measurements', [ProgressController::class, 'storeMeasurement'])->name('progress.measurements.store');
        Route::delete('/progress/measurements/{measurement}', [ProgressController::class, 'destroyMeasurement'])->whereNumber('measurement')->name('progress.measurements.destroy');

        // ---- workouts ----
        Route::middleware('entitled:workouts')->group(function () {
            Route::get('/plan/week', [PlanController::class, 'week'])->name('plan.week');
            Route::get('/plan/days/{weekday}', [PlanController::class, 'day'])->whereNumber('weekday')->name('plan.day');
            Route::post('/plan/days/{weekday}/complete', [PlanController::class, 'complete'])->whereNumber('weekday')->name('plan.complete');
            Route::post('/plan/days/{weekday}/skip', [PlanController::class, 'skip'])->whereNumber('weekday')->name('plan.skip');
            Route::delete('/plan/days/{weekday}/skip', [PlanController::class, 'unskip'])->whereNumber('weekday')->name('plan.unskip');
            Route::get('/plan-items/{item}/alternatives', [PlanController::class, 'alternatives'])->whereNumber('item')->name('plan-items.alternatives');
            Route::post('/plan-items/{item}/swap', [PlanController::class, 'swap'])->whereNumber('item')->name('plan-items.swap');
            Route::delete('/plan-items/{item}/swap', [PlanController::class, 'unswap'])->whereNumber('item')->name('plan-items.unswap');

            Route::get('/workout-sessions', [WorkoutSessionController::class, 'index'])->name('workout-sessions.index');
            Route::post('/workout-sessions', [WorkoutSessionController::class, 'store'])->name('workout-sessions.store');
            Route::get('/workout-sessions/active', [WorkoutSessionController::class, 'active'])->name('workout-sessions.active');
            Route::prefix('workout-sessions/{session}')->whereNumber('session')->name('workout-sessions.')->group(function () {
                Route::get('/', [WorkoutSessionController::class, 'show'])->name('show');
                Route::get('/summary', [WorkoutSessionController::class, 'summary'])->name('summary');
                Route::put('/state', [WorkoutSessionController::class, 'state'])->name('state');
                Route::post('/sets', [WorkoutSessionController::class, 'logSet'])->name('sets.store');
                Route::delete('/sets/{set}', [WorkoutSessionController::class, 'deleteSet'])->whereNumber('set')->name('sets.destroy');
                Route::get('/exercises/{exercise}/alternatives', [WorkoutSessionController::class, 'alternatives'])->whereNumber('exercise')->name('exercises.alternatives');
                Route::post('/exercises/{exercise}/swap', [WorkoutSessionController::class, 'swap'])->whereNumber('exercise')->name('exercises.swap');
                Route::post('/exercises/{exercise}/skip', [WorkoutSessionController::class, 'skipExercise'])->whereNumber('exercise')->name('exercises.skip');
                Route::post('/finish', [WorkoutSessionController::class, 'finish'])->name('finish');
                Route::post('/abandon', [WorkoutSessionController::class, 'abandon'])->name('abandon');
            });
        });

        // ---- nutrition ----
        Route::middleware('entitled:nutrition')->group(function () {
            Route::get('/nutrition/targets', [NutritionController::class, 'targets'])->name('nutrition.targets');
            Route::post('/nutrition/targets/recalculate', [NutritionController::class, 'recalculate'])->name('nutrition.recalculate');
            Route::get('/nutrition/day', [NutritionController::class, 'day'])->name('nutrition.day');
            Route::get('/nutrition/history', [NutritionController::class, 'history'])->name('nutrition.history');
            Route::get('/nutrition/meal-plan', [NutritionController::class, 'mealPlan'])->name('nutrition.meal-plan');
            Route::post('/nutrition/calculate', [NutritionController::class, 'calculate'])->name('nutrition.calculate');

            Route::get('/foods/search', [FoodController::class, 'search'])->name('foods.search');
            Route::get('/foods/recent', [FoodController::class, 'recent'])->name('foods.recent');
            Route::get('/foods/favorites', [FoodController::class, 'favorites'])->name('foods.favorites');
            Route::post('/foods', [FoodController::class, 'store'])->name('foods.store');
            Route::get('/foods/{food}', [FoodController::class, 'show'])->whereNumber('food')->name('foods.show');
            Route::delete('/foods/{food}', [FoodController::class, 'destroy'])->whereNumber('food')->name('foods.destroy');
            Route::put('/foods/{food}/favorite', [FoodController::class, 'favorite'])->whereNumber('food')->name('foods.favorite');
            Route::delete('/foods/{food}/favorite', [FoodController::class, 'unfavorite'])->whereNumber('food')->name('foods.unfavorite');

            Route::post('/food-logs', [FoodLogController::class, 'store'])->name('food-logs.store');
            Route::post('/food-logs/batch', [FoodLogController::class, 'batch'])->name('food-logs.batch');
            Route::post('/food-logs/copy', [FoodLogController::class, 'copy'])->name('food-logs.copy');
            Route::patch('/food-logs/{log}', [FoodLogController::class, 'update'])->whereNumber('log')->name('food-logs.update');
            Route::delete('/food-logs/{log}', [FoodLogController::class, 'destroy'])->whereNumber('log')->name('food-logs.destroy');

            Route::get('/saved-meals', [SavedMealController::class, 'index'])->name('saved-meals.index');
            Route::post('/saved-meals', [SavedMealController::class, 'store'])->name('saved-meals.store');
            Route::delete('/saved-meals/{meal}', [SavedMealController::class, 'destroy'])->whereNumber('meal')->name('saved-meals.destroy');
            Route::post('/saved-meals/{meal}/log', [SavedMealController::class, 'apply'])->whereNumber('meal')->name('saved-meals.log');

            Route::post('/habits/water', [HabitController::class, 'addWater'])->name('habits.water.add');
            Route::delete('/habits/water/last', [HabitController::class, 'undoWater'])->name('habits.water.undo');
        });

        // ---- analytics ----
        Route::middleware('entitled:analytics')->group(function () {
            Route::get('/progress/summary', [ProgressController::class, 'summary'])->name('progress.summary');
            Route::get('/progress/records', [ProgressController::class, 'records'])->name('progress.records');
            Route::get('/progress/strength/{exercise}', [ProgressController::class, 'strength'])->where('exercise', '[a-z0-9-]+')->name('progress.strength');
        });

        // ---- progress photos ----
        Route::middleware('entitled:progress_photos')->group(function () {
            Route::get('/progress/photos', [ProgressController::class, 'photos'])->name('progress.photos');
            Route::post('/progress/photos', [ProgressController::class, 'storePhoto'])->middleware('throttle:v1-uploads')->name('progress.photos.store');
            Route::get('/progress/photos/{photo}', [ProgressController::class, 'showPhoto'])->whereNumber('photo')->name('progress.photos.show');
            Route::delete('/progress/photos/{photo}', [ProgressController::class, 'destroyPhoto'])->whereNumber('photo')->name('progress.photos.destroy');
        });

        // ---- camera posture coach ----
        Route::middleware('entitled:posture_coach')->prefix('posture')->name('posture.')->group(function () {
            Route::get('/exercises', [PostureController::class, 'exercises'])->name('exercises');
            Route::get('/rules/{exercise}', [PostureController::class, 'rule'])->where('exercise', '[a-z0-9-]+')->name('rule');
            Route::post('/sessions', [PostureController::class, 'store'])->name('sessions.store');
        });

        // ---- offline sync (each operation is checked against the same entitlements as its online twin) ----
        Route::post('/sync/batch', [SyncController::class, 'batch'])->middleware('throttle:v1-sync')->name('sync.batch');
    });
});
