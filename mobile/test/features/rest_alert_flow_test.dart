import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/features/authentication/presentation/auth_controller.dart';
import 'package:ironpulse/core/notifications/rest_alerts.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

Future<void> openWorkout(WidgetTester tester, TestWorld w) async {
  w.serveSignedInMember();
  w.server.reply('GET', '/dashboard', 200, {'data': dashboardJson(openSessionId: 1)});
  FakeWorkoutBackend(w.server);
  await w.storeSession();
  await pumpApp(tester, w);
  await pumpUntilHome(tester);
  await tester.tap(find.text('RESUME WORKOUT'));
  await pumpUntil(tester, find.textContaining('Exercise 1 of'));
}

Future<void> startRest(WidgetTester tester) async {
  await tester.tap(find.text('START SET 1'));
  await pumpUntil(tester, find.text('SET 1 OF 3'));
  await tester.tap(find.text('COMPLETE SET'));
  await pumpUntil(tester, find.text('REST'));
}

/// The states a real phone goes through, in order (the framework asserts on a shortcut).
Future<void> goToBackground(WidgetTester tester) async {
  for (final s in [AppLifecycleState.inactive, AppLifecycleState.hidden, AppLifecycleState.paused]) {
    tester.binding.handleAppLifecycleStateChanged(s);
  }
  await tester.pump(const Duration(milliseconds: 100));
}

Future<void> comeBack(WidgetTester tester) async {
  for (final s in [AppLifecycleState.hidden, AppLifecycleState.inactive, AppLifecycleState.resumed]) {
    tester.binding.handleAppLifecycleStateChanged(s);
  }
  await tester.pump(const Duration(milliseconds: 100));
}

void main() {
  setUpAll(() {
    TestWidgetsFlutterBinding.ensureInitialized();
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async => null);
  });

  group('during a workout', () {
    testWidgets('switched on: leaving the app during a rest schedules an alert for the moment the rest ends; coming back cancels it', (tester) async {
      final w = await TestWorld.create(prefs: {RestAlertsEnabled.prefsKey: true});
      await openWorkout(tester, w);
      await startRest(tester);
      final restEnds = w.clock().add(const Duration(seconds: 90));
      expect(w.alerts.pending, isEmpty, reason: 'in the app the screen does the counting');

      await goToBackground(tester);
      final alert = w.alerts.pending[RestAlerts.notificationId]!;
      expect(alert.at, restEnds);
      expect(alert.title, 'Rest is over');
      expect(alert.body, 'Next: Bench press, set 2 of 3');

      await comeBack(tester);
      expect(w.alerts.pending, isEmpty);
    });

    testWidgets('switched off (the default): nothing is scheduled and the notification system is not touched', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      await startRest(tester);

      await goToBackground(tester);
      await comeBack(tester);
      expect(w.alerts.calls, 0);
    });

    testWidgets('+30 s moves the alert; it was scheduled while the rest was longer', (tester) async {
      final w = await TestWorld.create(prefs: {RestAlertsEnabled.prefsKey: true});
      await openWorkout(tester, w);
      await startRest(tester);
      await tester.tap(find.text('+30 S'));
      await tester.pump(const Duration(milliseconds: 300));

      await goToBackground(tester);
      expect(w.alerts.pending[RestAlerts.notificationId]!.at, w.clock().add(const Duration(seconds: 120)));
    });

    testWidgets('a paused rest has no alert (it does not end by itself)', (tester) async {
      final w = await TestWorld.create(prefs: {RestAlertsEnabled.prefsKey: true});
      await openWorkout(tester, w);
      await startRest(tester);
      await tester.tap(find.byTooltip('Pause'));
      await pumpUntil(tester, find.text('Paused'));

      await goToBackground(tester);
      expect(w.alerts.pending, isEmpty);
    });

    testWidgets('skipping the rest takes the alert away again', (tester) async {
      final w = await TestWorld.create(prefs: {RestAlertsEnabled.prefsKey: true});
      await openWorkout(tester, w);
      await startRest(tester);
      await goToBackground(tester);
      expect(w.alerts.pending, hasLength(1));
      await comeBack(tester);

      await tester.tap(find.text('SKIP REST'));
      await pumpUntil(tester, find.text('SET 2 OF 3'));
      await goToBackground(tester);
      expect(w.alerts.pending, isEmpty, reason: 'no rest is running');
    });
  });

  testWidgets('signing out removes a waiting alert, so the next person never gets it', (tester) async {
    final w = await TestWorld.create(prefs: {RestAlertsEnabled.prefsKey: true});
    await openWorkout(tester, w);
    await startRest(tester);
    await goToBackground(tester);
    expect(w.alerts.pending, hasLength(1));

    w.server.reply('POST', '/auth/logout', 200, {'data': {'ok': true}});
    final container = ProviderScope.containerOf(tester.element(find.byType(Scaffold).first));
    unawaited(container.read(authControllerProvider.notifier).signOut());
    for (var i = 0; i < 6; i++) {
      await tester.pump(const Duration(milliseconds: 100));
    }
    expect(w.alerts.pending, isEmpty);
  });

  group('on the Account screen', () {
    Future<TestWorld> openAccount(WidgetTester tester, {Map<String, Object> prefs = const {}, bool exact = true}) async {
      final w = await TestWorld.create(prefs: prefs);
      w.alerts.exact = exact;
      w.serveSignedInMember();
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('ACCOUNT'));
      await pumpUntil(tester, find.text('Rest timer alert'));
      return w;
    }

    testWidgets('is off by default and asks nothing until the member turns it on', (tester) async {
      final w = await openAccount(tester);
      expect(tester.widget<Switch>(find.byType(Switch)).value, isFalse);
      expect(w.alerts.calls, 0, reason: 'merely opening Account does not touch notifications');

      await tester.tap(find.byType(Switch));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));
      expect(w.alerts.prompted, isTrue);
      expect(tester.widget<Switch>(find.byType(Switch)).value, isTrue);
      expect(w.prefs.getBool(RestAlertsEnabled.prefsKey), isTrue);
    });

    testWidgets('a refused permission leaves it off and explains how to allow it', (tester) async {
      final w = await TestWorld.create();
      w.alerts.allowed = false;
      w.serveSignedInMember();
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('ACCOUNT'));
      await pumpUntil(tester, find.text('Rest timer alert'));

      await tester.tap(find.byType(Switch));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));
      expect(tester.widget<Switch>(find.byType(Switch)).value, isFalse);
      expect(find.textContaining('Notifications are blocked for IronPulse'), findsOneWidget);
    });

    testWidgets('without exact timing it says the alert may be late and offers to allow it; with it, no note', (tester) async {
      final w = await openAccount(tester, prefs: {RestAlertsEnabled.prefsKey: true}, exact: false);
      await tester.pump(const Duration(milliseconds: 100));
      expect(find.textContaining('may deliver the alert a few minutes late'), findsOneWidget);

      await tapVisible(tester, find.text('ALLOW EXACT TIMING'));
      await tester.pump();
      expect(w.alerts.exactRequests, 1);
    });

    testWidgets('with exact timing allowed there is no warning', (tester) async {
      await openAccount(tester, prefs: {RestAlertsEnabled.prefsKey: true});
      await tester.pump(const Duration(milliseconds: 100));
      expect(find.textContaining('may deliver the alert'), findsNothing);
    });
  });
}
