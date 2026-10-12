import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

/// Design QA for the immersive workout screens: intro, set entry, rest, paused, finish and the results.
void main() {
  setUpAll(() {
    TestWidgetsFlutterBinding.ensureInitialized();
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async => null);
  });

  testWidgets('workout phases', (tester) async {
    final w = await TestWorld.create();
    w.serveSignedInMember();
    w.server.reply('GET', '/dashboard', 200, {'data': dashboardJson(openSessionId: 1)});
    FakeWorkoutBackend(w.server);
    await w.storeSession();
    await pumpApp(tester, w);
    await pumpUntilHome(tester);

    await tester.tap(find.text('RESUME WORKOUT'));
    await pumpUntil(tester, find.text('START SET 1'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('workout_intro.png'));

    await tester.tap(find.text('START SET 1'));
    await pumpUntil(tester, find.text('SET 1 OF 3'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('workout_set.png'));

    await tester.tap(find.text('COMPLETE SET'));
    await pumpUntil(tester, find.text('REST'));
    w.clock.advance(const Duration(seconds: 25));
    await tester.pump(const Duration(milliseconds: 700));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('workout_rest.png'));

    await tester.tap(find.byTooltip('Pause'));
    await pumpUntil(tester, find.text('Paused'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('workout_paused.png'));
  });

  testWidgets('finish and results', (tester) async {
    final w = await TestWorld.create();
    w.serveSignedInMember();
    w.server.reply('GET', '/dashboard', 200, {'data': dashboardJson(openSessionId: 1)});
    FakeWorkoutBackend(w.server, session: sessionJson(exercises: [sessionExerciseJson(1, 'Bench press', sets: 1, rest: 60)]));
    await w.storeSession();
    await pumpApp(tester, w);
    await pumpUntilHome(tester);
    await tester.tap(find.text('RESUME WORKOUT'));
    await pumpUntil(tester, find.text('START SET 1'));
    await tester.tap(find.text('START SET 1'));
    await pumpUntil(tester, find.text('SET 1 OF 1'));
    await tester.tap(find.text('COMPLETE SET'));
    await pumpUntil(tester, find.text('All sets done'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('workout_finish.png'));

    await tester.tap(find.text('FINISH WORKOUT'));
    await pumpUntil(tester, find.text('Workout complete'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('workout_summary.png'));
  });
}
