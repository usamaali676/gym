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
import 'package:ironpulse/core/providers.dart';
import 'package:ironpulse/core/storage/secure_store.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'fake_adapter.dart';

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

  static Future<TestWorld> create({Map<String, Object> prefs = const {}}) async {
    SharedPreferences.setMockInitialValues(prefs);
    return TestWorld._(FakeServer(), MemorySecureStore(), await SharedPreferences.getInstance());
  }

  late final List<Override> overrides = [
    appEnvironmentProvider.overrideWithValue(testEnv),
    sharedPreferencesProvider.overrideWithValue(prefs),
    secureStoreProvider.overrideWithValue(store),
    deviceIdentityProvider.overrideWithValue(FakeDeviceIdentity()),
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
    final c = ProviderContainer(overrides: overrides);
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
  }
}

/// Lets queued microtasks and timers run (providers kick work off with Future.microtask).
Future<void> settle() async {
  for (var i = 0; i < 60; i++) {
    await Future<void>.delayed(Duration.zero);
  }
}
