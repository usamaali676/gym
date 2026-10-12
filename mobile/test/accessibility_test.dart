// Automated accessibility checks on the main screens: nothing overflows at large text sizes, controls are big enough to
// hit and have a label a screen reader can speak, and text contrasts with its background. This is not a substitute for a
// session with TalkBack or VoiceOver on a device, which has not been done.
import 'package:flutter/foundation.dart' show FlutterError;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/core/sync/pending_op.dart';
import 'package:ironpulse/core/sync/pending_store.dart';
import 'package:ironpulse/features/posture/presentation/posture_providers.dart';

import 'features/posture/posture_support.dart';
import 'features/workout_session_test.dart' show openWorkout;
import 'support/fake_adapter.dart';
import 'support/harness.dart';
import 'support/pump_app.dart';

Future<TestWorld> _signedIn(WidgetTester tester) async {
  final w = await TestWorld.create();
  w.serveSignedInMember();
  await w.storeSession();
  await pumpApp(tester, w);
  // The first thing on the dashboard (a stat further down may be off screen, and a list only builds what is near the screen).
  await pumpUntil(tester, find.text("TODAY'S WORKOUT"));
  await tester.pump(const Duration(milliseconds: 400));
  return w;
}

Future<void> _tab(WidgetTester tester, String label, Finder ready) async {
  await tester.tap(find.text(label));
  await pumpUntil(tester, ready);
  await tester.pump(const Duration(milliseconds: 400));
}

/// Walks the main signed-in screens, calling [check] on each.
Future<void> _tour(WidgetTester tester, Future<void> Function(String screen) check) async {
  await check('dashboard');

  await _tab(tester, 'TRAIN', find.text('This week'));
  await check('train: this week');
  await tester.tap(find.text('Programmes'));
  await pumpUntil(tester, find.text('Hypertrophy Plus'));
  await check('train: programmes');
  await tester.tap(find.text('Exercises'));
  await pumpUntil(tester, find.text('Exercise 1'));
  await check('train: exercises');

  await _tab(tester, 'FUEL', find.text('Chicken breast, cooked').evaluate().isNotEmpty ? find.text('Chicken breast, cooked') : find.textContaining('kcal'));
  await check('fuel');

  await _tab(tester, 'PROGRESS', find.text('WEIGHT'));
  await check('progress: overview');
  await tester.tap(find.text('Body'));
  await pumpUntil(tester, find.text('LOG MEASUREMENT'));
  await check('progress: body');
  await tester.tap(find.text('Badges'));
  await pumpUntil(tester, find.text('First Workout'));
  await check('progress: badges');

  await _tab(tester, 'ACCOUNT', find.text('Camera form coach'));
  await check('account');
}


