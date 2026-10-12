<?php

use App\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Admin\AppReleaseController as AdminAppReleaseController;
use App\Http\Controllers\Admin\AppSettingController as AdminAppSettingController;
use App\Http\Controllers\Admin\BannerController as AdminBannerController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DemoGalleryController;
use App\Http\Controllers\Admin\ExerciseController as AdminExerciseController;
use App\Http\Controllers\Admin\ExerciseMediaController as AdminExerciseMediaController;
use App\Http\Controllers\Admin\FoodController as AdminFoodController;
use App\Http\Controllers\Admin\HelpArticleController as AdminHelpArticleController;
use App\Http\Controllers\Admin\MembershipPlanController as AdminMembershipPlanController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\PostureRuleController as AdminPostureRuleController;
use App\Http\Controllers\Admin\ProgramController as AdminProgramController;
use App\Http\Controllers\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\CoachController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DietController;
use App\Http\Controllers\FoodController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PostureLabController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\RecoveryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\WorkoutController;
use Illuminate\Support\Facades\Route;

/*
| Authenticated, onboarded application routes (loaded inside web.php).
| Each phase adds its own section below.
*/

Route::get('/dashboard', DashboardController::class)->name('dashboard');

// Plan
Route::middleware('entitled:workouts')->group(function () {
    Route::get('/plan', [PlanController::class, 'index'])->name('plan.index');
    Route::get('/plan/{day}', [PlanController::class, 'show'])->whereNumber('day')->name('plan.day');
    Route::post('/plan/{day}/complete', [PlanController::class, 'complete'])->whereNumber('day')->name('plan.complete');
    Route::post('/plan/{day}/skip', [PlanController::class, 'skip'])->whereNumber('day')->name('plan.skip');
    Route::delete('/plan/{day}/skip', [PlanController::class, 'unskip'])->whereNumber('day')->name('plan.unskip');
});

// Exercise library
Route::get('/library', [LibraryController::class, 'index'])->name('library.index');
Route::get('/library/{exercise}', [LibraryController::class, 'show'])->name('library.show');

// Workout (game mode)
Route::middleware('entitled:workouts')->group(function () {
    Route::get('/workout', [WorkoutController::class, 'start'])->name('workout.start');
    Route::get('/workout/{session}', [WorkoutController::class, 'play'])->whereNumber('session')->name('workout.play');
    Route::get('/workout/{session}/summary', [WorkoutController::class, 'summary'])->whereNumber('session')->name('workout.summary');
});

// Diet and food log
Route::middleware('entitled:nutrition')->group(function () {
    Route::get('/diet', [DietController::class, 'index'])->name('diet.index');
    Route::post('/diet/recalculate', [DietController::class, 'recalculate'])->name('diet.recalculate');
    Route::get('/food', [FoodController::class, 'index'])->name('food.index');
});

// Progress: body measurements, photos, strength
Route::get('/progress', [ProgressController::class, 'index'])->name('progress.index');
Route::post('/progress/measurements', [ProgressController::class, 'storeMeasurement'])->middleware('throttle:30,1')->name('progress.measurements.store');
Route::delete('/progress/measurements/{measurement}', [ProgressController::class, 'destroyMeasurement'])->whereNumber('measurement')->name('progress.measurements.destroy');
Route::middleware('entitled:progress_photos')->group(function () {
    Route::post('/progress/photos', [ProgressController::class, 'storePhoto'])->middleware('throttle:uploads')->name('progress.photos.store');
    Route::get('/progress/photos/{photo}', [ProgressController::class, 'showPhoto'])->whereNumber('photo')->name('progress.photos.show');
    Route::delete('/progress/photos/{photo}', [ProgressController::class, 'destroyPhoto'])->whereNumber('photo')->name('progress.photos.destroy');
});

// Recovery: habits, warm-up / cool-down generator
Route::get('/recovery', [RecoveryController::class, 'index'])->name('recovery.index');

// Badges and leaderboard
Route::get('/badges', [BadgeController::class, 'index'])->name('badges.index');

// Optional AI coach (404 unless COACH_ENABLED)
Route::middleware('entitled:coach.ai')->group(function () {
    Route::get('/coach', [CoachController::class, 'index'])->name('coach.index');
    Route::post('/coach/opt-in', [CoachController::class, 'optIn'])->name('coach.opt-in');
    Route::delete('/coach/opt-in', [CoachController::class, 'optOut'])->name('coach.opt-out');
});

// Weekly reports: page, PDF, share links
Route::middleware('entitled:analytics')->group(function () {
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{week}', [ReportController::class, 'show'])->where('week', '\d{4}-\d{2}-\d{2}')->name('reports.show');
    Route::get('/reports/{week}/pdf', [ReportController::class, 'pdf'])->where('week', '\d{4}-\d{2}-\d{2}')->middleware('throttle:20,1')->name('reports.pdf');
    Route::post('/reports/{week}/share', [ReportController::class, 'share'])->where('week', '\d{4}-\d{2}-\d{2}')->name('reports.share');
    Route::delete('/reports/{week}/share', [ReportController::class, 'unshare'])->where('week', '\d{4}-\d{2}-\d{2}')->name('reports.unshare');
});

