@Tags(['live'])
library;

import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:dio/dio.dart' show Options;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/app/config/environment.dart';
import 'package:ironpulse/core/api/api_client.dart';
import 'package:ironpulse/core/api/api_error.dart';
import 'package:ironpulse/core/authentication/session_manager.dart';
import 'package:ironpulse/core/authentication/token_store.dart';
import 'package:ironpulse/core/policy/app_config.dart';
import 'package:ironpulse/core/providers.dart';
import 'package:ironpulse/core/sync/offline_queue.dart';
import 'package:ironpulse/core/sync/pending_op.dart';
import 'package:ironpulse/core/sync/pending_store.dart';
import 'package:ironpulse/core/storage/json_cache.dart';
import 'package:ironpulse/core/storage/secure_store.dart';
import 'package:ironpulse/core/utilities/clock.dart';
import 'package:ironpulse/core/device/device_identity.dart';
import 'package:ironpulse/features/authentication/data/auth_repository.dart';
import 'package:ironpulse/features/authentication/domain/auth_user.dart';
import 'package:ironpulse/features/dashboard/data/dashboard_repository.dart';
import 'package:ironpulse/features/exercise_library/data/exercise_repository.dart';
import 'package:ironpulse/features/exercise_library/domain/exercise.dart';
import 'package:ironpulse/features/membership/data/membership_repository.dart';
import 'package:ironpulse/features/nutrition/data/nutrition_repository.dart';
import 'package:ironpulse/features/posture/data/posture_repository.dart';
import 'package:ironpulse/features/posture/domain/geometry.dart';
import 'package:ironpulse/features/posture/domain/posture_analyzer.dart';
import 'package:ironpulse/features/posture/domain/posture_models.dart';
import 'package:ironpulse/features/progress/data/progress_repository.dart';
import 'package:ironpulse/features/workout_programs/data/plan_repository.dart';
import 'package:ironpulse/features/workout_sessions/data/session_repository.dart';
import 'package:ironpulse/features/workout_sessions/domain/session_engine.dart';
import 'package:ironpulse/features/workout_sessions/domain/workout_session.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';


/// Drives the app's repositories against a REAL running Laravel server and checks that every payload parses and every
/// request the app sends is accepted. This catches drift between the Dart models and the API that fake JSON cannot.
///
///   php artisan migrate:fresh --seed && php artisan serve --port=8123
///   flutter test test/live --dart-define=LIVE_API_URL=http://127.0.0.1:8123
const liveUrl = String.fromEnvironment('LIVE_API_URL');

class _Device implements DeviceIdentity {
  @override
  Future<String> installationId() async => 'live-test-installation-${DateTime.now().microsecondsSinceEpoch}';

  @override
  Future<Map<String, dynamic>> payload() async => {
        'installation_id': await installationId(),
        'platform': 'android',
        'name': 'Live test',
        'app_version': '1.0.0',
        'app_build': '1',
        'os_version': '14',
        'locale': 'en-US',
        'timezone': 'UTC',
      };
}

/// A real account on the live server, signed up and onboarded the way the app does it.
class _Live {
  _Live({required this.api, required this.auth, required this.cache, required this.config, required this.user, required this.sessions});

  final ApiClient api;
  final AuthRepository auth;
  final JsonCache cache;
  final AppConfig config;
  final AuthUser user;
  final SessionManager sessions;
}

Future<_Live> _signUp() async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    final store = MemorySecureStore();
    final tokens = TokenStore(store);
    const env = AppEnvironment(apiBaseUrl: liveUrl, platform: 'android', appVersion: '1.0.0', appBuild: '1');
    final sessions = SessionManager(store: tokens, refreshDio: buildDio(env));
    final api = ApiClient(env: env, tokens: sessions);
    final auth = AuthRepository(api: api, sessions: sessions, tokens: tokens, device: _Device());

    // ---- public config
    final config = AppConfig.fromJson((await api.get('/app/config', auth: false)).map);
    expect(config.versionStatus, VersionStatus.ok);
    expect(config.options('goal'), isNotEmpty, reason: 'profile_options reach the app');
    expect(config.entitlementCatalog.map((e) => e.key), contains('workouts'));
    expect(config.libraryChoices('muscle'), isNotEmpty);

    // ---- sign up with a unique address, then the onboarding form the app would show
    final email = 'live${Random().nextInt(1 << 30)}@example.com';
    late AuthUser user;
    try {
      user = await auth.register(name: 'Live Tester', email: email, password: 'correct-horse-battery-9');
    } on ApiError catch (e) {
      fail('register was refused: ${e.details}');
    }
    expect(user.onboarded, isFalse);
    String first(String key) => config.options(key).first.value.toString();
    user = await auth.completeOnboarding({
      'name': 'Live Tester',
      'age': 30,
      'sex': first('sex'),
      'height_cm': 178,
      'weight_kg': 80,
      'activity_level': first('activity_level'),
      'fitness_level': first('fitness_level'),
      'goal': first('goal'),
      'diet_preference': first('diet_preference'),
      'equipment': [for (final o in config.options('equipment')) o.value],
      'training_days': [1, 2, 3, 4, 5, 6],
    });
    expect(user.onboarded, isTrue);
    expect(await auth.me().then((u) => u.id), user.id);

    final cache = JsonCache(prefs, scope: '${user.id}');
    return _Live(api: api, auth: auth, cache: cache, config: config, user: user, sessions: sessions);
}

