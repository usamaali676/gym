import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart' show addTearDown;
import 'package:ironpulse/app/config/environment.dart';
import 'package:ironpulse/core/api/api_client.dart';
import 'package:ironpulse/core/authentication/session.dart';
import 'package:ironpulse/core/authentication/session_manager.dart';
import 'package:ironpulse/core/authentication/token_store.dart';
import 'package:ironpulse/core/device/device_identity.dart';
import 'package:ironpulse/core/notifications/local_alerts.dart';
import 'package:ironpulse/core/providers.dart';
import 'package:ironpulse/core/storage/secure_store.dart';
import 'package:ironpulse/core/utilities/clock.dart';
import 'package:ironpulse/core/utilities/device_feedback.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'fake_adapter.dart';
import 'fake_alerts.dart';

const testEnv = AppEnvironment(apiBaseUrl: 'https://gym.test', platform: 'android', appVersion: '1.0.0', appBuild: '1');

class FakeDeviceIdentity implements DeviceIdentity {
  @override
  Future<String> installationId() async => 'test-installation-0001';

  @override
  Future<Map<String, dynamic>> payload() async => {
        'installation_id': 'test-installation-0001',
        'platform': 'android',
        'name': 'Test Phone',
        'app_version': '1.0.0',
        'app_build': '1',
        'os_version': '14',
        'locale': 'en-US',
        'timezone': 'Europe/London',
      };
}

/// A tiny scripted server: register routes, everything else answers 404 in the API's error shape.
class FakeServer {
  FakeServer() {
    adapter = FakeAdapter((o) {
      final route = routes['${o.method} ${o.path}'];
      return route != null ? route(o) : FakeReply.json(404, errorJson('not_found', 'No route in the fake server: ${o.method} ${o.path}'));
    });
  }

  late final FakeAdapter adapter;
  final Map<String, Handler> routes = {};

  void on(String method, String path, Handler handler) => routes['$method $path'] = handler;

  /// A route that always answers the same JSON.
  void reply(String method, String path, int status, Object? body) => on(method, path, (_) => FakeReply.json(status, body));

  List<String> get log => adapter.requests.map((r) => '${r.method} ${r.path}').toList();
}

/// Everything the app needs, wired to in-memory fakes: no network, no platform channels.
class TestWorld {
  TestWorld._(this.server, this.store, this.prefs);

  final FakeServer server;
  final MemorySecureStore store;
  final SharedPreferences prefs;

  /// Time the workout engine sees. Advance it to simulate the phone sitting in a pocket.
  final TestClock clock = TestClock();
  final SilentDeviceFeedback feedback = SilentDeviceFeedback();
  final FakeLocalAlerts alerts = FakeLocalAlerts();

  static Future<TestWorld> create({Map<String, Object> prefs = const {}}) async {
    SharedPreferences.setMockInitialValues(prefs);
    return TestWorld._(FakeServer(), MemorySecureStore(), await SharedPreferences.getInstance());
  }

  late final List<Override> overrides = [
    appEnvironmentProvider.overrideWithValue(testEnv),
    sharedPreferencesProvider.overrideWithValue(prefs),
    secureStoreProvider.overrideWithValue(store),
    deviceIdentityProvider.overrideWithValue(FakeDeviceIdentity()),
    clockProvider.overrideWithValue(clock.call),
    deviceFeedbackProvider.overrideWithValue(feedback),
    localAlertsProvider.overrideWithValue(alerts),
    sessionManagerProvider.overrideWith((ref) {
      final m = SessionManager(store: ref.watch(tokenStoreProvider), refreshDio: buildDio(testEnv, adapter: server.adapter));
      ref.onDispose(m.dispose);
      return m;
    }),
    apiClientProvider.overrideWith((ref) {
      final c = ApiClient(env: testEnv, tokens: ref.watch(sessionManagerProvider), adapter: server.adapter, sleep: (_) async {});
      ref.onDispose(c.dispose);
      return c;
    }),
  ];

  ProviderContainer container() {
    final c = ProviderContainer(overrides: overrides, retry: noAutoRetry);
    addTearDown(c.dispose);
    return c;
  }

