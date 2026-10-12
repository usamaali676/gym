// Working out without a connection: sets, skips and finishing are kept on the phone and sent in order when the server can
// be reached again. The server side of this (op ids, conflicts, per-op plan checks) is covered by the PHP tests and by
// the live contract test.
import 'package:dio/dio.dart' show DioExceptionType;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';
import 'workout_session_test.dart' show openWorkout;

/// A fake `/sync/batch`: answers every op as applied (or as [answer] says) and remembers what it was sent.
class FakeBatch {
  FakeBatch(this.server) {
    server.on('POST', '/sync/batch', (o) {
      if (offline) return FakeReply.fail(DioExceptionType.connectionError);
      final ops = ((o.data as Map)['operations'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      received.addAll(ops);
      return FakeReply.json(200, {
        'data': {
          'results': [for (final op in ops) answer?.call(op) ?? {'op_id': op['op_id'], 'status': 'applied'}],
          'server_time': '2026-10-12T18:00:00+00:00',
        },
      });
    });
  }

  final FakeServer server;
  bool offline = true;
  Map<String, dynamic> Function(Map<String, dynamic> op)? answer;
  final List<Map<String, dynamic>> received = [];

  List<String> get types => [for (final o in received) o['type'] as String];
}

/// The connection drops: direct calls to the workout endpoints fail like an unreachable server.
void _goOffline(TestWorld w) {
  for (final route in ['POST /workout-sessions/1/sets', 'POST /workout-sessions/1/finish', 'POST /workout-sessions/1/abandon', 'POST /workout-sessions/1/exercises/1/skip', 'PUT /workout-sessions/1/state']) {
    w.server.routes[route] = (_) => FakeReply.fail(DioExceptionType.connectionError);
  }
  w.server.routes['GET /workout-sessions/1/summary'] = (_) => FakeReply.fail(DioExceptionType.connectionError);
}

int _direct(TestWorld w) => w.server.log.where((l) => l == 'POST /workout-sessions/1/sets').length;

Future<void> _logSet(WidgetTester tester, {required String label}) async {
  await tester.tap(find.text(label));
  await pumpUntil(tester, find.textContaining('OF 3'));
  await tester.tap(find.text('COMPLETE SET'));
  await pumpUntil(tester, find.text('REST'));
}

void main() {
  setUpAll(() {
    TestWidgetsFlutterBinding.ensureInitialized();
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async => null);
  });

  group('a set without a connection', () {
    testWidgets('moves on at once, is marked "waiting to sync", and is sent when the connection comes back', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      final batch = FakeBatch(w.server);
      _goOffline(w);

      await _logSet(tester, label: 'START SET 1');
      expect(find.text('01:30'), findsOneWidget, reason: 'the rest timer starts as if the set had been saved');
      expect(find.textContaining('1 change is saved on this phone and waiting to sync'), findsOneWidget);

      await tester.tap(find.text('SKIP REST'));
      await pumpUntil(tester, find.text('SET 2 OF 3'));
      expect(find.byTooltip('Saved on this phone, waiting to sync'), findsOneWidget, reason: 'the logged set is marked');
      expect(find.text('Set 1'), findsOneWidget);

      batch.offline = false;
      await tester.pump(const Duration(seconds: 20));
      await tester.pump(const Duration(milliseconds: 300));

      expect(batch.types, ['workout.set.log']);
      final op = batch.received.single;
      expect(op['payload'], {'session_id': 1, 'session_exercise_id': 1, 'set_number': 1, 'weight_kg': 60, 'reps': 8});
      expect(op['op_id'], isNotNull);
      expect(op['client_created_at'], isNotNull);
      expect(find.textContaining('waiting to sync'), findsNothing, reason: 'sent: the strip is gone');
      expect(find.byTooltip('Saved on this phone, waiting to sync'), findsNothing, reason: 'and so is the mark on the set');
    });

    testWidgets('later sets queue behind the first, so the server sees them in the order they were done', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      final batch = FakeBatch(w.server);
      _goOffline(w);

      await _logSet(tester, label: 'START SET 1');
      final attemptsAfterFirst = _direct(w);

      // the connection is back for direct calls, but something is still waiting: set 2 must not jump the queue
      w.server.reply('POST', '/workout-sessions/1/sets', 201, {
        'data': {'set': {'id': 99, 'number': 2, 'weight_kg': 60, 'reps': 8, 'duration_seconds': null, 'rpe': null, 'is_warmup': false, 'is_pr': false, 'completed_at': '2026-10-12T18:05:00+00:00'}, 'exercise_status': 'pending', 'is_pr': false, 'personal_records': [], 'xp': 10, 'totals': {}},
      });
      await tester.tap(find.text('SKIP REST'));
      await pumpUntil(tester, find.text('SET 2 OF 3'));
      await tester.tap(find.text('COMPLETE SET'));
      await pumpUntil(tester, find.text('REST'));
      expect(_direct(w), attemptsAfterFirst, reason: 'set 2 went to the queue, not straight to the server');
      expect(find.textContaining('2 changes are saved on this phone'), findsOneWidget);

      batch.offline = false;
      await tester.pump(const Duration(seconds: 20));
      await tester.pump(const Duration(milliseconds: 300));
      expect([for (final o in batch.received) (o['payload'] as Map)['set_number']], [1, 2]);
    });

    testWidgets('a server that refuses a queued set does not lose it: it waits on the sync screen with the reason', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      final batch = FakeBatch(w.server);
      batch.answer = (op) => {'op_id': op['op_id'], 'status': 'rejected', 'error': {'code': 'session_closed', 'message': 'This workout is no longer open.'}};
      _goOffline(w);
      await _logSet(tester, label: 'START SET 1');

      batch.offline = false;
      await tester.pump(const Duration(seconds: 20));
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.textContaining('1 change needs your attention'), findsOneWidget);

      await tester.tap(find.textContaining('needs your attention'));
      await pumpUntil(tester, find.text('Not accepted'));
      expect(find.text('Bench press, set 1: 60 kg × 8'), findsOneWidget);
      expect(find.text('This workout is no longer open.'), findsOneWidget);

      await tester.tap(find.text('DISMISS'));
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.text('Not accepted'), findsNothing);
      expect(find.text('ALL SAVED'), findsOneWidget);
    });

    testWidgets('a server error on a set that is not about the connection still keeps the member on the set', (tester) async {
      final w = await TestWorld.create();
      final backend = await openWorkout(tester, w);
      backend.failSets = 1; // an HTTP 500, which is not "offline"
      await tester.tap(find.text('START SET 1'));
      await pumpUntil(tester, find.text('SET 1 OF 3'));
      await tester.tap(find.text('COMPLETE SET'));
      await pumpUntil(tester, find.text('The set could not be saved.'));
      expect(find.text('REST'), findsNothing);
      expect(find.textContaining('waiting to sync'), findsNothing, reason: 'real server errors are shown, not hidden in a queue');
    });
  });

  group('skipping, finishing and leaving without a connection', () {
    testWidgets('skipping an exercise is queued and the workout moves to the next one', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      final batch = FakeBatch(w.server);
      _goOffline(w);

      await tester.tap(find.text('SKIP EXERCISE'));
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.textContaining('Exercise 2 of'), findsOneWidget);

      batch.offline = false;
      await tester.pump(const Duration(seconds: 20));
      await tester.pump(const Duration(milliseconds: 300));
      expect(batch.types, ['workout.exercise.skip']);
      expect(batch.received.single['payload'], {'session_id': 1, 'session_exercise_id': 1});
    });

    testWidgets('finishing is queued behind the sets; the summary shows only what the phone knows and says the rest comes later', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      final batch = FakeBatch(w.server);
      _goOffline(w);
      await _logSet(tester, label: 'START SET 1');

      await tester.tap(find.byTooltip('Leave workout'));
      await pumpUntil(tester, find.text('Leave this workout?'));
      await tester.pump(const Duration(milliseconds: 400)); // the sheet finishes sliding up
      await tester.tap(find.text('FINISH WORKOUT NOW'));
      await pumpUntil(tester, find.text('Workout finished'));

      expect(find.text('SAVED ON THIS PHONE'), findsOneWidget);
      expect(find.textContaining('XP, personal records and your streak are worked out there'), findsOneWidget);
      expect(find.text('Workout complete'), findsNothing, reason: 'no results were invented');
      expect(find.text('+0'), findsNothing);

      batch.offline = false;
      w.server.reply('GET', '/workout-sessions/1/summary', 200, {'data': summaryJson()});
      await tester.tap(find.text('SYNC NOW'));
      await pumpUntil(tester, find.text('Workout complete'));

      expect(batch.types, ['workout.set.log', 'workout.finish']);
      expect((batch.received[1]['payload'] as Map)['session_id'], 1);
      expect((batch.received[1]['payload'] as Map)['active_seconds'], isA<int>());
    });

    testWidgets('abandoning is queued too', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      final batch = FakeBatch(w.server);
      _goOffline(w);

      await tester.tap(find.byTooltip('Leave workout'));
      await pumpUntil(tester, find.text('Leave this workout?'));
      await tester.pump(const Duration(milliseconds: 400)); // the sheet finishes sliding up
      await tester.tap(find.text('ABANDON WORKOUT'));
      await pumpUntil(tester, find.text('Abandon workout?'));
      await tester.pump(const Duration(milliseconds: 400));
      await tester.tap(find.text('ABANDON'));
      await tester.pump(const Duration(milliseconds: 500));

      batch.offline = false;
      await tester.pump(const Duration(seconds: 20));
      await tester.pump(const Duration(milliseconds: 300));
      expect(batch.types, ['workout.abandon']);
    });
  });

  group('closing the app', () {
    testWidgets('a set waiting to sync is still counted and marked when the workout is opened again', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      FakeBatch(w.server);
      _goOffline(w);
      await _logSet(tester, label: 'START SET 1');

      // the app is closed and reopened (the queue is on the phone; everything else starts from scratch)
      await tester.pumpWidget(const SizedBox());
      await tester.pump();
      w.server.reply('GET', '/dashboard', 200, {'data': dashboardJson(openSessionId: 1)});
      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('RESUME WORKOUT'));
      await pumpUntil(tester, find.textContaining('Exercise 1 of'));

      expect(find.textContaining('1 change is saved on this phone and waiting to sync'), findsOneWidget);
      await tester.tap(find.text('SKIP REST')); // the rest that was running when the app was closed
      await pumpUntil(tester, find.text('SET 2 OF 3'));
      expect(find.byTooltip('Saved on this phone, waiting to sync'), findsOneWidget, reason: 'set 1 still counts, and is still marked');
    });
  });

  group('maintenance and required updates', () {
    testWidgets('a workout in progress can be carried on from the maintenance screen, from saved data, and its sets queue', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      final batch = FakeBatch(w.server);

      // leave the workout paused: its resume state stays on the phone
      await tester.tap(find.byTooltip('Leave workout'));
      await pumpUntil(tester, find.text('Leave this workout?'));
      await tester.pump(const Duration(milliseconds: 400));
      await tester.tap(find.text('PAUSE AND LEAVE'));
      await pumpUntilHome(tester);

      // the server goes into maintenance
      w.server.reply('GET', '/app/config', 200, {'data': configJson(maintenance: true)});
      w.server.reply('GET', '/workout-sessions/1', 503, errorJson('maintenance', 'Back soon.'));
      _goOffline(w);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await pumpUntil(tester, find.text('Back soon'));
      expect(find.text('CONTINUE MY WORKOUT'), findsOneWidget);

      await tester.tap(find.text('CONTINUE MY WORKOUT'));
      await pumpUntil(tester, find.textContaining('Exercise 1 of'));
      expect(find.text('Back soon'), findsNothing);

      await _logSet(tester, label: 'START SET 1');
      expect(find.textContaining('1 change is saved on this phone and waiting to sync'), findsOneWidget);
      expect(batch.received, isEmpty, reason: 'nothing is sent while the server is down');
    });

    testWidgets('with no workout in progress the maintenance screen offers no way around it', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/app/config', 200, {'data': configJson(maintenance: true)});
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntil(tester, find.text('Back soon'));
      expect(find.text('CONTINUE MY WORKOUT'), findsNothing);
    });
  });

  group('the sync screen and the account', () {
    testWidgets('Account shows how much is waiting, and signing out warns about it', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      FakeBatch(w.server);
      _goOffline(w);
      await _logSet(tester, label: 'START SET 1');

      await tester.tap(find.byTooltip('Leave workout'));
      await pumpUntil(tester, find.text('Leave this workout?'));
      await tester.pump(const Duration(milliseconds: 400)); // the sheet finishes sliding up
      await tester.tap(find.text('PAUSE AND LEAVE'));
      await pumpUntilHome(tester);
      await tester.tap(find.text('ACCOUNT'));
      await pumpUntil(tester, find.text('Sync'));
      expect(find.text('1 change is waiting to be sent'), findsOneWidget);

      await tapVisible(tester, find.text('SIGN OUT'));
      await pumpUntil(tester, find.text('Sign out?'));
      expect(find.textContaining('1 change has not reached the server yet'), findsOneWidget);
      expect(find.textContaining('stay on this phone and are sent the next time you sign in'), findsOneWidget);
    });

    testWidgets('a conflict shows both sides and lets the member choose', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      final batch = FakeBatch(w.server);
      batch.answer = (op) => {'op_id': op['op_id'], 'status': 'conflict', 'server': {'weight_kg': 65, 'reps': 6, 'updated_at': '2026-10-12T17:59:00+00:00'}};
      _goOffline(w);
      await _logSet(tester, label: 'START SET 1');

      batch.offline = false;
      await tester.pump(const Duration(seconds: 20));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.tap(find.textContaining('needs your attention'));
      await pumpUntil(tester, find.text('Needs a decision'));
      expect(find.textContaining('The server has: 65 kg, 6 reps.'), findsOneWidget);

      batch.answer = null;
      await tester.tap(find.text('USE MINE'));
      await tester.pump(const Duration(milliseconds: 500));
      expect(batch.received.length, 2, reason: 'sent again');
      expect(batch.received.last['op_id'], isNot(batch.received.first['op_id']), reason: 'as a new change, because the server remembers the first id as a conflict');
      expect(find.text('ALL SAVED'), findsOneWidget);
    });
  });
}
