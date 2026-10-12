import 'dart:math';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/app/config/environment.dart';
import 'package:ironpulse/core/api/api_client.dart';
import 'package:ironpulse/core/api/api_error.dart';

import '../support/fake_adapter.dart';

const env = AppEnvironment(apiBaseUrl: 'https://gym.test', platform: 'android', appVersion: '1.2.3', appBuild: '45');

class FakeTokens implements AuthTokenSource {
  FakeTokens(this.token, {this.replacement});
  String? token;
  String? replacement;
  final List<String> rejected = [];

  @override
  Future<String?> accessToken() async => token;

  @override
  Future<String?> onUnauthorized(String usedToken) async {
    rejected.add(usedToken);
    if (replacement != null) {
      token = replacement;
      return replacement;
    }
    token = null;
    return null;
  }
}

({ApiClient client, FakeAdapter adapter, List<Duration> sleeps, FakeTokens tokens}) build(Handler handler, {String? token = 'tok-1', String? replacement}) {
  final adapter = FakeAdapter(handler);
  final sleeps = <Duration>[];
  final tokens = FakeTokens(token, replacement: replacement);
  final client = ApiClient(env: env, tokens: tokens, adapter: adapter, sleep: (d) async => sleeps.add(d), random: Random(1));
  return (client: client, adapter: adapter, sleeps: sleeps, tokens: tokens);
}