  /// Pretend the member signed in earlier: a stored session (and optionally a cached account).
  Future<void> storeSession({String token = '5|ipt_stored', Map<String, dynamic>? cachedUser}) async {
    final tokens = TokenStore(store);
    await tokens.write(Session(
      accessToken: token,
      expiresAt: DateTime.now().toUtc().add(const Duration(days: 40)),
      refreshAfter: DateTime.now().toUtc().add(const Duration(days: 10)),
      deviceId: 1,
    ));
    if (cachedUser != null) await tokens.writeUser(cachedUser);
  }

  /// The usual happy-path routes for an onboarded member.
  void serveSignedInMember({Map<String, dynamic>? user, Map<String, dynamic>? config}) {
    server.reply('GET', '/app/config', 200, {'data': config ?? configJson()});
    server.reply('GET', '/me', 200, {'data': user ?? userJson()});
    server.reply('GET', '/dashboard', 200, {'data': dashboardJson()});
    server.reply('GET', '/membership', 200, {'data': membershipJson()});
    server.reply('GET', '/membership/plans', 200, {'data': [planJson()]});
    server.on('GET', '/plan/week', (o) => FakeReply.json(200, {'data': weekPlanJson()}));
    server.on('GET', '/plan/days/3', (o) => FakeReply.json(200, {'data': dayDetailJson()}));
    server.reply('GET', '/programs', 200, {'data': [programmeJson(), programmeJson(id: 2, name: 'Hypertrophy Plus', selected: false, locked: true, entitlement: 'programs.premium')]});
    server.reply('GET', '/programs/1', 200, {'data': programmeDetailJson()});
    server.on('GET', '/exercises', (o) => exercisePageReply(o));
    server.reply('GET', '/nutrition/day', 200, {'data': nutritionDayJson()});
    server.reply('GET', '/progress/summary', 200, {'data': progressSummaryJson()});
    server.reply('GET', '/progress/measurements', 200, {'data': [measurementJson(7, '2026-10-12', 80.2), measurementJson(6, '2026-10-04', 80.9, notes: 'After holiday')]});
    server.reply('GET', '/progress/records', 200, {'data': recordsJson()});
    server.reply('GET', '/achievements', 200, {'data': achievementsJson()});
  }
}

/// Lets queued microtasks and timers run (providers kick work off with Future.microtask).
Future<void> settle() async {
  for (var i = 0; i < 60; i++) {
    await Future<void>.delayed(Duration.zero);
  }
}

/// A controllable wall clock for workout timers.
class TestClock {
  TestClock([DateTime? start]) : now = start ?? DateTime.utc(2026, 10, 12, 18);
  DateTime now;

  void advance(Duration d) => now = now.add(d);
  DateTime call() => now;
}

