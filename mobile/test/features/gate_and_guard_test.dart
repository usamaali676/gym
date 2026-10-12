import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

void main() {
  group('signed out', () {
    testWidgets('shows welcome with sign in and create account, plus guest mode when the server allows it', (tester) async {
      final w = await TestWorld.create();
      w.server.reply('GET', '/app/config', 200, {'data': configJson(guest: true)});
      await pumpApp(tester, w);
      await pumpUntil(tester, find.text('SIGN IN'));

      expect(find.text('CREATE ACCOUNT'), findsOneWidget);
      expect(find.text('TRY AS A GUEST'), findsOneWidget);
      expect(find.text('IRONPULSE'), findsOneWidget); // the wordmark
    });

    testWidgets('hides guest mode when the server turned it off', (tester) async {
      final w = await TestWorld.create();
      w.server.reply('GET', '/app/config', 200, {'data': configJson(guest: false)});
      await pumpApp(tester, w);
      await pumpUntil(tester, find.text('SIGN IN'));
      await tester.pump(const Duration(milliseconds: 200));

      expect(find.text('TRY AS A GUEST'), findsNothing);
    });

    testWidgets('does not offer guest mode before the server has ever been reached', (tester) async {
      final w = await TestWorld.create();
      await pumpApp(tester, w); // /app/config answers 404 in the fake: nothing known
      await pumpUntil(tester, find.text('SIGN IN'));
      expect(find.text('TRY AS A GUEST'), findsNothing);
    });

    testWidgets('tapping Sign in opens the login form and Create account the registration form', (tester) async {
      final w = await TestWorld.create();
      w.server.reply('GET', '/app/config', 200, {'data': configJson()});
      await pumpApp(tester, w);
      await pumpUntil(tester, find.text('SIGN IN'));

      await tester.tap(find.text('SIGN IN'));
      await pumpUntil(tester, find.text('Welcome back'));
      expect(find.text('FORGOT PASSWORD?'), findsNothing); // text button keeps its case
      expect(find.text('Forgot password?'), findsOneWidget);

      await tester.tap(find.text('New here? Create an account'));
      await pumpUntil(tester, find.text('Create your account'));
    });
  });

  group('signed in', () {
    testWidgets('an onboarded member lands on home with their name and stats', (tester) async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.serveSignedInMember();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);

      expect(find.text('Sam Lifter'), findsOneWidget);
      expect(find.text('420'), findsOneWidget); // XP
      expect(find.text('5'), findsOneWidget); // streak
      expect(find.text('Push day'), findsOneWidget); // today's workout
    });

    testWidgets('a member who has not finished onboarding is sent to the profile form', (tester) async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.serveSignedInMember(user: userJson(onboarded: false));
      await pumpApp(tester, w);
      await pumpUntil(tester, find.text('Set up your profile'));
    });

    testWidgets('starting offline with a cached account opens the app and explains that home needs a connection', (tester) async {
      final w = await TestWorld.create();
      await w.storeSession(cachedUser: userJson(name: 'Cached Sam'));
      w.server.on('GET', '/app/config', (_) => FakeReply.fail(DioExceptionType.connectionError));
      w.server.on('GET', '/me', (_) => FakeReply.fail(DioExceptionType.connectionError));
      w.server.on('GET', '/dashboard', (_) => FakeReply.fail(DioExceptionType.connectionError));
      await pumpApp(tester, w);
      await pumpUntil(tester, find.text('You are offline'));

      expect(find.text('TRY AGAIN'), findsOneWidget);
      expect(find.text('HOME'), findsOneWidget, reason: 'the app shell is there, only the data is missing');
    });

    testWidgets('a cached dashboard is shown offline, with a banner saying so', (tester) async {
      final w = await TestWorld.create();
      await w.storeSession(cachedUser: userJson());
      w.serveSignedInMember();
      w.server.on('GET', '/dashboard', (_) => FakeReply.fail(DioExceptionType.connectionError));
      // the cache a previous online session left behind
      await w.prefs.setString('ip.cache.7.dashboard', '{"saved_at":"2026-10-12T08:30:00.000Z","data":${jsonEncode(dashboardJson())}}');
      await pumpApp(tester, w);
      await pumpUntilHome(tester);

      expect(find.textContaining('Offline. Showing data saved at'), findsOneWidget);
      expect(find.text('Push day'), findsOneWidget);
    });

    testWidgets('sign out asks first, then returns to welcome and forgets the account', (tester) async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.serveSignedInMember();
      w.server.reply('POST', '/auth/logout', 200, {'data': {'ok': true}});
      await pumpApp(tester, w);
      await pumpUntilHome(tester);

      await tester.tap(find.text('ACCOUNT'));
      await pumpUntil(tester, find.text('Member'));
      await tester.tap(find.text('SIGN OUT'));
      await pumpUntil(tester, find.text('Sign out?'));
      expect(find.textContaining('email and password'), findsOneWidget);

      await tester.tap(find.text('SIGN OUT').last);
      await pumpUntil(tester, find.text('CREATE ACCOUNT'));
      expect(w.server.log, contains('POST /auth/logout'));
    });

    testWidgets('a guest is warned that signing out loses the account', (tester) async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.serveSignedInMember(user: userJson(guest: true));
      await pumpApp(tester, w);
      await pumpUntilHome(tester);

      await tester.tap(find.text('ACCOUNT'));
      await pumpUntil(tester, find.text('Guest account'));
      await tester.tap(find.text('SIGN OUT'));
      await pumpUntil(tester, find.text('Sign out?'));
      expect(find.textContaining('guest account'), findsOneWidget);
    });
  });

  group('server policy', () {
    testWidgets('an unsupported version blocks the app and links to the store', (tester) async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.serveSignedInMember(config: configJson(status: 'update_required', storeUrl: 'https://play.google.com/store/apps/details?id=x'));
      await pumpApp(tester, w);
      await pumpUntil(tester, find.text('Update required'));

      expect(find.text('OPEN THE STORE'), findsOneWidget);
      expect(find.text('GOOD MORNING'), findsNothing);
    });

    testWidgets('no store button when the gym has not set a store link', (tester) async {
      final w = await TestWorld.create();
      w.server.reply('GET', '/app/config', 200, {'data': configJson(status: 'update_required')});
      await pumpApp(tester, w);
      await pumpUntil(tester, find.text('Update required'));

      expect(find.text('OPEN THE STORE'), findsNothing);
      expect(find.textContaining('store link has not been set'), findsOneWidget);
    });

    testWidgets('maintenance shows the admin message and "Check again" lets the app back in when it ends', (tester) async {
      final w = await TestWorld.create();
      w.server.reply('GET', '/app/config', 200, {'data': configJson(maintenance: true)});
      await pumpApp(tester, w);
      await pumpUntil(tester, find.text('Back soon'));
      expect(find.text('Upgrading the servers.'), findsOneWidget);

      w.server.reply('GET', '/app/config', 200, {'data': configJson()});
      await tester.tap(find.text('CHECK AGAIN'));
      await pumpUntil(tester, find.text('SIGN IN'));
      expect(find.text('Back soon'), findsNothing);
    });

    testWidgets('a 426 from any API call during use switches to the update screen', (tester) async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.serveSignedInMember();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);

      w.server.reply('GET', '/dashboard', 426, errorJson('upgrade_required', 'Update please.'));
      // pull to refresh reloads the dashboard, which the server now refuses
      await tester.fling(find.text('GOOD MORNING'), const Offset(0, 400), 1000);
      await pumpUntil(tester, find.text('Update required'));
    });
  });
}
