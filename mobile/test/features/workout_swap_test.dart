import 'package:dio/dio.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

Future<FakeWorkoutBackend> openWorkout(WidgetTester tester, TestWorld w, {Map<String, dynamic>? session}) async {
  w.serveSignedInMember();
  w.server.reply('GET', '/dashboard', 200, {'data': dashboardJson(openSessionId: 1)});
  final backend = FakeWorkoutBackend(w.server, session: session);
  await w.storeSession();
  await pumpApp(tester, w);
  await pumpUntilHome(tester);
  await tester.tap(find.text('RESUME WORKOUT'));
  await pumpUntil(tester, find.textContaining('Exercise 1 of'));
  return backend;
}

void main() {
  setUpAll(() {
    TestWidgetsFlutterBinding.ensureInitialized();
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async => null);
  });

  testWidgets('the server\'s alternatives are listed with their equipment; choosing one replaces the exercise and keeps the position', (tester) async {
    final w = await TestWorld.create();
    final backend = await openWorkout(tester, w);
    expect(find.text('Bench press'), findsOneWidget);

    await tapVisible(tester, find.text('SWAP EXERCISE'));
    await pumpUntil(tester, find.text('Dumbbell bench press'));
    await tester.pump(const Duration(milliseconds: 400)); // the sheet has finished sliding in
    expect(find.text('Machine chest press'), findsOneWidget);
    expect(find.textContaining('Your plan is not changed for next time'), findsOneWidget);
    expect(backend.alternativeGets, 1);

    await tester.tap(find.text('Machine chest press'));
    await pumpUntil(tester, find.text('Swapped from Bench press'));
    await tester.pump(const Duration(milliseconds: 400)); // the sheet has finished closing

    expect(backend.swapPosts.single['exercise_id'], 902);
    expect(find.text('Machine chest press'), findsOneWidget, reason: 'the title changed');
    expect(find.textContaining('Exercise 1 of'), findsOneWidget, reason: 'still on the first exercise');
    expect(find.text('START SET 1'), findsOneWidget);
    expect(find.text('Swap to Dumbbell bench press'), findsNothing, reason: 'the sheet closed');
  });

  testWidgets('no swap button once a set has been logged for the exercise (the server would refuse)', (tester) async {
    final w = await TestWorld.create();
    final session = sessionJson(exercises: [
      sessionExerciseJson(1, 'Bench press', logged: [
        {'id': 1, 'number': 1, 'weight_kg': 60, 'reps': 8, 'duration_seconds': null, 'rpe': null, 'is_warmup': false, 'is_pr': false, 'completed_at': '2026-10-12T18:01:00+00:00'},
      ]),
      sessionExerciseJson(2, 'Plank', sets: 1, rest: 0, timed: true, hold: 30),
    ]);
    await openWorkout(tester, w, session: session);

    expect(find.text('SET 2 OF 3'), findsOneWidget, reason: 'resumed in the middle of the exercise: the set screen, not the intro');
    expect(find.text('SWAP EXERCISE'), findsNothing);
  });

  testWidgets('an exercise the server says cannot be swapped has no button', (tester) async {
    final w = await TestWorld.create();
    final session = sessionJson();
    final exercises = List<Map<String, dynamic>>.from((session['exercises'] as List).map((e) => Map<String, dynamic>.from(e as Map)));
    exercises[0]['can_swap'] = false;
    await openWorkout(tester, w, session: {...session, 'exercises': exercises});
    expect(find.text('START SET 1'), findsOneWidget);
    expect(find.text('SWAP EXERCISE'), findsNothing);
  });

  testWidgets('without a connection it says so, offers Try again, and nothing is swapped', (tester) async {
    final w = await TestWorld.create();
    final backend = await openWorkout(tester, w);
    backend.alternativesReply = FakeReply.fail(DioExceptionType.connectionError);

    await tapVisible(tester, find.text('SWAP EXERCISE'));
    await pumpUntil(tester, find.textContaining('Swapping needs a connection'));
    await tester.pump(const Duration(milliseconds: 400));
    expect(backend.swapPosts, isEmpty);

    backend.alternativesReply = null;
    await tester.tap(find.text('TRY AGAIN'));
    await pumpUntil(tester, find.text('Dumbbell bench press'));
  });

  testWidgets('a refusal from the server is shown in the sheet and the exercise stays', (tester) async {
    final w = await TestWorld.create();
    final backend = await openWorkout(tester, w);
    backend.swapRefusal = 'That exercise is not a valid alternative for this move.';

    await tapVisible(tester, find.text('SWAP EXERCISE'));
    await pumpUntil(tester, find.text('Dumbbell bench press'));
    await tester.pump(const Duration(milliseconds: 400));
    await tester.tap(find.text('Dumbbell bench press'));
    await pumpUntil(tester, find.textContaining('not a valid alternative'));

    expect(find.text('Machine chest press'), findsOneWidget, reason: 'the sheet is still open to choose again');
    expect(backend.session['exercises'][0]['name'], 'Bench press');
  });

  testWidgets('an empty list says there is no alternative', (tester) async {
    final w = await TestWorld.create();
    final backend = await openWorkout(tester, w);
    backend.alternativeOptions = [];

    await tapVisible(tester, find.text('SWAP EXERCISE'));
    await pumpUntil(tester, find.textContaining('no alternative for this move'));
  });
}