/// A tiny in-memory workout backend: logs sets, remembers the saved state, finishes and abandons.
class FakeWorkoutBackend {
  FakeWorkoutBackend(this.server, {Map<String, dynamic>? session}) : session = session ?? sessionJson() {
    server.on('POST', '/workout-sessions', (o) => FakeReply.json(201, {'data': this.session}));
    server.on('GET', '/workout-sessions/1', (o) => FakeReply.json(200, {'data': this.session}));
    server.on('GET', '/workout-sessions/active', (o) => FakeReply.json(200, {'data': this.session}));
    server.on('POST', '/workout-sessions/1/sets', (o) {
      final body = Map<String, dynamic>.from(o.data as Map);
      setPosts.add(body);
      if (failSets > 0) {
        failSets--;
        return FakeReply.json(500, errorJson('server_error', 'The set could not be saved.'));
      }
      final exercises = List<Map<String, dynamic>>.from((this.session['exercises'] as List).map((e) => Map<String, dynamic>.from(e as Map)));
      final i = exercises.indexWhere((e) => e['id'] == body['session_exercise_id']);
      final sets = List<Map<String, dynamic>>.from(exercises[i]['sets'] as List);
      final set = {'id': 500 + setPosts.length, 'number': body['set_number'], 'weight_kg': body['weight_kg'], 'reps': body['reps'], 'duration_seconds': body['duration_seconds'], 'rpe': null, 'is_warmup': false, 'is_pr': setPosts.length == 2, 'completed_at': '2026-10-12T18:05:00+00:00'};
      sets.removeWhere((s) => s['number'] == body['set_number']);
      sets.add(set);
      exercises[i] = {...exercises[i], 'sets': sets, 'status': sets.length >= (exercises[i]['target_sets'] as int) ? 'done' : 'pending'};
      this.session = {...this.session, 'exercises': exercises};
      return FakeReply.json(201, {'data': {'set': set, 'exercise_status': exercises[i]['status'], 'is_pr': set['is_pr'], 'personal_records': [], 'xp': 10, 'totals': {}}});
    });
    server.on('PUT', '/workout-sessions/1/state', (o) {
      statePuts.add(Map<String, dynamic>.from((o.data as Map)['state'] as Map));
      return FakeReply.json(200, {'data': {'applied': true}});
    });
    server.on('POST', '/workout-sessions/1/exercises/1/skip', (o) {
      skips.add(1);
      final exercises = List<Map<String, dynamic>>.from((this.session['exercises'] as List).map((e) => Map<String, dynamic>.from(e as Map)));
      exercises[0]['status'] = 'skipped';
      this.session = {...this.session, 'exercises': exercises};
      return FakeReply.json(200, {'data': {'ok': true}});
    });
    server.on('GET', '/workout-sessions/1/exercises/1/alternatives', (o) {
      alternativeGets++;
      return alternativesReply ?? FakeReply.json(200, {'data': alternativeOptions});
    });
    server.on('POST', '/workout-sessions/1/exercises/1/swap', (o) {
      final body = Map<String, dynamic>.from(o.data as Map);
      swapPosts.add(body);
      if (swapRefusal != null) return FakeReply.json(422, errorJson('validation_failed', swapRefusal!, details: {'exercise_id': [swapRefusal!]}));
      final chosen = alternativeOptions.firstWhere((a) => a['id'] == body['exercise_id']);
      final exercises = List<Map<String, dynamic>>.from((this.session['exercises'] as List).map((e) => Map<String, dynamic>.from(e as Map)));
      exercises[0] = {...exercises[0], 'name': chosen['name'], 'slug': chosen['slug'], 'swapped_from': exercises[0]['swapped_from'] ?? exercises[0]['name']};
      this.session = {...this.session, 'exercises': exercises};
      return FakeReply.json(200, {'data': this.session});
    });
    server.on('POST', '/workout-sessions/1/finish', (o) {
      finishPosts.add(Map<String, dynamic>.from(o.data as Map));
      return FakeReply.json(200, {'data': {'ok': true, 'session_id': 1, 'status': 'completed'}});
    });
    server.on('POST', '/workout-sessions/1/abandon', (o) {
      abandons++;
      return FakeReply.json(200, {'data': {'ok': true, 'status': 'abandoned'}});
    });
    server.reply('GET', '/workout-sessions/1/summary', 200, {'data': summaryJson()});
  }

  final FakeServer server;
  Map<String, dynamic> session;
  final List<Map<String, dynamic>> setPosts = [];
  final List<Map<String, dynamic>> statePuts = [];
  final List<int> skips = [];
  final List<Map<String, dynamic>> finishPosts = [];
  int abandons = 0;
  int alternativeGets = 0;
  final List<Map<String, dynamic>> swapPosts = [];

  /// What the server offers instead of the first exercise; replace [alternativesReply] to make the call fail.
  List<Map<String, dynamic>> alternativeOptions = [
    {'id': 901, 'slug': 'dumbbell-bench-press', 'name': 'Dumbbell bench press', 'kind': 'alternative', 'mechanic': 'compound', 'difficulty': 'beginner', 'equipment': ['dumbbell']},
    {'id': 902, 'slug': 'machine-chest-press', 'name': 'Machine chest press', 'kind': 'alternative', 'mechanic': 'compound', 'difficulty': 'beginner', 'equipment': ['machine']},
  ];
  FakeReply? alternativesReply;

  /// When set, the swap is refused with this message.
  String? swapRefusal;

  /// The next N set requests fail with a server error.
  int failSets = 0;
}