void main() {
  setUpAll(() => HttpOverrides.global = null); // flutter_test blocks real HTTP by default

  test('the app talks to the real API: sign-up, onboarding, browsing, a whole workout, results', () async {
    final live = await _signUp();
    final (api, auth, cache, config, user, sessions) = (live.api, live.auth, live.cache, live.config, live.user, live.sessions);
    final env = const AppEnvironment(apiBaseUrl: liveUrl, platform: 'android', appVersion: '1.0.0', appBuild: '1');

    // ---- membership
    final membership = (await MembershipRepository(api: api, cache: cache).membership()).data;
    expect(membership.entitlements.allows('workouts'), isTrue);
    final plans = (await MembershipRepository(api: api, cache: cache).plans()).data;
    expect(plans, isNotEmpty);

    // ---- dashboard
    final dash = (await DashboardRepository(api: api, cache: cache).load()).data;
    expect(dash.weekDays, hasLength(7));
    expect(dash.level, greaterThanOrEqualTo(1));

    // ---- programmes
    final planRepo = PlanRepository(api: api, cache: cache);
    final programmes = (await planRepo.programmes()).data;
    expect(programmes, isNotEmpty);
    final detail = (await planRepo.programme(programmes.first.id)).data;
    expect(detail.days, isNotEmpty);
    expect(detail.days.expand((d) => d.exercises), isNotEmpty);

    // ---- week plan and a day
    final week = (await planRepo.week()).data;
    expect(week.days, hasLength(7));
    final day = week.days.firstWhere((d) => d.isTraining && (d.isToday || d.status == 'missed' || d.status == 'today'), orElse: () => week.days.firstWhere((d) => d.isTraining && !d.date.isAfter(DateTime.now())));
    final dayDetail = (await planRepo.day(day.weekday, date: day.date)).data;
    expect(dayDetail.exercises, isNotEmpty);
    expect(dayDetail.exercises.first.restSeconds, greaterThan(0));

    // ---- library
    final library = ExerciseRepository(api: api, cache: cache);
    final page = (await library.page(const LibraryFilters(), 1)).data;
    expect(page.items, isNotEmpty);
    expect(page.lastPage, greaterThanOrEqualTo(1));
    final filtered = (await library.page(const LibraryFilters(muscle: 'chest'), 1)).data;
    expect(filtered.items.every((e) => e.muscleGroup == 'chest'), isTrue);
    final one = (await library.exercise(page.items.first.slug)).data;
    expect(one.name, page.items.first.name);

    // ---- nutrition: targets on first view, search, the server's calculation, logging, editing, removing
    final nutrition = NutritionRepository(api: api, cache: cache);
    var fuelDay = (await nutrition.day()).data;
    expect(fuelDay.targets, isNotNull, reason: 'targets exist from the first look at the day');
    expect(fuelDay.meals.map((m) => m.meal), containsAll(['breakfast', 'lunch', 'dinner']));

    final foods = await nutrition.search('chicken');
    expect(foods, isNotEmpty);
    final food = foods.first;
    final calc = await nutrition.calculate(foodId: food.id, quantity: 150, unit: 'grams');
    expect(calc.grams, 150);
    // the server's own arithmetic: 150 g x (protein per 100 g) / 100
    expect(calc.macros.proteinG, closeTo(150 * food.per100g.proteinG / 100, 0.11));
    final viaServing = food.servings.isEmpty ? null : await nutrition.calculate(foodId: food.id, quantity: 2, unit: 'serving', servingId: food.defaultServing!.id);
    if (viaServing != null) expect(viaServing.grams, closeTo(2 * food.defaultServing!.grams, 0.01));

    final today = DateTime.now();
    final entry = await nutrition.log(foodId: food.id, meal: 'lunch', date: today, quantity: 150, unit: 'grams');
    expect(entry.macros.proteinG, closeTo(calc.macros.proteinG, 0.11), reason: 'what is logged equals what was calculated');
    fuelDay = (await nutrition.day(today)).data;
    expect(fuelDay.totals.calories, closeTo(entry.macros.calories, 0.6));
    expect(fuelDay.meals.firstWhere((m) => m.meal == 'lunch').entries.single.name, entry.name);
    expect((await nutrition.recent()).map((f) => f.id), contains(food.id));

    await nutrition.updateLog(entry.id, quantity: 300, unit: 'grams');
    fuelDay = (await nutrition.day(today)).data;
    expect(fuelDay.meals.firstWhere((m) => m.meal == 'lunch').entries.single.grams, 300);

    await nutrition.setFavorite(food.id, true);
    expect((await nutrition.favorites()).map((f) => f.id), contains(food.id));
    await nutrition.setFavorite(food.id, false);
    expect((await nutrition.favorites()).map((f) => f.id), isNot(contains(food.id)));

    await nutrition.deleteLog(entry.id);
    expect((await nutrition.day(today)).data.totals.calories, 0);

    // ---- a whole workout
    final repo = SessionRepository(api: api, cache: cache);
    var session = await repo.start(weekday: day.weekday, date: day.date);
    expect(session.isOpen, isTrue);
    expect(session.exercises, isNotEmpty);
    expect(session.exercises.first.suggested.reason, isNotNull);

    // the active workout is the same one
    final active = await repo.active();
    expect(active?.id, session.id);

    final now = DateTime.now().toUtc();
    var engine = SessionEngine.initial(stepsOfSession(session));
    expect(engine.phase, WorkPhase.intro);
    engine = SessionEngine.startSet(engine, now);

    final exercise = session.exercises.first;
    final result = await repo.logSet(session.id, SetEntry(sessionExerciseId: exercise.id, setNumber: 1, weightKg: exercise.isTimed ? null : 40, reps: exercise.isTimed ? 0 : 8, durationSeconds: exercise.isTimed ? 30 : null));
    expect(result.set.number, 1);

    session = session.withExercise(0, exercise.copyWith(sets: [result.set]));
    engine = SessionEngine.setLogged(engine, stepsOfSession(session), now);

    // the server accepts exactly the state the engine produces, in every phase
    await repo.saveState(session.id, engine.toServerJson(startedAt: session.startedAt, now: now));
    final paused = SessionEngine.pause(engine, now.add(const Duration(seconds: 5)));
    await api.put('/workout-sessions/${session.id}/state', body: {'state': paused.toServerJson(startedAt: session.startedAt, now: now.add(const Duration(seconds: 5)))});
    final reloaded = await repo.load(session.id);
    final stored = EngineState.fromJson(reloaded.clientState);
    expect(stored, isNotNull, reason: 'the server returns the saved state in a shape the app reads back');
    expect(stored!.phase, WorkPhase.paused);
    expect(reloaded.exercises.first.sets.single.number, 1);

    // the same set sent again (a retry) does not create a second set
    final again = await repo.logSet(session.id, SetEntry(sessionExerciseId: exercise.id, setNumber: 1, weightKg: exercise.isTimed ? null : 40, reps: exercise.isTimed ? 0 : 8, durationSeconds: exercise.isTimed ? 30 : null));
    expect(again.set.number, 1);
    expect((await repo.load(session.id)).exercises.first.sets, hasLength(1));

    await repo.finish(session.id, activeSeconds: 600);
    final summary = await repo.summary(session.id);
    // The app reports 10 minutes but the session is seconds old: the server caps the claim at the real elapsed time.
    expect(summary.activeSeconds, lessThanOrEqualTo(600));
    expect(summary.activeSeconds, greaterThanOrEqualTo(0));
    expect(summary.setsCompleted, 1);
    expect(summary.xpEarned, greaterThan(0));

    // ---- finished work shows on the dashboard / week
    final after = (await planRepo.week()).data;
    expect(after.done, greaterThanOrEqualTo(1));

    // ---- progress: a measurement, the server's own numbers, records, strength curve and badges
    final progress = ProgressRepository(api: api, cache: cache);
    await progress.saveMeasurement(date: DateTime.now(), weightKg: 80.5, bodyFatPct: 18.2, waistCm: 85);
    final measurements = (await progress.measurements()).data;
    expect(measurements, hasLength(1));
    expect(measurements.single.weightKg, 80.5);
    final overview = (await progress.summary()).data;
    expect(overview.latest?.weightKg, 80.5);
    expect(overview.weightPoints, hasLength(1));
    if (overview.bmi != null) expect(overview.bmiCategory, isNotNull);
    final records = (await progress.records()).data; // one logged set: it may or may not count as a record yet
    for (final r in records) {
      expect(r.slug, isNotEmpty);
    }
    if (records.isNotEmpty) expect((await progress.strength(records.first.slug)).data.points, isNotNull);
    final badges = (await progress.achievements()).data;
    expect(badges.badges, isNotEmpty);
    expect(badges.level, greaterThanOrEqualTo(1));
    expect(badges.xpTotal, greaterThan(0), reason: 'finishing a workout earned XP');
    await progress.deleteMeasurement(measurements.single.id);
    expect((await progress.measurements()).data, isEmpty);

    // ---- camera coach: the server's own rule, run by the Dart engine over recorded landmarks, saved with the real validator
    final posture = PostureRepository(api: api, cache: cache);
    final coached = (await posture.exercises()).data;
    expect(coached, isNotEmpty);
    expect(coached.map((e) => e.slug), contains('barbell-squat'));
    for (final e in coached) {
      // every rule the server serves must be one this app can run
      final rule = (await posture.rule(e.slug)).data;
      expect(rule.compiled.measures, isNotEmpty, reason: e.slug);
    }
    final squat = (await posture.rule('barbell-squat')).data;
    expect(squat.guidance.steps, isNotEmpty);
    final fixture = jsonDecode(utf8.decode(gzip.decode(File('test/fixtures/posture_parity.json.gz').readAsBytesSync()))) as Map<String, dynamic>;
    final scenario = (fixture['scenarios'] as List).cast<Map<String, dynamic>>().firstWhere((s) => (s['head'] as Map)['id'] == 'squat_clean');
    final head = scenario['head'] as Map<String, dynamic>;
    final analyzer = PostureAnalyzer(squat.compiled, aspect: (head['aspect'] as num).toDouble());
    final times = (head['times'] as List).cast<num>();
    final frames = scenario['frames'] as List;
    for (var i = 0; i < frames.length; i++) {
      final row = frames[i] as List?;
      analyzer.update(
        row == null ? null : [for (var k = 0; k < landmarkCount; k++) Landmark((row[k * 3] as int) / 1e4, (row[k * 3 + 1] as int) / 1e4, visibility: (row[k * 3 + 2] as int) / 100)],
        times[i].toDouble(),
      );
    }
    analyzer.flush(times.last.toDouble());
    final coachSummary = analyzer.summary(times.last / 1000);
    expect(coachSummary.totalReps, 3);
    final postureSet = FinishedSet(summary: coachSummary, startedAt: DateTime.now().toUtc(), clientUuid: const Uuid().v4(), avgFps: 15);
    final postureSaved = await posture.save(squat, postureSet);
    expect(postureSaved.created, isTrue, reason: 'the real validator accepts what the Dart engine produces');
    final postureAgain = await posture.save(squat, postureSet);
    expect(postureAgain.created, isFalse, reason: 'sending the same set again is recognised, not duplicated');

    // ---- the server refuses what it should
    await expectLater(api.get('/me', auth: false), throwsA(isA<ApiError>().having((e) => e.kind, 'kind', ApiErrorKind.unauthenticated)));
    await expectLater(repo.start(weekday: 7, date: DateTime.now().add(const Duration(days: 30))), throwsA(isA<ApiError>()));

    // ---- sign out revokes the token on the server
    final token = sessions.current!.accessToken;
    await auth.logout();
    final raw = buildDio(env);
    final res = await raw.get<dynamic>('/me', options: Options(headers: {'Authorization': 'Bearer $token'}));
    expect(res.statusCode, 401);
  }, skip: liveUrl.isEmpty ? 'set --dart-define=LIVE_API_URL to run against a live server' : false, timeout: const Timeout(Duration(minutes: 2)));

  test('the offline queue replays what a phone held through the real /sync/batch: in order, once, with conflicts and refusals reported', () async {
    final live = await _signUp();
    final api = live.api;
    final planRepo = PlanRepository(api: api, cache: live.cache);
    final week = (await planRepo.week()).data;
    final day = week.days.firstWhere((d) => d.isTraining && !d.date.isAfter(DateTime.now()));
    final repo = SessionRepository(api: api, cache: live.cache);
    final session = await repo.start(weekday: day.weekday, date: day.date);
    final first = session.exercises[0];
    final second = session.exercises[1];

    final store = MemoryPendingStore();
    final container = ProviderContainer(overrides: [
      queueScopeProvider.overrideWithValue('${live.user.id}'),
      pendingStoreProvider.overrideWithValue(store),
      apiClientProvider.overrideWithValue(api),
      clockProvider.overrideWithValue(() => DateTime.now().toUtc()),
    ]);
    addTearDown(container.dispose);
    final queue = container.read(offlineQueueProvider.notifier);

    // what a phone with no signal holds after a workout: two sets, a skip, a body measurement, a camera set, the finish
    SetEntry entry(SessionExercise e, int n, double kg, int reps) => SetEntry(sessionExerciseId: e.id, setNumber: n, weightKg: kg, reps: reps);
    await queue.enqueue(OpTypes.setLog, SessionRepository.setPayload(session.id, entry(first, 1, 40, 8)), label: 'set 1', flushNow: false);
    await queue.enqueue(OpTypes.setLog, SessionRepository.setPayload(session.id, entry(first, 2, 40, 7)), label: 'set 2', flushNow: false);
    await queue.enqueue(OpTypes.exerciseSkip, {'session_id': session.id, 'session_exercise_id': second.id}, label: 'skip', flushNow: false);
    await queue.enqueue(OpTypes.measurementSave, ProgressRepository.measurementPayload(date: DateTime.now(), weightKg: 81.2), label: 'weigh-in', flushNow: false);
    await queue.enqueue(OpTypes.workoutFinish, {'session_id': session.id, 'active_seconds': 600}, label: 'finish', flushNow: false);
    // one the server must refuse: a set for a workout that is not theirs
    await queue.enqueue(OpTypes.setLog, SessionRepository.setPayload(session.id + 100000, entry(first, 1, 40, 8)), label: 'not mine', flushNow: false);
    expect(container.read(offlineQueueProvider).waiting, hasLength(6));

    final report = await queue.flush();

    expect(report.offline, isFalse);
    expect(report.applied, 5, reason: 'everything the server could accept');
    expect(report.rejected, 1, reason: 'the set for a workout that is not theirs');
    final state = container.read(offlineQueueProvider);
    expect(state.rejected.single.label, 'not mine');
    expect(state.rejected.single.errorCode, 'not_found');
    expect(state.waiting, isEmpty);

    // the server really holds the results, in the order they were made
    final after = await repo.load(session.id);
    expect(after.status, 'completed');
    expect(after.exercises.first.sets.map((s) => s.number), [1, 2]);
    expect(after.exercises[1].status, 'skipped');
    expect((await repo.summary(session.id)).setsCompleted, 2);

    // a replay of the same change (the answer was lost on the way back) is recognised by its op id and changes nothing
    const replayId = 'c0ffee00-1111-4222-8333-444455556666';
    Future<Map<String, dynamic>> sendWater() async => Map<String, dynamic>.from(
          ((await api.post('/sync/batch', body: {'operations': [{'op_id': replayId, 'type': 'water.add', 'payload': {'amount_ml': 250}}]})).map['results'] as List).single as Map,
        );
    expect((await sendWater())['status'], 'applied');
    final again = await sendWater();
    expect(again['status'], 'duplicate');
    expect(again['original_status'], 'applied');

    await queue.discard(state.rejected.single.opId);
    expect(container.read(offlineQueueProvider).isEmpty, isTrue);

    // deleting the account from the app (a store requirement): a wrong password is refused, the right one deletes it
    await expectLater(live.auth.deleteAccount(password: 'not-the-password', guest: false), throwsA(isA<ApiError>().having((e) => e.fieldErrors.containsKey('password'), 'password error', true)));
    expect((await live.auth.me()).id, live.user.id, reason: 'still there after a wrong password');
    await live.auth.deleteAccount(password: 'correct-horse-battery-9', guest: false);
    await expectLater(live.api.get('/me'), throwsA(isA<ApiError>()), reason: 'the account and its token are gone');
  }, skip: liveUrl.isEmpty ? 'set --dart-define=LIVE_API_URL to run against a live server' : false, timeout: const Timeout(Duration(minutes: 2)));
}

List<ExerciseStep> stepsOfSession(WorkoutSession s) => [for (final e in s.exercises) ExerciseStep(targetSets: e.targetSets, loggedSets: e.sets.length, skipped: e.isSkipped, restSeconds: e.restSeconds)];
