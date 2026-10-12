import 'package:flutter/material.dart' show Scrollable, EditableText;
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/features/progress/domain/progress.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

Future<void> openProgress(WidgetTester tester, TestWorld w, {String? segment}) async {
  w.serveSignedInMember();
  await w.storeSession();
  await pumpApp(tester, w);
  await pumpUntilHome(tester);
  await tester.tap(find.text('PROGRESS'));
  await pumpUntil(tester, find.text(segment ?? 'WEIGHT'));
}

void main() {
  group('models', () {
    test('summary: the server numbers are kept as sent', () {
      final s = ProgressSummary.fromJson(progressSummaryJson());
      expect(s.weightPoints, hasLength(4));
      expect(s.change30dKg, -1.8);
      expect(s.trendPerWeekKg, -0.57);
      expect(s.bmi, 25.3);
      expect(s.bmiCategory, 'Overweight range');
      expect(s.bodyFatSource, 'logged');
      expect(s.latest!.waistCm, 85);
    });

    test('records and strength series', () {
      final r = PersonalRecord.fromJson(recordsJson().single);
      expect((r.exerciseName, r.bestWeightKg, r.est1rm), ('Flat Barbell Bench Press', 50.0, 63.33));
      final s = StrengthSeries.fromJson({'exercise': {'name': 'Bench'}, 'series': [{'date': '2026-10-12', 'e1rm': 63.3, 'top_weight': 50, 'volume': 400}]});
      expect(s.points.single.e1rm, 63.3);
    });

    test('badges: progress and earned state', () {
      final a = Achievements.fromJson(achievementsJson());
      expect(a.level, 2);
      expect(a.badges.where((b) => b.earned).single.slug, 'first-workout');
      expect(a.badges.first.progress!.pct, 0.3);
      expect(a.xpProgress, closeTo(0.1, 0.001));
    });
  });

  group('overview', () {
    testWidgets('draws the weight trend and shows the server-computed figures', (tester) async {
      final w = await TestWorld.create();
      final semantics = tester.ensureSemantics();
      await openProgress(tester, w);
      await tester.pump(const Duration(milliseconds: 400));

      expect(find.semantics.byPredicate((n) => n.label.contains('Weight trend: 82.0 kg on Sep 20 to 80.2 kg on Oct 12')), findsOneWidget);
      expect(find.text('80.2 kg'), findsOneWidget);
      expect(find.text('-1.8 kg'), findsOneWidget);
      expect(find.text('-0.57 kg'), findsOneWidget);
      expect(find.text('25.3 · Overweight range'), findsOneWidget);
      expect(find.text('A rough screening number, not a diagnosis.'), findsOneWidget);
      expect(find.text('Body fat'), findsOneWidget);
      semantics.dispose();
    });

    testWidgets('an estimated body fat is labelled as an estimate', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      final s = progressSummaryJson()..['body_fat_source'] = 'estimated';
      w.server.reply('GET', '/progress/summary', 200, {'data': s});
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('PROGRESS'));
      await pumpUntil(tester, find.text('Body fat (estimate)'));
      await tester.pump(const Duration(milliseconds: 300)); // let the records request finish before the test ends
    });

    testWidgets('with no weigh-ins it says how to start instead of drawing an empty chart', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/progress/summary', 200, {'data': progressSummaryJson(points: false)});
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('PROGRESS'));
      await pumpUntil(tester, find.text('Log your weight in the Body tab to see a trend.'));
      await tester.pump(const Duration(milliseconds: 300));
    });

    testWidgets('personal records open the strength curve of that exercise', (tester) async {
      final w = await TestWorld.create();
      w.server.reply('GET', '/progress/strength/flat-barbell-bench-press', 200, {
        'data': {
          'exercise': {'id': 1, 'slug': 'flat-barbell-bench-press', 'name': 'Flat Barbell Bench Press'},
          'series': [
            {'date': '2026-10-05', 'e1rm': 60.0, 'top_weight': 47.5, 'volume': 380},
            {'date': '2026-10-12', 'e1rm': 63.3, 'top_weight': 50, 'volume': 400},
          ],
        },
      });
      await openProgress(tester, w);
      await tapVisible(tester, find.text('Flat Barbell Bench Press'));
      await pumpUntil(tester, find.text('ESTIMATED 1-REP MAX'));
      expect(find.text('An estimate from your heaviest sets, not a tested max.'), findsOneWidget);
      expect(find.text('top 50.0 kg · e1RM 63.3'), findsOneWidget);
    });

    testWidgets('a plan without analytics gets the locked screen on Overview while Body and Badges still work', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/progress/summary', 403, errorJson('entitlement_required', 'Your membership does not include this feature.', details: {'required_entitlement': 'analytics'}));
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('PROGRESS'));
      await pumpUntil(tester, find.text('Not in your membership'));
      expect(find.textContaining('Progress analytics and reports is not included'), findsOneWidget);

      await tester.tap(find.text('Body'));
      await pumpUntil(tester, find.text('LOG MEASUREMENT'));
      await tester.tap(find.text('Badges'));
      await pumpUntil(tester, find.text('First Workout'));
    });
  });

  group('body measurements', () {
    Future<void> openBody(WidgetTester tester, TestWorld w) async {
      await openProgress(tester, w);
      await tester.tap(find.text('Body'));
      await pumpUntil(tester, find.text('LOG MEASUREMENT'));
    }

    testWidgets('lists entries with weight, body fat and waist', (tester) async {
      final w = await TestWorld.create();
      await openBody(tester, w);
      expect(find.text('80.2\u00A0kg · 18.5\u00A0%\u00A0fat · waist\u00A085\u00A0cm'), findsOneWidget);
      expect(find.text('After holiday'), findsOneWidget);
    });

    testWidgets('saving sends only what was filled in, with today\'s date and an idempotency key', (tester) async {
      final w = await TestWorld.create();
      w.server.reply('POST', '/progress/measurements', 201, {'data': {'measurement': measurementJson(8, '2026-10-12', 79.9), 'badges': []}});
      await openBody(tester, w);

      await tester.tap(find.text('LOG MEASUREMENT'));
      await pumpUntil(tester, find.text('Log measurement'));
      await tester.pump(const Duration(milliseconds: 400));
      await tester.enterText(find.byType(EditableText).at(0), '79,9');
      await tester.tap(find.text('SAVE'));
      await pumpUntil(tester, find.text('LOG MEASUREMENT'));

      final post = w.server.adapter.requests.firstWhere((r) => r.method == 'POST' && r.path == '/progress/measurements');
      final body = post.data as Map;
      expect(body['weight_kg'], 79.9);
      expect(body.containsKey('body_fat_pct'), isFalse);
      expect(body['measured_on'], matches(RegExp(r'^\d{4}-\d{2}-\d{2}$')));
      expect(post.headers['Idempotency-Key'], isNotNull);
    });

    testWidgets('an empty form is refused before sending; letters are not numbers', (tester) async {
      final w = await TestWorld.create();
      await openBody(tester, w);
      await tester.tap(find.text('LOG MEASUREMENT'));
      await pumpUntil(tester, find.text('Log measurement'));
      await tester.pump(const Duration(milliseconds: 400));

      await tester.tap(find.text('SAVE'));
      await tester.pump();
      expect(find.text('Enter at least a weight, body fat or waist.'), findsOneWidget);

      await tester.enterText(find.byType(EditableText).at(0), 'abc');
      await tester.tap(find.text('SAVE'));
      await tester.pump();
      expect(find.text('Use numbers only.'), findsOneWidget);
      expect(w.server.log, isNot(contains('POST /progress/measurements')));
    });

    testWidgets('the server\'s message for an impossible value appears on its field', (tester) async {
      final w = await TestWorld.create();
      w.server.reply('POST', '/progress/measurements', 422, errorJson('validation_failed', 'The given data was invalid.', details: {'weight_kg': ['The weight must be between 30 and 300.']}));
      await openBody(tester, w);
      await tester.tap(find.text('LOG MEASUREMENT'));
      await pumpUntil(tester, find.text('Log measurement'));
      await tester.pump(const Duration(milliseconds: 400));

      await tester.enterText(find.byType(EditableText).at(0), '5');
      await tester.tap(find.text('SAVE'));
      await pumpUntil(tester, find.text('The weight must be between 30 and 300.'));
    });

    testWidgets('deleting asks first and then removes it on the server', (tester) async {
      final w = await TestWorld.create();
      w.server.reply('DELETE', '/progress/measurements/7', 200, {'data': {'ok': true}});
      await openBody(tester, w);

      await tester.tap(find.byTooltip('Delete entry from Oct 12, 2026'));
      await pumpUntil(tester, find.text('Delete this entry?'));
      await tester.tap(find.text('Cancel'));
      await tester.pump(const Duration(milliseconds: 400));
      expect(w.server.log, isNot(contains('DELETE /progress/measurements/7')));

      await tester.tap(find.byTooltip('Delete entry from Oct 12, 2026'));
      await pumpUntil(tester, find.text('Delete this entry?'));
      await tester.tap(find.text('DELETE'));
      await pumpUntil(tester, find.byTooltip('Delete entry from Oct 12, 2026'));
      expect(w.server.log, contains('DELETE /progress/measurements/7'));
    });
  });

  group('badges', () {
    testWidgets('shows level, streak and earned count, then earned badges, then the ones in progress, closest first', (tester) async {
      final w = await TestWorld.create();
      await openProgress(tester, w);
      await tester.tap(find.text('Badges'));
      await pumpUntil(tester, find.text('EARNED'));

      expect(find.text('130').evaluate().isEmpty, isTrue); // XP total is not the headline; level and streak are
      expect(find.text('30 / 300 XP to level 3 · best streak 4'), findsOneWidget);
      expect(find.textContaining('Earned Oct 5, 2026 · +25 XP'), findsOneWidget);

      await tester.dragUntilVisible(find.text('7-Day Streak'), find.byType(Scrollable).last, const Offset(0, -200));
      final streakY = tester.getTopLeft(find.text('7-Day Streak')).dy;
      await tester.dragUntilVisible(find.text('Getting Serious'), find.byType(Scrollable).last, const Offset(0, -200));
      expect(find.text('3 / 10'), findsOneWidget);
      expect(streakY, isNonNegative);
    });

    testWidgets('offline shows the saved badges with the offline banner', (tester) async {
      final w = await TestWorld.create();
      await openProgress(tester, w);
      await tester.tap(find.text('Badges'));
      await pumpUntil(tester, find.text('EARNED'));
      await tester.pump(const Duration(milliseconds: 300));

      w.server.on('GET', '/achievements', (o) => FakeReply.fail(DioExceptionType.connectionError));
      await tester.fling(find.text('EARNED'), const Offset(0, 400), 1000);
      await pumpUntil(tester, find.textContaining('Offline. Showing data saved at'));
    });
  });
}