void main() {
  test('sends the app headers and the bearer token, and unwraps the data envelope', () async {
    final t = build((_) => FakeReply.json(200, {'data': {'ok': true}, 'meta': {'page': 1}}));

    final res = await t.client.get('/me');

    final req = t.adapter.requests.single;
    expect(req.uri.toString(), 'https://gym.test/api/v1/me');
    expect(req.headers['Authorization'], 'Bearer tok-1');
    expect(req.headers['X-App-Platform'], 'android');
    expect(req.headers['X-App-Version'], '1.2.3');
    expect(req.headers['Accept'], 'application/json');
    expect(res.map, {'ok': true});
    expect(res.meta, {'page': 1});
  });

  test('sends no Authorization header when signed out or when auth is off', () async {
    final t = build((_) => FakeReply.json(200, {'data': {}}), token: null);
    await t.client.get('/app/config');
    expect(t.adapter.requests.single.headers.containsKey('Authorization'), isFalse);

    final signedIn = build((_) => FakeReply.json(200, {'data': {}}));
    await signedIn.client.get('/app/config', auth: false);
    expect(signedIn.adapter.requests.single.headers.containsKey('Authorization'), isFalse);
  });

  group('retry rules', () {
    test('GET is retried on a gateway error with growing, jittered delays, then succeeds', () async {
      var n = 0;
      final t = build((_) => ++n < 3 ? FakeReply.json(502, null) : FakeReply.json(200, {'data': {'n': n}}));

      final res = await t.client.get('/programs');

      expect(res.map['n'], 3);
      expect(t.adapter.requests, hasLength(3));
      expect(t.sleeps, hasLength(2));
      // 400 ms and 800 ms, each within ±25 %.
      expect(t.sleeps[0].inMilliseconds, inInclusiveRange(300, 500));
      expect(t.sleeps[1].inMilliseconds, inInclusiveRange(600, 1000));
    });

    test('gives up after three attempts and reports the last error', () async {
      final t = build((_) => FakeReply.json(504, errorJson('http_504', 'Gateway timeout')));
      await expectLater(t.client.get('/programs'), throwsA(isA<ApiError>().having((e) => e.status, 'status', 504)));
      expect(t.adapter.requests, hasLength(3));
    });

    test('a write without an Idempotency-Key is never retried', () async {
      final t = build((_) => FakeReply.json(503, errorJson('service_unavailable', 'x')));
      await expectLater(t.client.post('/food-logs', body: {'a': 1}), throwsA(isA<ApiError>()));
      expect(t.adapter.requests, hasLength(1));
    });

    test('a write with an Idempotency-Key is retried with the same key', () async {
      var n = 0;
      final t = build((_) => ++n < 3 ? FakeReply.fail(DioExceptionType.connectionError) : FakeReply.json(201, {'data': {'ok': true}}));

      await t.client.post('/food-logs', body: {'a': 1}, idempotent: true);

      final keys = t.adapter.requests.map((r) => r.headers['Idempotency-Key']).toSet();
      expect(t.adapter.requests, hasLength(3));
      expect(keys, hasLength(1));
      expect(keys.single, isNotNull);
    });

    test('an explicit key (from the offline queue) is used as given', () async {
      final t = build((_) => FakeReply.json(200, {'data': {}}));
      await t.client.post('/sync/batch', body: {}, idempotencyKey: 'op-123456789');
      expect(t.adapter.requests.single.headers['Idempotency-Key'], 'op-123456789');
    });

    test('client errors (400, 404, 422) are not retried', () async {
      for (final status in [400, 404, 422]) {
        final t = build((_) => FakeReply.json(status, errorJson('x', 'x')));
        await expectLater(t.client.get('/x'), throwsA(isA<ApiError>()));
        expect(t.adapter.requests, hasLength(1), reason: 'status $status');
      }
    });

    test('429 is retried only for a short Retry-After', () async {
      var n = 0;
      final shortWait = build((_) => ++n < 2 ? FakeReply.json(429, errorJson('rate_limited', 'x'), headers: {'retry-after': '2'}) : FakeReply.json(200, {'data': {}}));
      await shortWait.client.get('/x');
      expect(shortWait.sleeps.single, const Duration(seconds: 2));

      final longWait = build((_) => FakeReply.json(429, errorJson('rate_limited', 'x'), headers: {'retry-after': '60'}));
      await expectLater(longWait.client.get('/x'), throwsA(isA<ApiError>().having((e) => e.kind, 'kind', ApiErrorKind.rateLimited)));
      expect(longWait.adapter.requests, hasLength(1));
    });

    test('a timeout on a GET is retried, a timeout on a bare POST is not', () async {
      var n = 0;
      final get = build((_) => ++n < 2 ? FakeReply.fail(DioExceptionType.receiveTimeout) : FakeReply.json(200, {'data': {}}));
      await get.client.get('/x');
      expect(get.adapter.requests, hasLength(2));

      final post = build((_) => FakeReply.fail(DioExceptionType.receiveTimeout));
      await expectLater(post.client.post('/x', body: {}), throwsA(isA<ApiError>().having((e) => e.kind, 'kind', ApiErrorKind.timeout)));
      expect(post.adapter.requests, hasLength(1));
    });
  });

  group('policy signals', () {
    test('426 raises an upgrade signal', () async {
      final t = build((_) => FakeReply.json(426, errorJson('upgrade_required', 'Update.', details: {'min_supported': '2.0.0'})));
      final signals = <PolicySignal>[];
      t.client.signals.listen(signals.add);

      await expectLater(t.client.get('/dashboard'), throwsA(isA<ApiError>().having((e) => e.kind, 'kind', ApiErrorKind.upgradeRequired)));
      await Future<void>.delayed(Duration.zero);

      expect(signals.single, isA<UpgradeRequiredSignal>().having((s) => s.details['min_supported'], 'min', '2.0.0'));
    });

    test('503 maintenance raises a maintenance signal and is not retried', () async {
      final t = build((_) => FakeReply.json(503, errorJson('maintenance', 'Back at noon.'), headers: {'retry-after': '300'}));
      final signals = <PolicySignal>[];
      t.client.signals.listen(signals.add);

      await expectLater(t.client.get('/dashboard'), throwsA(isA<ApiError>().having((e) => e.kind, 'kind', ApiErrorKind.maintenance)));
      await Future<void>.delayed(Duration.zero);

      expect(t.adapter.requests, hasLength(1));
      expect(signals.single, isA<MaintenanceSignal>().having((s) => s.message, 'message', 'Back at noon.'));
    });
  });

  group('401 handling', () {
    test('a rejected token is reported and the error surfaces', () async {
      final t = build((_) => FakeReply.json(401, errorJson('unauthenticated', 'x')));
      await expectLater(t.client.get('/me'), throwsA(isA<ApiError>().having((e) => e.kind, 'kind', ApiErrorKind.unauthenticated)));
      expect(t.tokens.rejected, ['tok-1']);
      expect(t.adapter.requests, hasLength(1));
    });

    test('when the token was rotated in the meantime the call is repeated once with the new one', () async {
      final t = build(
        (o) => o.headers['Authorization'] == 'Bearer tok-2' ? FakeReply.json(200, {'data': {'ok': true}}) : FakeReply.json(401, errorJson('unauthenticated', 'x')),
        replacement: 'tok-2',
      );

      final res = await t.client.get('/me');

      expect(res.map['ok'], true);
      expect(t.adapter.requests.map((r) => r.headers['Authorization']), ['Bearer tok-1', 'Bearer tok-2']);
    });

    test('a 401 with no token attached (bad login) is just an error, nobody is signed out', () async {
      final t = build((_) => FakeReply.json(401, errorJson('unauthenticated', 'x')), token: null);
      await expectLater(t.client.post('/auth/login', body: {}), throwsA(isA<ApiError>()));
      expect(t.tokens.rejected, isEmpty);
    });
  });

  test('every failure leaves the client as an ApiError, never a raw Dio exception', () async {
    final t = build((_) => FakeReply.fail(DioExceptionType.connectionError));
    await expectLater(t.client.post('/x', body: {}), throwsA(isA<ApiError>().having((e) => e.isOffline, 'offline', true)));
  });

  test('idempotency replay is visible to callers', () async {
    final t = build((_) => FakeReply.json(200, {'data': {}}, headers: {'idempotent-replayed': 'true'}));
    final res = await t.client.post('/x', body: {}, idempotent: true);
    expect(res.wasReplayed, isTrue);
  });
}