// Posture Lab
Route::middleware('entitled:posture_coach')->group(function () {
    Route::get('/posture', [PostureLabController::class, 'index'])->name('posture.index');
    Route::get('/posture/{exercise}', [PostureLabController::class, 'show'])->name('posture.show');
});

// Admin panel
Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('index');
    Route::get('/demos', DemoGalleryController::class)->name('demos');

    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->whereNumber('user')->name('users.show');
    Route::patch('/users/{user}/role', [AdminUserController::class, 'updateRole'])->whereNumber('user')->name('users.role');
    Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus'])->whereNumber('user')->name('users.status');
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->whereNumber('user')->name('users.destroy');

    // Programs: publishing, premium gate, sprints and phases
    Route::get('/programs', [AdminProgramController::class, 'index'])->name('programs.index');
    Route::post('/programs', [AdminProgramController::class, 'store'])->name('programs.store');
    Route::get('/programs/{plan}/edit', [AdminProgramController::class, 'edit'])->whereNumber('plan')->name('programs.edit');
    Route::patch('/programs/{plan}', [AdminProgramController::class, 'update'])->whereNumber('plan')->name('programs.update');
    Route::post('/programs/{plan}/phases', [AdminProgramController::class, 'storePhase'])->whereNumber('plan')->name('programs.phases.store');
    Route::patch('/phases/{phase}', [AdminProgramController::class, 'updatePhase'])->whereNumber('phase')->name('programs.phases.update');
    Route::delete('/phases/{phase}', [AdminProgramController::class, 'destroyPhase'])->whereNumber('phase')->name('programs.phases.destroy');

    Route::get('/plan', [AdminPlanController::class, 'index'])->name('plan.index');
    Route::get('/plan/days/{day}', [AdminPlanController::class, 'edit'])->whereNumber('day')->name('plan.edit');
    Route::patch('/plan/days/{day}', [AdminPlanController::class, 'update'])->whereNumber('day')->name('plan.update');
    Route::post('/plan/days/{day}/items', [AdminPlanController::class, 'storeItem'])->whereNumber('day')->name('plan.items.store');
    Route::patch('/plan/items/{item}', [AdminPlanController::class, 'updateItem'])->whereNumber('item')->name('plan.items.update');
    Route::post('/plan/items/{item}/move', [AdminPlanController::class, 'moveItem'])->whereNumber('item')->name('plan.items.move');
    Route::delete('/plan/items/{item}', [AdminPlanController::class, 'destroyItem'])->whereNumber('item')->name('plan.items.destroy');

    Route::get('/exercises', [AdminExerciseController::class, 'index'])->name('exercises.index');
    Route::get('/exercises/create', [AdminExerciseController::class, 'create'])->name('exercises.create');
    Route::post('/exercises', [AdminExerciseController::class, 'store'])->name('exercises.store');
    Route::get('/exercises/{exercise}/edit', [AdminExerciseController::class, 'edit'])->name('exercises.edit');
    Route::patch('/exercises/{exercise}', [AdminExerciseController::class, 'update'])->name('exercises.update');
    Route::delete('/exercises/{exercise}', [AdminExerciseController::class, 'destroy'])->name('exercises.destroy');
    Route::post('/exercises/{exercise}/media', [AdminExerciseMediaController::class, 'store'])->name('exercises.media.store');
    Route::patch('/exercise-media/{media}', [AdminExerciseMediaController::class, 'update'])->whereNumber('media')->name('exercise-media.update');
    Route::delete('/exercise-media/{media}', [AdminExerciseMediaController::class, 'destroy'])->whereNumber('media')->name('exercise-media.destroy');

    Route::get('/posture', [AdminPostureRuleController::class, 'index'])->name('posture.index');
    Route::post('/posture/validate', [AdminPostureRuleController::class, 'validateConfig'])->name('posture.validate');
    Route::get('/posture/{exercise}', [AdminPostureRuleController::class, 'edit'])->name('posture.edit');
    Route::put('/posture/{exercise}', [AdminPostureRuleController::class, 'update'])->name('posture.update');
    Route::post('/posture/{exercise}/restore/{rule}', [AdminPostureRuleController::class, 'restore'])->whereNumber('rule')->name('posture.restore');

    // Memberships: plans, and who is on them
    Route::get('/memberships', [AdminMembershipPlanController::class, 'index'])->name('memberships.index');
    Route::get('/memberships/create', [AdminMembershipPlanController::class, 'create'])->name('memberships.create');
    Route::post('/memberships', [AdminMembershipPlanController::class, 'store'])->name('memberships.store');
    Route::get('/memberships/{plan}/edit', [AdminMembershipPlanController::class, 'edit'])->whereNumber('plan')->name('memberships.edit');
    Route::patch('/memberships/{plan}', [AdminMembershipPlanController::class, 'update'])->whereNumber('plan')->name('memberships.update');
    Route::delete('/memberships/{plan}', [AdminMembershipPlanController::class, 'destroy'])->whereNumber('plan')->name('memberships.destroy');
    Route::get('/subscriptions', [AdminSubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('/users/{user}/subscriptions', [AdminSubscriptionController::class, 'grant'])->whereNumber('user')->name('subscriptions.grant');
    Route::patch('/subscriptions/{subscription}', [AdminSubscriptionController::class, 'extend'])->whereNumber('subscription')->name('subscriptions.extend');
    Route::delete('/subscriptions/{subscription}', [AdminSubscriptionController::class, 'revoke'])->whereNumber('subscription')->name('subscriptions.revoke');

    // App content: announcements, banners, help articles, settings, releases
    Route::get('/announcements', [AdminAnnouncementController::class, 'index'])->name('announcements.index');
    Route::get('/announcements/create', [AdminAnnouncementController::class, 'create'])->name('announcements.create');
    Route::post('/announcements', [AdminAnnouncementController::class, 'store'])->name('announcements.store');
    Route::get('/announcements/{announcement}/edit', [AdminAnnouncementController::class, 'edit'])->whereNumber('announcement')->name('announcements.edit');
    Route::patch('/announcements/{announcement}', [AdminAnnouncementController::class, 'update'])->whereNumber('announcement')->name('announcements.update');
    Route::delete('/announcements/{announcement}', [AdminAnnouncementController::class, 'destroy'])->whereNumber('announcement')->name('announcements.destroy');
    Route::post('/announcements/{announcement}/push', [AdminAnnouncementController::class, 'push'])->whereNumber('announcement')->name('announcements.push');
    Route::get('/banners', [AdminBannerController::class, 'index'])->name('banners.index');
    Route::get('/banners/create', [AdminBannerController::class, 'create'])->name('banners.create');
    Route::post('/banners', [AdminBannerController::class, 'store'])->name('banners.store');
    Route::get('/banners/{banner}/edit', [AdminBannerController::class, 'edit'])->whereNumber('banner')->name('banners.edit');
    Route::patch('/banners/{banner}', [AdminBannerController::class, 'update'])->whereNumber('banner')->name('banners.update');
    Route::delete('/banners/{banner}', [AdminBannerController::class, 'destroy'])->whereNumber('banner')->name('banners.destroy');
    Route::get('/help-articles', [AdminHelpArticleController::class, 'index'])->name('help.index');
    Route::get('/help-articles/create', [AdminHelpArticleController::class, 'create'])->name('help.create');
    Route::post('/help-articles', [AdminHelpArticleController::class, 'store'])->name('help.store');
    Route::get('/help-articles/{article}/edit', [AdminHelpArticleController::class, 'edit'])->whereNumber('article')->name('help.edit');
    Route::patch('/help-articles/{article}', [AdminHelpArticleController::class, 'update'])->whereNumber('article')->name('help.update');
    Route::delete('/help-articles/{article}', [AdminHelpArticleController::class, 'destroy'])->whereNumber('article')->name('help.destroy');
    Route::get('/app-settings', [AdminAppSettingController::class, 'edit'])->name('settings.edit');
    Route::put('/app-settings', [AdminAppSettingController::class, 'update'])->name('settings.update');
    Route::get('/releases', [AdminAppReleaseController::class, 'index'])->name('releases.index');
    Route::put('/releases/policy/{platform}', [AdminAppReleaseController::class, 'updatePolicy'])->where('platform', 'android|ios')->name('releases.policy');
    Route::post('/releases', [AdminAppReleaseController::class, 'storeRelease'])->name('releases.store');
    Route::patch('/releases/{release}', [AdminAppReleaseController::class, 'toggleRelease'])->whereNumber('release')->name('releases.toggle');
    Route::delete('/releases/{release}', [AdminAppReleaseController::class, 'destroyRelease'])->whereNumber('release')->name('releases.destroy');

    Route::get('/foods', [AdminFoodController::class, 'index'])->name('foods.index');
    Route::get('/foods/create', [AdminFoodController::class, 'create'])->name('foods.create');
    Route::post('/foods', [AdminFoodController::class, 'store'])->name('foods.store');
    Route::get('/foods/{food}/edit', [AdminFoodController::class, 'edit'])->whereNumber('food')->name('foods.edit');
    Route::patch('/foods/{food}', [AdminFoodController::class, 'update'])->whereNumber('food')->name('foods.update');
    Route::delete('/foods/{food}', [AdminFoodController::class, 'destroy'])->whereNumber('food')->name('foods.destroy');
});
