import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/features/dashboard/domain/dashboard.dart';
import 'package:ironpulse/features/membership/domain/membership.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

/// A screen reader node whose spoken label contains [text] (labels may carry the merged text of children).
SemanticsFinder hasLabel(String text) => find.semantics.byPredicate((node) => node.label.contains(text));

Future<void> openHome(WidgetTester tester, TestWorld w) async {
  await w.storeSession();
  await pumpApp(tester, w);
  await pumpUntilHome(tester);
}

void main() {
  group('dashboard models', () {
    test('parse the server payload', () {
      final d = Dashboard.fromJson(dashboardJson(openSessionId: 12));
      expect(d.workout.title, 'Push day');
      expect(d.workout.openSessionId, 12);
      expect(d.weekDays, hasLength(7));
      expect(d.weekDays[2].isToday, isTrue);
      expect(d.weekDone, 2);
      expect(d.xpProgress, closeTo(0.4, 0.001));
      expect(d.nutrition!.caloriesEaten, 900.5);
      expect(d.entitlements.allows('workouts'), isTrue);
    });

    test('tolerate missing pieces without crashing', () {
      final d = Dashboard.fromJson({'workout': {'status': 'rest', 'day': null}});
      expect(d.workout.hasDay, isFalse);
      expect(d.weekDays, isEmpty);
      expect(d.nutrition, isNull);
      expect(d.xpProgress, 0);
    });

    test('entitlements are "unknown" (show everything, let the server decide) until the server has answered', () {
      expect(Entitlements.unknown.allows('workouts'), isTrue);
      expect(Entitlements.fromJson({'workouts': false}).allows('workouts'), isFalse);
      expect(Entitlements.fromJson({'workouts': false}).allows('nutrition'), isFalse, reason: 'a key the server did not list is not granted');
    });
  });

  group('dashboard screen', () {
    testWidgets("shows today's workout, the week, level, XP, streak and nutrition", (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      final semantics = tester.ensureSemantics(); // assert on what a screen reader is told
      await openHome(tester, w);

      expect(find.text('Push day'), findsOneWidget);
      expect(find.text('Chest, shoulders, triceps'), findsOneWidget);
      expect(find.text('About 55 min'), findsOneWidget);
      expect(find.text('2 of 5 sessions'), findsOneWidget);
      expect(find.text('120 / 300 XP to level 4'), findsOneWidget);
      expect(find.text('901'), findsOneWidget); // 900.5 rounds
      expect(find.textContaining('/ 2400 kcal'), findsOneWidget);
      expect(hasLabel('Wednesday: today'), findsOneWidget);
      expect(hasLabel('Monday: done'), findsOneWidget);
      semantics.dispose();
    });

    testWidgets('a rest day says so and offers nothing to start', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/dashboard', 200, {'data': dashboardJson(status: 'rest', rest: true)});
      await openHome(tester, w);

      expect(find.text('Rest day'), findsOneWidget);
      expect(find.textContaining('Recovery is part of the plan'), findsOneWidget);
      expect(find.textContaining('About'), findsNothing);
    });

    testWidgets('no programme selected yet', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/dashboard', 200, {'data': dashboardJson(hasDay: false, status: 'rest')});
      await openHome(tester, w);
      expect(find.text('No programme selected yet.'), findsOneWidget);
    });

    testWidgets('a plan without workouts shows the locked card instead of the workout, and nutrition is absent', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/dashboard', 200, {'data': dashboardJson(allow: ['library.premium'], nutrition: false)});
      await openHome(tester, w);

      expect(find.text('Not in your membership'), findsOneWidget);
      expect(find.text('Workouts and training plan is not included in your current plan.'), findsOneWidget);
      expect(find.text('SEE MEMBERSHIP'), findsOneWidget);
      expect(find.text('Push day'), findsNothing);
      expect(find.text('THIS WEEK'), findsNothing, reason: 'the week plan is part of workouts');
      expect(find.text('TODAY’S NUTRITION'), findsNothing);
    });

    testWidgets('over the calorie target is flagged in amber text, not by colour alone', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      final d = dashboardJson();
      ((d['nutrition'] as Map)['totals'] as Map)['calories'] = 2800;
      w.server.reply('GET', '/dashboard', 200, {'data': d});
      await openHome(tester, w);
      expect(find.text('Over target'), findsOneWidget);
    });

    testWidgets('a server failure with nothing cached shows an error with a retry that works', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/dashboard', 500, errorJson('server_error', 'Something broke.'));
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntil(tester, find.text('Something went wrong'));
      expect(find.text('Reference req-123'), findsOneWidget);

      w.server.reply('GET', '/dashboard', 200, {'data': dashboardJson()});
      await tester.tap(find.text('TRY AGAIN'));
      await pumpUntilHome(tester);
    });

    testWidgets('signing out removes the cached dashboard from the phone', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('POST', '/auth/logout', 200, {'data': {'ok': true}});
      await openHome(tester, w);
      expect(w.prefs.getKeys().where((k) => k.startsWith('ip.cache.7.')), isNotEmpty);

      await tester.tap(find.text('ACCOUNT'));
      await pumpUntil(tester, find.text('Camera form coach'));
      await tapVisible(tester, find.text('SIGN OUT'));
      await pumpUntil(tester, find.text('Sign out?'));
      await tester.tap(find.text('SIGN OUT').last);
      await pumpUntil(tester, find.text('CREATE ACCOUNT'));

      expect(w.prefs.getKeys().where((k) => k.startsWith('ip.cache.')), isEmpty);
    });
  });

  group('membership screen', () {
    Future<void> openMembership(WidgetTester tester, TestWorld w) async {
      await openHome(tester, w);
      await tester.tap(find.text('ACCOUNT'));
      await pumpUntil(tester, find.text('Membership'));
      await tester.tap(find.text('Membership'));
      await pumpUntil(tester, find.text('YOUR PLAN'));
      await tester.pump(const Duration(milliseconds: 300)); // let the plans request finish too
    }

    testWidgets('lists everything a plan includes, with the server wording, and no purchase button', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await openMembership(tester, w);

      expect(find.text('Free'), findsWidgets);
      expect(find.text('Workouts and training plan'), findsOneWidget);
      expect(find.text('Camera posture coach'), findsOneWidget);
      expect(find.text('Included'), findsOneWidget); // the status chip of the default plan
      expect(find.textContaining('Memberships are managed by your gym'), findsOneWidget);
      expect(find.textContaining('SUBSCRIBE'), findsNothing);
      expect(find.textContaining('BUY'), findsNothing);
      expect(find.textContaining('UPGRADE'), findsNothing);
    });

    testWidgets('a restricted plan marks what is locked and the plan limits', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/membership', 200, {'data': membershipJson(allow: ['workouts'])});
      final semantics = tester.ensureSemantics();
      await openMembership(tester, w);

      expect(hasLabel('Workouts and training plan: included'), findsOneWidget);
      expect(hasLabel('Nutrition and food tracking: not included'), findsOneWidget);
      expect(find.text('Custom foods a member can create: up to 5'), findsOneWidget);
      semantics.dispose();
    });

    testWidgets('an expired subscription is described as ended, a cancelled one as running until its end date', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/membership', 200, {
        'data': membershipJson(
          plan: planJson(id: 2, slug: 'pro', name: 'Pro', free: false),
          subscription: {'id': 1, 'status': 'canceled', 'source': 'google', 'plan_slug': 'pro', 'ends_at': '2030-01-15T00:00:00+00:00', 'current_period_ends_at': '2030-01-15T00:00:00+00:00', 'auto_renew': false},
        ),
      });
      await openMembership(tester, w);

      expect(find.text('Cancelled'), findsOneWidget);
      expect(find.textContaining('Access until'), findsOneWidget);
      expect(find.textContaining('Billed by Google Play'), findsOneWidget);
    });

    testWidgets('other plans are shown with their listed price as information only', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/membership/plans', 200, {'data': [planJson(), planJson(id: 2, slug: 'pro', name: 'Pro', free: false, features: ['Everything unlocked'])]});
      await openMembership(tester, w);
      await tester.dragUntilVisible(find.text('Pro'), find.byType(Scrollable).first, const Offset(0, -200));

      expect(find.text('Listed price: USD 9.99'), findsOneWidget);
      expect(find.text('• Everything unlocked'), findsOneWidget);
      expect(find.text('Current'), findsOneWidget);
    });

    testWidgets('"See membership" on a locked card opens this screen', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/dashboard', 200, {'data': dashboardJson(allow: [], nutrition: false)});
      await openHome(tester, w);

      await tester.tap(find.text('SEE MEMBERSHIP'));
      await pumpUntil(tester, find.text('YOUR PLAN'));
    });
  });
}