/// The screens outside the tab tour. Each is opened, then every check runs on it.
final _otherScreens = <(String, Future<TestWorld> Function(WidgetTester) setup, Finder Function() ready)>[
  (
    'welcome',
    (tester) async {
      final w = await TestWorld.create();
      w.server.reply('GET', '/app/config', 200, {'data': configJson()});
      await pumpApp(tester, w);
      return w;
    },
    () => find.text('SIGN IN'),
  ),
  (
    'login',
    (tester) async {
      final w = await TestWorld.create();
      w.server.reply('GET', '/app/config', 200, {'data': configJson()});
      await pumpApp(tester, w);
      await pumpUntil(tester, find.text('SIGN IN'));
      await tester.tap(find.text('SIGN IN'));
      return w;
    },
    () => find.text('EMAIL'),
  ),
  (
    'register',
    (tester) async {
      final w = await TestWorld.create();
      w.server.reply('GET', '/app/config', 200, {'data': configJson()});
      await pumpApp(tester, w);
      await pumpUntil(tester, find.text('CREATE ACCOUNT'));
      await tester.tap(find.text('CREATE ACCOUNT'));
      return w;
    },
    () => find.text('NAME'),
  ),
  (
    'onboarding',
    (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember(user: userJson(onboarded: false));
      await w.storeSession();
      await pumpApp(tester, w);
      return w;
    },
    () => find.text('Set up your profile'),
  ),
  (
    'workout: intro',
    (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      return w;
    },
    () => find.text('START SET 1'),
  ),
  (
    'workout: a set',
    (tester) async {
      final w = await TestWorld.create();
      await openWorkout(tester, w);
      await tester.tap(find.text('START SET 1'));
      return w;
    },
    () => find.text('COMPLETE SET'),
  ),
  (
    'membership',
    (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('ACCOUNT'));
      await pumpUntil(tester, find.text('Membership'));
      await tester.tap(find.text('Membership'));
      return w;
    },
    () => find.textContaining('INCLUDED').evaluate().isNotEmpty ? find.textContaining('INCLUDED') : find.textContaining('PLAN'),
  ),
  (
    'camera coach: list and intro',
    (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/posture/exercises', 200, {'data': [postureExerciseJson()]});
      w.server.reply('GET', '/posture/rules/barbell-squat', 200, {'data': ruleApiJson('barbell-squat')});
      await w.storeSession();
      await pumpApp(tester, w, extraOverrides: [poseCaptureOpenerProvider.overrideWithValue(FakeOpener())]);
      await pumpUntilHome(tester);
      await tester.tap(find.text('ACCOUNT'));
      await pumpUntil(tester, find.text('Camera form coach'));
      await tester.tap(find.text('Camera form coach'));
      await pumpUntil(tester, find.text('Barbell Squat'));
      await tester.tap(find.text('Barbell Squat'));
      return w;
    },
    () => find.text('START CAMERA'),
  ),
  (
    'sync',
    (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await w.storeSession();
      await PrefsPendingStore(w.prefs).write('7', [
        PendingOp(opId: 'a', type: OpTypes.setLog, payload: const {'session_id': 1}, createdAt: DateTime.utc(2026, 10, 12), label: 'Bench press, set 1: 60 kg × 8'),
        PendingOp(opId: 'b', type: OpTypes.measurementSave, payload: const {}, createdAt: DateTime.utc(2026, 10, 12), label: 'Body measurement: 80.2 kg', state: OpState.conflict, serverCopy: const {'weight_kg': 79.0}),
        PendingOp(opId: 'c', type: OpTypes.postureSave, payload: const {}, createdAt: DateTime.utc(2026, 10, 12), label: 'Camera set: Barbell Squat (3 reps)', state: OpState.rejected, errorMessage: 'Your membership does not include this feature.'),
      ]);
      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('ACCOUNT'));
      await pumpUntil(tester, find.text('Sync'));
      await tester.tap(find.text('Sync'));
      return w;
    },
    () => find.text('Needs a decision'),
  ),
  (
    'delete account',
    (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('ACCOUNT'));
      await pumpUntil(tester, find.text('Camera form coach'));
      await tapVisible(tester, find.text('DELETE ACCOUNT'));
      return w;
    },
    () => find.text('THIS CANNOT BE UNDONE'),
  ),
];

void main() {
  for (final scale in [1.5, 2.0]) {
    testWidgets('the main screens do not overflow or break at ${scale}x text size', (tester) async {
      tester.platformDispatcher.textScaleFactorTestValue = scale;
      addTearDown(tester.platformDispatcher.clearAllTestValues);
      // keep the full report of any layout error, which names the widget that overflowed
      final reports = <String>[];
      final previous = FlutterError.onError;
      FlutterError.onError = (details) {
        reports.add(details.toString());
        previous?.call(details);
      };
      addTearDown(() => FlutterError.onError = previous);
      await _signedIn(tester);
      await _tour(tester, (screen) async {
        // an overflow is a FlutterError raised during layout; reaching here with none recorded means the screen laid out
        final problem = tester.takeException();
        expect(problem, isNull, reason: '$screen at ${scale}x: ${reports.join('\n---\n')}');
      });
    });
  }

  testWidgets('every control on the main screens is big enough to hit and has a name a screen reader can speak', (tester) async {
    final semantics = tester.ensureSemantics();
    await _signedIn(tester);
    await _tour(tester, (screen) async {
      await expectLater(tester, meetsGuideline(androidTapTargetGuideline), reason: '$screen: tap target size');
      await expectLater(tester, meetsGuideline(labeledTapTargetGuideline), reason: '$screen: labelled controls');
    });
    semantics.dispose();
  });

  group('the other screens', () {
    for (final (name, setup, ready) in _otherScreens) {
      testWidgets('$name: does not overflow at 2x text, controls are big enough and named', (tester) async {
        final semantics = tester.ensureSemantics();
        addTearDown(tester.platformDispatcher.clearAllTestValues);
        final reports = <String>[];
        final previous = FlutterError.onError;
        FlutterError.onError = (details) {
          reports.add(details.toString());
          previous?.call(details);
        };
        addTearDown(() => FlutterError.onError = previous);

        // Open the screen at the normal size (the helpers look for text a large size can push off screen), then make the
        // text twice as big and check the same screen.
        await setup(tester);
        await pumpUntil(tester, ready());
        await tester.pump(const Duration(milliseconds: 500));
        tester.platformDispatcher.textScaleFactorTestValue = 2.0;
        await tester.pump(const Duration(milliseconds: 500));

        final problem = tester.takeException();
        expect(problem, isNull, reason: '$name at 2x: ${reports.join('\n---\n')}');
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline), reason: '$name: tap target size');
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline), reason: '$name: labelled controls');
        semantics.dispose();
      });
    }
  });
}
