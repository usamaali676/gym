import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/app/config/environment.dart';
import 'package:ironpulse/core/api/api_client.dart';
import 'package:ironpulse/core/authentication/session.dart';
import 'package:ironpulse/core/authentication/session_manager.dart';
import 'package:ironpulse/core/authentication/token_store.dart';
import 'package:ironpulse/core/storage/secure_store.dart';

import '../support/fake_adapter.dart';

const env = AppEnvironment(apiBaseUrl: 'https://gym.test', platform: 'ios', appVersion: '1.0.0', appBuild: '1');

class Harness {
  Harness({Handler? handler, DateTime? start})
      : now = start ?? DateTime.utc(2026, 10, 12, 12),
        store = MemorySecureStore() {
    adapter = FakeAdapter(handler ?? (_) => FakeReply.json(200, {'data': {'token': tokenJson(token: 'new-token', expires: DateTime.utc(2026, 12, 12), refreshAfter: DateTime.utc(2026, 11, 12))}}));
    tokens = TokenStore(store);
    manager = SessionManager(store: tokens, refreshDio: buildDio(env, adapter: adapter), clock: () => now);
  }

  DateTime now;
  final MemorySecureStore store;
  late final FakeAdapter adapter;
  late final TokenStore tokens;
  late final SessionManager manager;

  Session session({String token = 'old-token', int expiresInDays = 40, int refreshInDays = -1}) => Session(
        accessToken: token,
        expiresAt: now.add(Duration(days: expiresInDays)),
        refreshAfter: now.add(Duration(days: refreshInDays)),
        deviceId: 4,
      );
}

void main() {
  group('restore', () {
    test('returns nothing when no session is stored', () async {
      expect(await Harness().manager.restore(), isNull);
    });

    test('returns a stored, unexpired session', () async {
      final h = Harness();
      await h.tokens.write(h.session());
      expect((await h.manager.restore())?.accessToken, 'old-token');
    });

    test('discards an expired session', () async {
      final h = Harness();
      await h.tokens.write(h.session(expiresInDays: -1));
      expect(await h.manager.restore(), isNull);
      expect(await h.tokens.read(), isNull);
    });
  });

  group('rotation', () {
    test('a token that is not due yet is used as is, with no refresh call', () async {
      final h = Harness();
      await h.manager.start(h.session(refreshInDays: 10));
      expect(await h.manager.accessToken(), 'old-token');
      expect(h.adapter.requests, isEmpty);
    });

    test('a due token is rotated, the new one is persisted and used', () async {
      final h = Harness();
      await h.manager.start(h.session());

      expect(await h.manager.accessToken(), 'new-token');

      expect(h.adapter.requests.single.path, '/auth/refresh');
      expect(h.adapter.requests.single.headers['Authorization'], 'Bearer old-token');
      expect((await h.tokens.read())?.accessToken, 'new-token');
      expect(h.manager.current?.deviceId, 4, reason: 'the device id survives a refresh');
    });

    test('many requests asking at once cause exactly one refresh (single flight)', () async {
      final gate = Completer<void>();
      final h = Harness(handler: (_) async {
        await gate.future;
        return FakeReply.json(200, {'data': {'token': tokenJson(token: 'new-token', expires: DateTime.utc(2026, 12, 12), refreshAfter: DateTime.utc(2026, 11, 12))}});
      });
      await h.manager.start(h.session());

      final calls = [for (var i = 0; i < 8; i++) h.manager.accessToken()];
      gate.complete();
      final results = await Future.wait(calls);

      expect(results.toSet(), {'new-token'});
      expect(h.adapter.count('POST', '/auth/refresh'), 1);
    });

    test('a refresh the server rejects (401) ends the session as revoked', () async {
      final h = Harness(handler: (_) => FakeReply.json(401, errorJson('unauthenticated', 'x')));
      await h.manager.start(h.session());
      final ends = <SessionEnd>[];
      h.manager.ends.listen(ends.add);

      expect(await h.manager.accessToken(), isNull);
      await Future<void>.delayed(Duration.zero);

      expect(ends, [SessionEnd.revoked]);
      expect(await h.tokens.read(), isNull);
    });

    test('offline during refresh keeps the still-valid token and waits 5 minutes before trying again', () async {
      final h = Harness(handler: (_) => FakeReply.fail(DioExceptionType.connectionError));
      await h.manager.start(h.session());

      expect(await h.manager.accessToken(), 'old-token');
      expect(await h.manager.accessToken(), 'old-token');
      expect(h.adapter.count('POST', '/auth/refresh'), 1, reason: 'no retry storm while offline');

      h.now = h.now.add(const Duration(minutes: 6));
      expect(await h.manager.accessToken(), 'old-token');
      expect(h.adapter.count('POST', '/auth/refresh'), 2);
    });

    test('an expired token ends the session without any request', () async {
      final h = Harness();
      await h.manager.start(h.session(expiresInDays: -1));
      final ends = <SessionEnd>[];
      h.manager.ends.listen(ends.add);

      expect(await h.manager.accessToken(), isNull);
      await Future<void>.delayed(Duration.zero);

      expect(ends, [SessionEnd.revoked]);
      expect(h.adapter.requests, isEmpty);
    });
  });

  group('401 from the API', () {
    test('rejecting the current token signs out', () async {
      final h = Harness();
      await h.manager.start(h.session(refreshInDays: 10));
      final ends = <SessionEnd>[];
      h.manager.ends.listen(ends.add);

      expect(await h.manager.onUnauthorized('old-token'), isNull);
      await Future<void>.delayed(Duration.zero);

      expect(ends, [SessionEnd.revoked]);
      expect(h.manager.hasSession, isFalse);
    });

    test('a token that was already replaced is not blamed: the caller gets the current one', () async {
      final h = Harness();
      await h.manager.start(h.session(token: 'newer', refreshInDays: 10));
      expect(await h.manager.onUnauthorized('stale'), 'newer');
      expect(h.manager.hasSession, isTrue);
    });
  });

  test('signing out on purpose clears storage and reports signedOut', () async {
    final h = Harness();
    await h.manager.start(h.session(refreshInDays: 10));
    final ends = <SessionEnd>[];
    h.manager.ends.listen(ends.add);

    await h.manager.end(SessionEnd.signedOut);
    await Future<void>.delayed(Duration.zero);

    expect(ends, [SessionEnd.signedOut]);
    expect(h.store.data, isEmpty);
  });
}
