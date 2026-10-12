import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/features/authentication/presentation/forgot_password_screen.dart';
import 'package:ironpulse/features/authentication/presentation/login_screen.dart';
import 'package:ironpulse/features/authentication/presentation/register_screen.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

void main() {
  group('login form', () {
    testWidgets('empty submit shows messages next to the fields and sends nothing', (tester) async {
      final w = await TestWorld.create();
      await pumpScreen(tester, w, const LoginScreen());

      await tester.tap(find.text('SIGN IN'));
      await tester.pump();

      expect(find.text('Enter your email address.'), findsOneWidget);
      expect(find.text('Enter your password.'), findsOneWidget);
      expect(w.server.adapter.requests.where((r) => r.path == '/auth/login'), isEmpty);
    });

    testWidgets('a malformed email is caught before the request', (tester) async {
      final w = await TestWorld.create();
      await pumpScreen(tester, w, const LoginScreen());

      await tester.enterText(find.byType(TextFormField).at(0), 'not-an-email');
      await tester.enterText(find.byType(TextFormField).at(1), 'whatever');
      await tester.tap(find.text('SIGN IN'));
      await tester.pump();

      expect(find.text('That does not look like an email address.'), findsOneWidget);
      expect(w.server.adapter.requests, isEmpty);
    });

    testWidgets("the server's message for wrong credentials appears under the email field", (tester) async {
      final w = await TestWorld.create();
      w.server.reply('POST', '/auth/login', 422, errorJson('validation_failed', 'The given data was invalid.', details: {'email': ['These credentials do not match our records.']}));
      await pumpScreen(tester, w, const LoginScreen());

      await tester.enterText(find.byType(TextFormField).at(0), 'sam@example.com');
      await tester.enterText(find.byType(TextFormField).at(1), 'wrong-password');
      await tester.tap(find.text('SIGN IN'));
      await pumpUntil(tester, find.text('These credentials do not match our records.'));
    });

    testWidgets('offline shows why, in a banner above the form', (tester) async {
      final w = await TestWorld.create();
      w.server.on('POST', '/auth/login', (_) => FakeReply.fail(DioExceptionType.connectionError));
      await pumpScreen(tester, w, const LoginScreen());

      await tester.enterText(find.byType(TextFormField).at(0), 'sam@example.com');
      await tester.enterText(find.byType(TextFormField).at(1), 'password123');
      await tester.tap(find.text('SIGN IN'));
      await pumpUntil(tester, find.text('Cannot reach the server. Check your connection and try again.'));
    });

    testWidgets('the password can be revealed and hidden', (tester) async {
      final w = await TestWorld.create();
      await pumpScreen(tester, w, const LoginScreen());

      EditableText password() => tester.widget<EditableText>(find.byType(EditableText).at(1));
      expect(password().obscureText, isTrue);
      await tester.tap(find.byTooltip('Show password'));
      await tester.pump();
      expect(password().obscureText, isFalse);
    });
  });

  group('register form', () {
    testWidgets('requires a name, a valid email and a password of at least 8 characters', (tester) async {
      final w = await TestWorld.create();
      await pumpScreen(tester, w, const RegisterScreen());

      await tester.enterText(find.byType(TextFormField).at(2), 'short');
      await tester.tap(find.text('CREATE ACCOUNT'));
      await tester.pump();

      expect(find.text('Enter your name.'), findsOneWidget);
      expect(find.text('Enter your email address.'), findsOneWidget);
      expect(find.text('Use at least 8 characters.'), findsOneWidget);
      expect(w.server.adapter.requests, isEmpty);
    });

    testWidgets('the server reports an email that is already registered', (tester) async {
      final w = await TestWorld.create();
      w.server.reply('POST', '/auth/register', 422, errorJson('validation_failed', 'The given data was invalid.', details: {'email': ['The email has already been taken.']}));
      await pumpScreen(tester, w, const RegisterScreen());

      await tester.enterText(find.byType(TextFormField).at(0), 'Sam');
      await tester.enterText(find.byType(TextFormField).at(1), 'sam@example.com');
      await tester.enterText(find.byType(TextFormField).at(2), 'password123');
      await tester.tap(find.text('CREATE ACCOUNT'));
      await pumpUntil(tester, find.text('The email has already been taken.'));
    });
  });

  group('forgot password', () {
    testWidgets('asks the server for a reset link and shows the neutral confirmation', (tester) async {
      final w = await TestWorld.create();
      w.server.reply('POST', '/auth/forgot-password', 200, {'data': {'message': 'If that email has an account, a reset link is on its way.'}});
      await pumpScreen(tester, w, const ForgotPasswordScreen());

      await tester.enterText(find.byType(TextFormField), 'sam@example.com');
      await tester.tap(find.text('SEND RESET LINK'));
      await pumpUntil(tester, find.textContaining('a reset link is on its way'));

      expect((w.server.adapter.requests.single.data as Map)['email'], 'sam@example.com');
    });
  });
}
