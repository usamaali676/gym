@Tags(['live'])
library;

import 'dart:io';
import 'dart:math';

import 'package:dio/dio.dart' show Options;
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/app/config/environment.dart';
import 'package:ironpulse/core/api/api_client.dart';
import 'package:ironpulse/core/api/api_error.dart';
import 'package:ironpulse/core/authentication/session_manager.dart';
import 'package:ironpulse/core/authentication/token_store.dart';
import 'package:ironpulse/core/policy/app_config.dart';
import 'package:ironpulse/core/storage/json_cache.dart';
import 'package:ironpulse/core/storage/secure_store.dart';
import 'package:ironpulse/core/device/device_identity.dart';
import 'package:ironpulse/features/authentication/data/auth_repository.dart';
import 'package:ironpulse/features/authentication/domain/auth_user.dart';
import 'package:ironpulse/features/dashboard/data/dashboard_repository.dart';
import 'package:ironpulse/features/exercise_library/data/exercise_repository.dart';
import 'package:ironpulse/features/exercise_library/domain/exercise.dart';
import 'package:ironpulse/features/membership/data/membership_repository.dart';
import 'package:ironpulse/features/workout_programs/data/plan_repository.dart';
import 'package:ironpulse/features/workout_sessions/data/session_repository.dart';
import 'package:ironpulse/features/workout_sessions/domain/session_engine.dart';
import 'package:ironpulse/features/workout_sessions/domain/workout_session.dart';
import 'package:shared_preferences/shared_preferences.dart';


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

void main() {
  setUpAll(() => HttpOverrides.global = null); // flutter_test blocks real HTTP by default

  test('the app talks to the real API: sign-up, onboarding, browsing, a whole workout, results', () async {
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
}

List<ExerciseStep> stepsOfSession(WorkoutSession s) => [for (final e in s.exercises) ExerciseStep(targetSets: e.targetSets, loggedSets: e.sets.length, skipped: e.isSkipped, restSeconds: e.restSeconds)];
