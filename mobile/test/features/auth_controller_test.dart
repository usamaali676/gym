import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/core/api/api_error.dart';
import 'package:ironpulse/core/authentication/token_store.dart';
import 'package:ironpulse/core/providers.dart';
import 'package:ironpulse/features/authentication/presentation/auth_controller.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';

void main() {
  group('restoring a session at launch', () {
    test('no stored session: signed out', () async {
      final w = await TestWorld.create();
      final c = w.container();
      c.read(authControllerProvider);
      await settle();
      expect(c.read(authControllerProvider), isA<SignedOut>());
      expect(w.server.log, isEmpty, reason: 'nothing to ask the server');
    });

    test('a stored session is confirmed with /me and the member lands signed in', () async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.serveSignedInMember();
      final c = w.container();
      c.read(authControllerProvider);
      await settle();

      final state = c.read(authControllerProvider);
      expect(state, isA<SignedIn>());
      expect((state as SignedIn).user.name, 'Sam Lifter');
      expect(state.offline, isFalse);
      expect(w.server.adapter.requests.single.headers['Authorization'], 'Bearer 5|ipt_stored');
    });

    test('the server revoked the token: signed out with an explanation, credentials erased', () async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.server.reply('GET', '/me', 401, errorJson('unauthenticated', 'x'));
      final c = w.container();
      c.read(authControllerProvider);
      await settle();

      final state = c.read(authControllerProvider);
      expect(state, isA<SignedOut>().having((s) => s.notice, 'notice', contains('session ended')));
      expect(await TokenStore(w.store).read(), isNull);
    });

    test('offline with a cached account: opens signed in, flagged offline', () async {
      final w = await TestWorld.create();
      await w.storeSession(cachedUser: userJson(name: 'Cached Sam'));
      w.server.on('GET', '/me', (_) => FakeReply.fail(DioExceptionType.connectionError));
      final c = w.container();
      c.read(authControllerProvider);
      await settle();

      final state = c.read(authControllerProvider);
      expect(state, isA<SignedIn>().having((s) => s.offline, 'offline', true).having((s) => s.user.name, 'name', 'Cached Sam'));
    });

    test('offline with nothing cached: waits on the splash screen with a retry, the token is kept', () async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.server.on('GET', '/me', (_) => FakeReply.fail(DioExceptionType.connectionError));
      final c = w.container();
      c.read(authControllerProvider);
      await settle();

      expect(c.read(authControllerProvider), isA<AuthInitializing>().having((s) => s.error?.isOffline, 'offline error', true));
      expect(await TokenStore(w.store).read(), isNotNull);

      // back online: retry works
      w.server.reply('GET', '/me', 200, {'data': userJson()});
      await c.read(authControllerProvider.notifier).restore();
      expect(c.read(authControllerProvider), isA<SignedIn>());
    });

    test('a server error keeps the token and offers a retry instead of signing out', () async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.server.reply('GET', '/me', 500, errorJson('server_error', 'Oops'));
      final c = w.container();
      c.read(authControllerProvider);
      await settle();

      expect(c.read(authControllerProvider), isA<AuthInitializing>().having((s) => s.error?.kind, 'kind', ApiErrorKind.server));
      expect(await TokenStore(w.store).read(), isNotNull);
    });
  });

  group('signing in', () {
    test('login sends the device, stores the token securely and signs in', () async {
      final w = await TestWorld.create();
      w.server.on('POST', '/auth/login', (_) => FakeReply.json(200, {'data': {'token': tokenJson(token: '8|ipt_new'), 'device_id': 12, 'user': userJson()}}));
      final c = w.container();
      await settle();

      await c.read(authControllerProvider.notifier).login('  Sam@Example.com ', 'hunter2hunter2');

      expect(c.read(authControllerProvider), isA<SignedIn>());
      final sent = w.server.adapter.requests.firstWhere((r) => r.path == '/auth/login');
      final body = sent.data as Map;
      expect(body['email'], 'Sam@Example.com', reason: 'trimmed; the server lower-cases');
      expect(body['device']['installation_id'], 'test-installation-0001');
      expect(body['device']['platform'], 'android');
      expect(body['device']['timezone'], 'Europe/London');
      expect(sent.headers.containsKey('Authorization'), isFalse);
      expect((await TokenStore(w.store).read())?.accessToken, '8|ipt_new');
      expect((await TokenStore(w.store).read())?.deviceId, 12);
    });

    test('wrong credentials: the field error reaches the form and nothing is stored', () async {
      final w = await TestWorld.create();
      w.server.reply('POST', '/auth/login', 422, errorJson('validation_failed', 'The given data was invalid.', details: {'email': ['These credentials do not match our records.']}));
      final c = w.container();
      await settle();

      await expectLater(
        c.read(authControllerProvider.notifier).login('a@b.co', 'wrong-password'),
        throwsA(isA<ApiError>().having((e) => e.fieldErrors['email'], 'email error', 'These credentials do not match our records.')),
      );
      expect(c.read(authControllerProvider), isA<SignedOut>());
      expect(await TokenStore(w.store).read(), isNull);
    });

    test('an answer the app cannot read signs nobody in and stores no token', () async {
      final w = await TestWorld.create();
      // A token without the user: half of a valid answer.
      w.server.reply('POST', '/auth/login', 200, {'data': {'token': tokenJson(token: '8|ipt_half'), 'device_id': 12}});
      final c = w.container();
      await settle();

      await expectLater(
        c.read(authControllerProvider.notifier).login('a@b.co', 'hunter2hunter2'),
        throwsA(isA<ApiError>().having((e) => e.code, 'code', 'unreadable_response')),
      );
      expect(c.read(authControllerProvider), isA<SignedOut>());
      expect(await TokenStore(w.store).read(), isNull, reason: 'a half-readable answer must not leave a stored session behind');
    });

    test('register and guest sign in the same way', () async {
      final w = await TestWorld.create();
      w.server.on('POST', '/auth/register', (_) => FakeReply.json(201, {'data': {'token': tokenJson(), 'device_id': 1, 'user': userJson(onboarded: false)}}));
      w.server.on('POST', '/auth/guest', (_) => FakeReply.json(201, {'data': {'token': tokenJson(), 'device_id': 2, 'user': userJson(guest: true, onboarded: false)}}));

      final c1 = w.container();
      await settle();
      await c1.read(authControllerProvider.notifier).register('Sam', 'sam@example.com', 'password123');
      expect((c1.read(authControllerProvider) as SignedIn).user.onboarded, isFalse);
      expect((w.server.adapter.requests.last.data as Map)['name'], 'Sam');

      final c2 = w.container();
      await settle();
      await c2.read(authControllerProvider.notifier).continueAsGuest();
      expect((c2.read(authControllerProvider) as SignedIn).user.isGuest, isTrue);
    });
  });

  group('signing out', () {
    test('revokes the token on the server and erases it locally', () async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.serveSignedInMember();
      w.server.reply('POST', '/auth/logout', 200, {'data': {'ok': true}});
      final c = w.container();
      c.read(authControllerProvider);
      await settle();

      await c.read(authControllerProvider.notifier).signOut();
      await settle();

      expect(w.server.log, contains('POST /auth/logout'));
      expect(c.read(authControllerProvider), isA<SignedOut>().having((s) => s.notice, 'notice', isNull));
      expect(await TokenStore(w.store).read(), isNull);
      expect(await TokenStore(w.store).readUser(), isNull);
    });

    test('works offline: the phone forgets the token even if the server cannot be told', () async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.serveSignedInMember();
      w.server.on('POST', '/auth/logout', (_) => FakeReply.fail(DioExceptionType.connectionError));
      final c = w.container();
      c.read(authControllerProvider);
      await settle();

      await c.read(authControllerProvider.notifier).signOut();
      await settle();

      expect(c.read(authControllerProvider), isA<SignedOut>());
      expect(await TokenStore(w.store).read(), isNull);
    });
  });

  test('a 401 on any later call (token revoked from another device) ends the session and explains why', () async {
    final w = await TestWorld.create();
    await w.storeSession();
    w.serveSignedInMember();
    final c = w.container();
    c.read(authControllerProvider);
    await settle();
    expect(c.read(authControllerProvider), isA<SignedIn>());

    w.server.reply('GET', '/dashboard', 401, errorJson('unauthenticated', 'x'));
    await expectLater(c.read(apiClientProvider).get('/dashboard'), throwsA(isA<ApiError>().having((e) => e.kind, 'kind', ApiErrorKind.unauthenticated)));
    await settle();

    expect(c.read(authControllerProvider), isA<SignedOut>().having((s) => s.notice, 'notice', contains('session ended')));
    expect(await TokenStore(w.store).read(), isNull);
  });
}
