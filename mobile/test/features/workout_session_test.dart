import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

/// Opens the signed-in app with a workout in progress and taps "Resume workout".
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

/// Lets the 500 ms workout ticker and the 250 ms live timers fire after the test clock moved.
Future<void> elapse(WidgetTester tester, TestWorld w, Duration d) async {
  w.clock.advance(d);
  await tester.pump(const Duration(milliseconds: 700));
}

void main() {
  setUpAll(() {
    TestWidgetsFlutterBinding.ensureInitialized();
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async => null);
  });

  group('starting', () {
    testWidgets('"Start workout" on the dashboard starts a session on the server and opens the intro', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      final backend = FakeWorkoutBackend(w.server);
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);

      await tester.tap(find.text('START WORKOUT'));
      await pumpUntil(tester, find.text('Bench press'));

      final post = w.server.adapter.requests.firstWhere((r) => r.method == 'POST' && r.path == '/workout-sessions');
      final body = post.data as Map;
      expect(body.containsKey('weekday'), isFalse, reason: 'the server decides what "today" is');
      expect(body['client_uuid'], isNotNull);
      expect(post.headers['Idempotency-Key'], isNotNull);
      expect(find.text('START SET 1'), findsOneWidget);
      expect(find.text('Last time: 57.5 kg × 8, 8, 7'), findsOneWidget);
      expect(find.text('Today: 60 kg × 8'), findsOneWidget);
      expect(find.text('• Shoulder blades together'), findsOneWidget);
      expect(backend.setPosts, isEmpty);
    });

    testWidgets('a second workout already open is resumed instead of showing an error', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      FakeWorkoutBackend(w.server);
      w.server.reply('POST', '/workout-sessions', 409, errorJson('session_in_progress', 'Finish or abandon your workout in progress first.', details: {'session_id': 1}));
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);

      await tester.tap(find.text('START WORKOUT'));
      await pumpUntil(tester, find.text('Bench press'));
    });

    testWidgets('the screen is kept awake while the workout is open', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      expect(w.feedback.events, contains('awake'));
    });
  });

  group('a set', () {
    testWidgets('is pre-filled, logged on the server with the numbers shown, then a rest starts', (tester) async {
      final w = await TestWorld.create();
      final backend = await openWorkout(tester, w);

      await tester.tap(find.text('START SET 1'));
      await pumpUntil(tester, find.text('SET 1 OF 3'));
      expect(find.text('60'), findsOneWidget); // weight
      expect(find.text('8'), findsOneWidget); // reps

      await tester.tap(find.byTooltip('Increase Weight'));
      await tester.pump();
      await tester.tap(find.text('COMPLETE SET'));
      await pumpUntil(tester, find.text('REST'));

      expect(backend.setPosts.single, {'session_exercise_id': 1, 'set_number': 1, 'weight_kg': 62.5, 'reps': 8});
      expect(w.feedback.events, contains('setLogged'));
      expect(find.text('01:30'), findsOneWidget);
      expect(find.text('Set 2 of 3'), findsOneWidget);
      expect(find.text('Last set: 62.5 kg × 8'), findsOneWidget);
    });

    testWidgets('a failure keeps the member on the set with their numbers, shows why, and works on retry', (tester) async {
      final w = await TestWorld.create();
      final backend = await openWorkout(tester, w);
      backend.failSets = 1;

      await tester.tap(find.text('START SET 1'));
      await pumpUntil(tester, find.text('SET 1 OF 3'));
      await tester.tap(find.byTooltip('Increase Reps'));
      await tester.pump();
      await tester.tap(find.text('COMPLETE SET'));
      await pumpUntil(tester, find.text('The set could not be saved.'));

      expect(find.text('SET 1 OF 3'), findsOneWidget, reason: 'still on the set');
      expect(find.text('9'), findsOneWidget, reason: 'the 9 reps they entered are still there');
      expect(find.text('REST'), findsNothing);

      await tester.tap(find.text('COMPLETE SET'));
      await pumpUntil(tester, find.text('REST'));
      expect(backend.setPosts.map((b) => b['reps']), [9, 9]);
      expect(find.text('The set could not be saved.'), findsNothing);
    });

    testWidgets('the next set is pre-filled from the one just done', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      await tester.tap(find.text('START SET 1'));
      await pumpUntil(tester, find.text('SET 1 OF 3'));
      await tester.tap(find.byTooltip('Increase Weight'));
      await tester.pump();
      await tester.tap(find.text('COMPLETE SET'));
      await pumpUntil(tester, find.text('REST'));

      await tester.tap(find.text('SKIP REST'));
      await pumpUntil(tester, find.text('SET 2 OF 3'));
      expect(find.text('62.5'), findsOneWidget);
    });
  });

  group('the rest timer', () {
    Future<TestWorld> inRest(WidgetTester tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      await tester.tap(find.text('START SET 1'));
      await pumpUntil(tester, find.text('SET 1 OF 3'));
      await tester.tap(find.text('COMPLETE SET'));
      await pumpUntil(tester, find.text('REST'));
      return w;
    }

    testWidgets('counts down from the clock and moves on by itself, with a buzz', (tester) async {
      final w = await inRest(tester);
      expect(find.text('01:30'), findsOneWidget);

      await elapse(tester, w, const Duration(seconds: 30));
      expect(find.text('01:00'), findsOneWidget);

      await elapse(tester, w, const Duration(seconds: 61));
      await pumpUntil(tester, find.text('SET 2 OF 3'));
      expect(w.feedback.events, contains('restOver'));
    });

    testWidgets('the phone in a pocket for ten minutes: coming back, the rest is over and the next set is ready', (tester) async {
      final w = await inRest(tester);

      w.clock.advance(const Duration(minutes: 10)); // no frames, no ticks: the app was in the background
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();
      await pumpUntil(tester, find.text('SET 2 OF 3'));
    });

    testWidgets('+30 s adds to the rest and Skip rest ends it', (tester) async {
      final w = await inRest(tester);
      await tester.tap(find.text('+30 S'));
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.text('02:00'), findsOneWidget);

      await tester.tap(find.text('SKIP REST'));
      await pumpUntil(tester, find.text('SET 2 OF 3'));
      expect(w.feedback.events, isNot(contains('restOver')), reason: 'skipping is the member\'s own choice, no buzz');
    });

    testWidgets('pausing freezes the rest for as long as the pause lasts, resuming continues with the same time', (tester) async {
      final w = await inRest(tester);
      await elapse(tester, w, const Duration(seconds: 30)); // 60 s left

      await tester.tap(find.byTooltip('Pause'));
      await pumpUntil(tester, find.text('Paused'));
      expect(find.textContaining('01:00 left'), findsOneWidget);

      await elapse(tester, w, const Duration(minutes: 20));
      expect(find.text('Paused'), findsOneWidget, reason: 'a paused rest never ends by itself');

      await tester.tap(find.text('RESUME'));
      await pumpUntil(tester, find.text('REST'));
      expect(find.text('01:00'), findsOneWidget);
    });

    testWidgets('the elapsed workout time excludes the pause', (tester) async {
      final w = await inRest(tester);
      await elapse(tester, w, const Duration(minutes: 1)); // 1:00 of work + rest
      await tester.tap(find.byTooltip('Pause'));
      await pumpUntil(tester, find.text('Paused'));
      await elapse(tester, w, const Duration(minutes: 30));
      await tester.tap(find.text('RESUME'));
      await pumpUntil(tester, find.text('REST'));
      expect(find.text('01:00'), findsWidgets);
    });
  });

  group('resume after the app was killed', () {
    testWidgets('a rest in progress comes back with the right time left', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      await tester.tap(find.text('START SET 1'));
      await pumpUntil(tester, find.text('SET 1 OF 3'));
      await tester.tap(find.text('COMPLETE SET'));
      await pumpUntil(tester, find.text('REST'));
      await tester.pump(const Duration(milliseconds: 100));

      // "kill" the app: drop the whole widget tree, keep the phone's storage and the server's data
      await tester.pumpWidget(const SizedBox());
      await tester.pump();
      w.clock.advance(const Duration(seconds: 40));

      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('RESUME WORKOUT'));
      await pumpUntil(tester, find.text('REST'));
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.text('00:50'), findsOneWidget);
    });

    testWidgets('the server\'s copy of the state is used when the phone has none (another device)', (tester) async {
      final w = await TestWorld.create();
      final restEnds = w.clock.now.add(const Duration(seconds: 75));
      final session = sessionJson(
        exercises: [
          sessionExerciseJson(1, 'Bench press', logged: [
            {'id': 1, 'number': 1, 'weight_kg': 60, 'reps': 8, 'duration_seconds': null, 'rpe': null, 'is_warmup': false, 'is_pr': false, 'completed_at': '2026-10-12T18:01:00+00:00'},
          ]),
          sessionExerciseJson(2, 'Plank', sets: 1, rest: 0, timed: true, hold: 30),
        ],
        clientState: {'idx': 0, 'setIdx': 1, 'phase': 'rest', 'rest_ends_at': restEnds.toIso8601String(), 'paused_ms': 0, 'active_ms': 60000, 'saved_at': w.clock.now.toIso8601String()},
      );
      await openWorkout(tester, w, session: session);
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.text('REST'), findsOneWidget);
      expect(find.text('01:15'), findsOneWidget);
    });

    testWidgets('sets already logged on the server move the workout forward, whatever the saved position says', (tester) async {
      final w = await TestWorld.create();
      final session = sessionJson(
        exercises: [
          sessionExerciseJson(1, 'Bench press', logged: [
            for (var n = 1; n <= 3; n++) {'id': n, 'number': n, 'weight_kg': 60, 'reps': 8, 'duration_seconds': null, 'rpe': null, 'is_warmup': false, 'is_pr': false, 'completed_at': '2026-10-12T18:01:00+00:00'},
          ], status: 'done'),
          sessionExerciseJson(2, 'Plank', sets: 1, rest: 0, timed: true, hold: 30),
        ],
        clientState: {'idx': 0, 'setIdx': 0, 'phase': 'intro', 'paused_ms': 0, 'saved_at': w.clock.now.toIso8601String()},
      );
      w.serveSignedInMember();
      w.server.reply('GET', '/dashboard', 200, {'data': dashboardJson(openSessionId: 1)});
      FakeWorkoutBackend(w.server, session: session);
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('RESUME WORKOUT'));
      await pumpUntil(tester, find.text('Plank'));
      expect(find.text('START SET 1'), findsOneWidget);
    });
  });

  group('exercises', () {
    testWidgets('after the last set of an exercise comes a rest, then the next exercise\'s intro', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      for (var set = 1; set <= 3; set++) {
        await tester.tap(find.text(set == 1 ? 'START SET 1' : 'SKIP REST'));
        await pumpUntil(tester, find.text('SET $set OF 3'));
        await tester.tap(find.text('COMPLETE SET'));
        await pumpUntil(tester, find.text('REST'));
      }
      expect(find.text('New exercise · 1 × hold'), findsOneWidget);
      await tester.tap(find.text('SKIP REST'));
      await pumpUntil(tester, find.text('Plank'));
      expect(find.textContaining('Exercise 2 of 2'), findsOneWidget);
      expect(find.text('START SET 1'), findsOneWidget);
    });

    testWidgets('skipping an exercise tells the server and opens the next one', (tester) async {
      final w = await TestWorld.create();
      final backend = await openWorkout(tester, w);

      await tester.tap(find.text('SKIP EXERCISE'));
      await pumpUntil(tester, find.text('Plank'));
      expect(backend.skips, [1]);
    });

    testWidgets('a timed hold counts down from when it began and is logged as its duration', (tester) async {
      final w = await TestWorld.create();
      final backend = await openWorkout(
        tester,
        w,
        session: sessionJson(exercises: [sessionExerciseJson(1, 'Plank', sets: 1, rest: 0, timed: true, hold: 30), sessionExerciseJson(2, 'Bench press')]),
      );
      await tester.tap(find.text('START SET 1'));
      await pumpUntil(tester, find.text('SET 1 OF 1'));
      expect(find.text('00:30'), findsOneWidget);

      await elapse(tester, w, const Duration(seconds: 12));
      expect(find.text('00:18'), findsOneWidget);

      await elapse(tester, w, const Duration(seconds: 18));
      expect(find.text('00:00'), findsOneWidget);
      await tester.tap(find.text('COMPLETE HOLD'));
      await pumpUntil(tester, find.textContaining('Bench press'));
      expect(backend.setPosts.single['duration_seconds'], 30);
    });
  });

  group('finishing', () {
    Future<(TestWorld, FakeWorkoutBackend)> finishAll(WidgetTester tester) async {
      final w = await TestWorld.create();
      final backend = await openWorkout(tester, w, session: sessionJson(exercises: [sessionExerciseJson(1, 'Bench press', sets: 1, rest: 60)]));
      await tester.tap(find.text('START SET 1'));
      await pumpUntil(tester, find.text('SET 1 OF 1'));
      await tester.tap(find.text('COMPLETE SET'));
      await pumpUntil(tester, find.text('All sets done'));
      return (w, backend);
    }

    testWidgets('the last set leads straight to a finish screen, no pointless rest', (tester) async {
      final (_, backend) = await finishAll(tester);
      expect(find.text('REST'), findsNothing);
      expect(find.text('1 exercise trained · 1 sets'), findsOneWidget);
      expect(backend.finishPosts, isEmpty);
    });

    testWidgets('finishing sends the active time (without pauses) and shows the server-computed results', (tester) async {
      final (w, backend) = await finishAll(tester);
      await elapse(tester, w, const Duration(minutes: 20));

      await tester.tap(find.text('FINISH WORKOUT'));
      await pumpUntil(tester, find.text('Workout complete'));

      expect(backend.finishPosts.single['active_seconds'], 1200);
      expect(find.text('LEVEL UP! You are level 4'), findsOneWidget);
      expect(find.text('Bench press: 62.5 kg × 8'), findsOneWidget);
      expect(find.text('+190'), findsOneWidget);
      await tester.dragUntilVisible(find.text('First workout'), find.byType(ListView), const Offset(0, -200));
      expect(find.text('Set complete'), findsOneWidget);
      expect(find.text('30 min 00 s'), findsOneWidget);

      await tester.tap(find.text('DONE'));
      await pumpUntilHome(tester);
    });

    testWidgets('finishing refreshes the home screen data', (tester) async {
      await finishAll(tester);
      await tester.tap(find.text('FINISH WORKOUT'));
      await pumpUntil(tester, find.text('Workout complete'));
    });
  });

  group('leaving', () {
    testWidgets('"Pause and leave" saves the state and returns home; the workout can be resumed', (tester) async {
      final w = await TestWorld.create();
      final backend = await openWorkout(tester, w);
      await tester.tap(find.text('START SET 1'));
      await pumpUntil(tester, find.text('SET 1 OF 3'));

      await tester.tap(find.byTooltip('Leave workout'));
      await pumpUntil(tester, find.text('Leave this workout?'));
      await tester.pump(const Duration(milliseconds: 400));
      await tester.tap(find.text('PAUSE AND LEAVE'));
      await pumpUntilHome(tester);

      expect(backend.statePuts.last['phase'], 'paused');
      expect(backend.abandons, 0);
    });

    testWidgets('abandoning asks to confirm, then tells the server', (tester) async {
      final w = await TestWorld.create();
      final backend = await openWorkout(tester, w);

      await tester.tap(find.byTooltip('Leave workout'));
      await pumpUntil(tester, find.text('Leave this workout?'));
      await tester.pump(const Duration(milliseconds: 400));
      await tester.tap(find.text('ABANDON WORKOUT'));
      await pumpUntil(tester, find.text('Abandon workout?'));
      await tester.tap(find.text('Cancel'));
      await tester.pump(const Duration(milliseconds: 400));
      expect(backend.abandons, 0);

      await tester.tap(find.byTooltip('Leave workout'));
      await pumpUntil(tester, find.text('Leave this workout?'));
      await tester.pump(const Duration(milliseconds: 400));
      await tester.tap(find.text('ABANDON WORKOUT'));
      await pumpUntil(tester, find.text('Abandon workout?'));
      await tester.tap(find.text('ABANDON'));
      await pumpUntilHome(tester);
      expect(backend.abandons, 1);
    });

    testWidgets('"Finish workout now" is offered only once something was logged', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      await tester.tap(find.byTooltip('Leave workout'));
      await pumpUntil(tester, find.text('Leave this workout?'));
      expect(find.text('FINISH WORKOUT NOW'), findsNothing);
      await tester.pump(const Duration(milliseconds: 400));
      await tester.tap(find.text('KEEP GOING'));
      await tester.pump(const Duration(milliseconds: 400));
    });
  });

  group('state saved while working out', () {
    testWidgets('every change goes to the server in the fields the API accepts, with absolute times', (tester) async {
      final w = await TestWorld.create();
      final backend = await openWorkout(tester, w);
      await tester.tap(find.text('START SET 1'));
      await pumpUntil(tester, find.text('SET 1 OF 3'));
      await tester.tap(find.text('COMPLETE SET'));
      await pumpUntil(tester, find.text('REST'));
      await tester.pump(const Duration(milliseconds: 100));

      final last = backend.statePuts.last;
      expect(last['phase'], 'rest');
      expect((last['idx'], last['setIdx']), (0, 1));
      expect(last['rest_ends_at'], w.clock.now.add(const Duration(seconds: 90)).toIso8601String());
      expect(last.keys.toSet(), {'idx', 'setIdx', 'phase', 'rest_ends_at', 'paused_ms', 'active_ms', 'saved_at'});
    });

    testWidgets('a workout whose state could not be saved on the server still works (the phone keeps it)', (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      w.server.reply('PUT', '/workout-sessions/1/state', 500, errorJson('server_error', 'nope'));

      await tester.tap(find.text('START SET 1'));
      await pumpUntil(tester, find.text('SET 1 OF 3'));
      expect(find.text('The set could not be saved.'), findsNothing);
    });
  });
}
