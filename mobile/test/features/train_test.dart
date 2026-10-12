import 'package:flutter/widgets.dart' show Scrollable;
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/features/workout_programs/domain/plan.dart';
import 'package:ironpulse/features/workout_programs/domain/programme.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

Future<void> openTrain(WidgetTester tester, TestWorld w) async {
  await w.storeSession();
  await pumpApp(tester, w);
  await pumpUntilHome(tester);
  await tester.tap(find.text('TRAIN'));
  await pumpUntil(tester, find.text('Strength Block'));
}

void main() {
  group('models', () {
    test('week plan parses days, statuses and sessions', () {
      final w = WeekPlan.fromJson(weekPlanJson());
      expect(w.programName, 'Strength Block');
      expect(w.days, hasLength(7));
      expect(w.days[0].status, 'completed');
      expect(w.days[0].sessionId, 100);
      expect(w.days[2].isToday, isTrue);
      expect(w.days[3].day!.isRest, isTrue);
      expect((w.done, w.target), (2, 4));
    });

    test('day detail parses exercises with the server-composed target', () {
      final d = DayDetail.fromJson(dayDetailJson());
      expect(d.exercises, hasLength(2));
      expect(d.exercises.first.target!.next, '62.5 kg × 8');
      expect(d.exercises.last.target, isNull);
      expect(d.canAct, isTrue);
      expect(d.exercises.first.restSeconds, 120);
    });

    test('programme detail reads phases (sprint or phase) with their week ranges', () {
      final p = ProgrammeDetail.fromJson(programmeDetailJson());
      expect(p.phases.map((e) => e.weeks), ['Weeks 1-4', 'Week 5']);
      expect(p.phases.first.kind, 'sprint');
      expect(p.days.last.header.isRest, isTrue);
      expect(p.days.first.exercises.single.name, 'Bench press');
    });

    test('a locked programme card carries no days or phases', () {
      final p = ProgrammeDetail.fromJson(programmeJson(locked: true, entitlement: 'programs.premium'));
      expect(p.programme.locked, isTrue);
      expect(p.days, isEmpty);
    });

    test('dateParam zero-pads', () {
      expect(dateParam(DateTime(2026, 3, 5)), '2026-03-05');
    });
  });

  group('this week', () {
    testWidgets('lists the seven days with their status and opens a day', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await openTrain(tester, w);

      expect(find.text('2 of 4 sessions done'), findsOneWidget);
      expect(find.text('Push day'), findsOneWidget);
      expect(find.text('Pull day'), findsOneWidget);
      expect(find.text('Done'), findsNWidgets(2));
      expect(find.text('Today'), findsOneWidget);

      await tester.tap(find.text('Legs'));
      await pumpUntil(tester, find.text('Bench press'));
      expect(find.text('About 55 min · 2 exercises'), findsOneWidget);
      expect(w.server.adapter.requests.last.uri.queryParameters['date'], '2026-10-14');
    });

    testWidgets('next and previous week ask the server for that week', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await openTrain(tester, w);

      w.server.on('GET', '/plan/week', (o) => FakeReply.json(200, {'data': weekPlanJson(monday: DateTime.utc(2026, 10, 19), thisWeek: false, done: 0, target: 3)}));
      await tester.tap(find.byTooltip('Next week'));
      await pumpUntil(tester, find.text('0 of 3 sessions done'));
      expect(find.text('WEEK 4'), findsOneWidget);
      expect(w.server.adapter.requests.where((r) => r.path == '/plan/week').last.uri.queryParameters['date'], '2026-10-19');

      expect(find.text('Back to this week'), findsOneWidget);
      w.server.on('GET', '/plan/week', (o) => FakeReply.json(200, {'data': weekPlanJson()}));
      await tester.tap(find.text('Back to this week'));
      await pumpUntil(tester, find.text('2 of 4 sessions done'));
      expect(w.server.adapter.requests.where((r) => r.path == '/plan/week').last.uri.queryParameters.containsKey('date'), isFalse);
    });

    testWidgets('a plan without workouts gets the locked screen, from the server refusing', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/plan/week', 403, errorJson('entitlement_required', 'Your membership does not include this feature.', details: {'required_entitlement': 'workouts'}));
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('TRAIN'));
      await pumpUntil(tester, find.text('Not in your membership'));
      expect(find.textContaining('Workouts and training plan is not included'), findsOneWidget);
    });

    testWidgets('offline shows the last week that was loaded, marked as saved data', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await openTrain(tester, w);
      await tester.pump(const Duration(milliseconds: 300));

      // leave and come back with the network down
      w.server.on('GET', '/plan/week', (o) => FakeReply.fail(DioExceptionType.connectionError));
      await tester.tap(find.text('HOME'));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.tap(find.text('TRAIN'));
      await tester.pump(const Duration(milliseconds: 300));
      // the provider still holds the data; pull-to-refresh asks again and falls back to the cache
      await tester.fling(find.text('Strength Block'), const Offset(0, 400), 1000);
      await pumpUntil(tester, find.textContaining('Offline. Showing data saved at'));
      expect(find.text('Strength Block'), findsOneWidget);
    });
  });

  group('day detail', () {
    Future<void> openDay(WidgetTester tester, TestWorld w) async {
      await openTrain(tester, w);
      await tester.tap(find.text('Legs'));
      await pumpUntil(tester, find.text('Bench press'));
    }

    testWidgets('shows each exercise with sets, reps, rest, note and last/next target', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await openDay(tester, w);

      expect(find.text('4 × 6-8 · rest 120s'), findsOneWidget);
      expect(find.text('Pause on the chest.'), findsOneWidget);
      expect(find.text('Last: 60 kg × 8, 8, 7'), findsOneWidget);
      expect(find.text('Next: 62.5 kg × 8'), findsOneWidget);
      expect(find.text('Romanian deadlift'), findsOneWidget);
    });

    testWidgets('skipping a day asks the server, then offers to undo', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('POST', '/plan/days/3/skip', 200, {'data': {'status': 'skipped'}});
      await openDay(tester, w);

      w.server.on('GET', '/plan/days/3', (o) => FakeReply.json(200, {'data': dayDetailJson(status: 'skipped')}));
      await tester.tap(find.text('SKIP THIS DAY'));
      await pumpUntil(tester, find.text('UNDO SKIP'));

      final post = w.server.adapter.requests.firstWhere((r) => r.path == '/plan/days/3/skip');
      expect((post.data as Map)['date'], '2026-10-14');
      expect(post.headers['Idempotency-Key'], isNotNull);

      w.server.reply('DELETE', '/plan/days/3/skip', 200, {'data': {'status': 'unskipped'}});
      w.server.on('GET', '/plan/days/3', (o) => FakeReply.json(200, {'data': dayDetailJson()}));
      await tester.tap(find.text('UNDO SKIP'));
      await pumpUntil(tester, find.text('SKIP THIS DAY'));
    });

    testWidgets('a day that cannot be acted on has no skip button; a future day explains why', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.on('GET', '/plan/days/3', (o) => FakeReply.json(200, {'data': dayDetailJson(canAct: false, future: true, status: 'upcoming')}));
      await openDay(tester, w);

      expect(find.text('SKIP THIS DAY'), findsNothing);
      expect(find.text('This day is still ahead. You can start it on the day.'), findsOneWidget);
    });

    testWidgets('a rest day shows its recovery note and no exercises', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.on('GET', '/plan/days/3', (o) => FakeReply.json(200, {'data': dayDetailJson(rest: true, status: 'rest', canAct: false)}));
      await w.storeSession();
      await openTrain(tester, w);
      await tester.tap(find.text('Legs'));
      await pumpUntil(tester, find.text('Walk and stretch.'));
      expect(find.text('SKIP THIS DAY'), findsNothing);
    });

    testWidgets('a server error on skipping is shown, and nothing changes', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('POST', '/plan/days/3/skip', 422, errorJson('validation_failed', 'You can only skip a day within the last week.'));
      await openDay(tester, w);

      await tester.tap(find.text('SKIP THIS DAY'));
      await pumpUntil(tester, find.text('You can only skip a day within the last week.'));
      expect(find.text('SKIP THIS DAY'), findsOneWidget);
    });
  });

  group('programmes', () {
    Future<void> openProgrammes(WidgetTester tester, TestWorld w) async {
      await openTrain(tester, w);
      await tester.tap(find.text('Programmes'));
      await pumpUntil(tester, find.text('Hypertrophy Plus'));
    }

    testWidgets('lists programmes, marks the current one and the locked ones', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await openProgrammes(tester, w);

      expect(find.text('Current'), findsOneWidget);
      expect(find.text('Locked'), findsOneWidget);
      expect(find.text('4 training days'), findsNWidgets(2));
    });

    testWidgets('a locked programme opens as an explanation, not as its content', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/programs/2', 200, {'data': programmeJson(id: 2, name: 'Hypertrophy Plus', selected: false, locked: true, entitlement: 'programs.premium')});
      await openProgrammes(tester, w);

      await tester.tap(find.text('Hypertrophy Plus'));
      await pumpUntil(tester, find.text('Not in your membership'));
      expect(find.textContaining('Premium programs is not included'), findsOneWidget);
      expect(find.text('USE THIS PROGRAMME'), findsNothing);
    });

    testWidgets('detail shows phases with week ranges and the weekly template', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await openProgrammes(tester, w);

      await tester.tap(find.text('Strength Block').last);
      await pumpUntil(tester, find.text('Foundation sprint'));
      expect(find.text('Weeks 1-4'), findsOneWidget);
      expect(find.text('Sprint'), findsOneWidget);
      expect(find.text('Week 5'), findsOneWidget);
      expect(find.text('USE THIS PROGRAMME'), findsNothing, reason: 'it is already the current one');
      await tester.dragUntilVisible(find.text('Push day'), find.byType(Scrollable).first, const Offset(0, -200));
      await tester.tap(find.text('Push day'));
      await pumpUntil(tester, find.text('Bench press'));
    });

    testWidgets('switching programme asks for confirmation, posts the id once and refreshes the views', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/programs/3', 200, {'data': programmeDetailJson(id: 3, selected: false)});
      w.server.on('GET', '/programs', (o) => FakeReply.json(200, {'data': [programmeJson(id: 3, name: 'Beginner Full Body', selected: false), programmeJson()]}));
      w.server.reply('POST', '/me/program', 200, {'data': {'program_id': 3}});
      await openTrain(tester, w);
      await tester.tap(find.text('Programmes'));
      await pumpUntil(tester, find.text('Beginner Full Body'));
      await tester.tap(find.text('Beginner Full Body'));
      await pumpUntil(tester, find.text('USE THIS PROGRAMME'));

      await tester.tap(find.text('USE THIS PROGRAMME'));
      await pumpUntil(tester, find.text('Switch programme?'));
      await tester.tap(find.text('Cancel'));
      await tester.pump(const Duration(milliseconds: 400));
      expect(w.server.log, isNot(contains('POST /me/program')));

      await tester.tap(find.text('USE THIS PROGRAMME'));
      await pumpUntil(tester, find.text('Switch programme?'));
      await tester.tap(find.text('SWITCH'));
      await pumpUntil(tester, find.textContaining('Now following'));
      final post = w.server.adapter.requests.singleWhere((r) => r.path == '/me/program');
      expect((post.data as Map)['program_id'], 3);
    });
  });
}
