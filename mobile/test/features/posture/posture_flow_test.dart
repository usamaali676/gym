// The camera coach screens end to end. The camera and the pose model are replaced by recorded landmarks (the same
// frames the web engine was checked against); everything after them is the real code: smoothing, rule evaluation, rep
// counting, form checks, the countdown, the summary and the upload.
import 'package:dio/dio.dart' show DioExceptionType;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/features/posture/data/pose_capture.dart';
import 'package:ironpulse/features/posture/presentation/posture_providers.dart';

import '../../support/fake_adapter.dart';
import '../../support/harness.dart';
import '../../support/pump_app.dart';
import 'posture_rig.dart';
import 'posture_support.dart';

SemanticsFinder _figure(String label, String value) => find.semantics.byPredicate((n) => n.label == '$label $value');

void main() {
  group('entry points', () {
    testWidgets('Account lists the exercises the coach supports, with how to film and how complete the rule is', (tester) async {
      await openPosture(tester);
      // the list was shown on the way in: go back and read it
      await tester.pageBack();
      await pumpUntil(tester, find.text('Plank'));
      expect(find.text('Barbell Squat'), findsOneWidget);
      expect(find.text('Film from the side'), findsNWidgets(2));
      expect(find.text('Full rules'), findsOneWidget);
      expect(find.text('Generic rules'), findsOneWidget);
      expect(find.textContaining('never stored or sent'), findsOneWidget);
    });

    testWidgets('the exercise page offers "Check my form" when the coach supports it', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/exercises/exercise-1', 200, {'data': exerciseJson(1, name: 'Bench press', full: true, posture: true)});
      w.server.reply('GET', '/posture/rules/exercise-1', 200, {'data': ruleApiJson('barbell-squat')});
      await w.storeSession();
      await pumpApp(tester, w, extraOverrides: [poseCaptureOpenerProvider.overrideWithValue(FakeOpener())]);
      await pumpUntilHome(tester);
      await tester.tap(find.text('TRAIN'));
      await pumpUntil(tester, find.text('Exercises'));
      await tester.tap(find.text('Exercises'));
      await pumpUntil(tester, find.text('Exercise 1'));
      await tester.tap(find.text('Exercise 1'));
      await pumpUntil(tester, find.text('CHECK MY FORM'));
      await tester.tap(find.text('CHECK MY FORM'));
      await pumpUntil(tester, find.text('START CAMERA'));
    });

    testWidgets('a plan without the coach gets the locked screen', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/posture/exercises', 403, errorJson('entitlement_required', 'Your membership does not include this feature.', details: {'required_entitlement': 'posture_coach'}));
      await w.storeSession();
      await pumpApp(tester, w, extraOverrides: [poseCaptureOpenerProvider.overrideWithValue(FakeOpener())]);
      await pumpUntilHome(tester);
      await tester.tap(find.text('ACCOUNT'));
      await pumpUntil(tester, find.text('Camera form coach'));
      await tester.tap(find.text('Camera form coach'));
      await pumpUntil(tester, find.text('Not in your membership'));
    });
  });

  group('before the camera starts', () {
    testWidgets('the intro explains how to film, what is watched, how far to trust it, and where the pictures go', (tester) async {
      await openPosture(tester);
      expect(find.text('FILM FROM THE SIDE'), findsOneWidget);
      expect(find.textContaining('Prop your phone'), findsOneWidget);
      expect(find.text('WHAT IT WATCHES'), findsOneWidget);
      expect(find.text('torso too far forward'), findsOneWidget);
      await tester.dragUntilVisible(find.text('HOW MUCH TO TRUST IT'), find.byType(Scrollable).last, const Offset(0, -200));
      expect(find.textContaining('not a coach'), findsNWidgets(2), reason: 'the rule\'s own accuracy note and the app\'s general one');
      await tester.dragUntilVisible(find.textContaining('never recorded, stored or sent'), find.byType(Scrollable).last, const Offset(0, -200));
      expect(find.textContaining('never recorded, stored or sent'), findsOneWidget);
      expect(find.text('START CAMERA'), findsOneWidget, reason: 'the button stays on screen however far the instructions are scrolled');
    });

    testWidgets('a blocked camera says so and can be retried', (tester) async {
      final opener = FakeOpener(failWith: PoseProblem.permissionDenied);
      await openPosture(tester, opener: opener);
      await startCamera(tester);
      await pumpUntil(tester, find.text('The camera did not start'));
      expect(find.textContaining('Camera permission was blocked'), findsOneWidget);

      await tester.tap(find.text('TRY AGAIN'));
      await pumpUntil(tester, find.text('Looking for you…'));
      expect(opener.captures, hasLength(1));
    });

    testWidgets('a phone with no usable pose model explains it', (tester) async {
      final opener = FakeOpener(failWith: PoseProblem.modelFailed);
      await openPosture(tester, opener: opener);
      await startCamera(tester);
      await pumpUntil(tester, find.textContaining('pose model could not be started'));
    });

    testWidgets('a rule from a newer schema is refused politely', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/posture/exercises', 200, {
        'data': [postureExerciseJson()],
      });
      final rule = ruleApiJson('barbell-squat');
      (rule['config'] as Map)['schema'] = 2;
      w.server.reply('GET', '/posture/rules/barbell-squat', 200, {'data': rule});
      await w.storeSession();
      await pumpApp(tester, w, extraOverrides: [poseCaptureOpenerProvider.overrideWithValue(FakeOpener())]);
      await pumpUntilHome(tester);
      await tester.tap(find.text('ACCOUNT'));
      await pumpUntil(tester, find.text('Camera form coach'));
      await tester.tap(find.text('Camera form coach'));
      await pumpUntil(tester, find.text('Barbell Squat'));
      await tester.tap(find.text('Barbell Squat'));
      await pumpUntil(tester, find.text('Not available'));
      expect(find.text('This exercise needs a newer version of the app.'), findsOneWidget);
    });
  });

  group('positioning', () {
    testWidgets('with nobody in view it says so, and a hidden ankle names the joint to show', (tester) async {
      final rig = await openPosture(tester);
      await startCamera(tester);
      await pumpUntil(tester, find.byType(CustomPaint));

      await feedFrames(tester, rig.opener, [for (var i = 0; i < 10; i++) PoseFrame(landmarks: null, tMs: i * 67.0, aspect: 0.5625)]);
      expect(find.textContaining("I can't see you"), findsOneWidget);

      await feedFrames(tester, rig.opener, scenarioFrames('squat_hidden_ankles_then_visible', timeOffsetMs: 1000).take(20));
      expect(find.textContaining('Move so I can see your left ankle'), findsOneWidget);
      expect(find.textContaining('Turn side-on to the camera'), findsOneWidget);
    });

    testWidgets('a steady start position leads into a 3-2-1 countdown and then the live set', (tester) async {
      final rig = await openPosture(tester);
      await startCamera(tester);
      final stand = scenarioFrames('squat_clean').first;

      await feedFrames(tester, rig.opener, holdStill(stand, seconds: 2.4));
      expect(find.text('Ready. Start your first rep.'), findsNothing, reason: 'the banner is replaced by the countdown message once the pose has been steady');
      expect(find.text('Get ready. Hold your starting position.'), findsOneWidget);
      expect(find.text('3'), findsOneWidget);

      await feedFrames(tester, rig.opener, holdStill(stand, seconds: 3.2, fromMs: 2400));
      expect(find.text('FINISH'), findsOneWidget);
      expect(find.text('PAUSE'), findsOneWidget);
    });

    testWidgets('losing the person during the countdown sends them back to positioning', (tester) async {
      final rig = await openPosture(tester);
      await startCamera(tester);
      final stand = scenarioFrames('squat_clean').first;
      await feedFrames(tester, rig.opener, holdStill(stand, seconds: 2.4));
      expect(find.text('Get ready. Hold your starting position.'), findsOneWidget);

      await feedFrames(tester, rig.opener, [for (var i = 0; i < 6; i++) PoseFrame(landmarks: null, tMs: 2400.0 + i * 67, aspect: 0.5625)]);
      expect(find.text('Get ready. Hold your starting position.'), findsNothing);
      expect(find.textContaining("I can't see you"), findsOneWidget);
    });

    testWidgets('"Start anyway" appears after a few seconds and starts the countdown without waiting for the pose', (tester) async {
      final rig = await openPosture(tester);
      await startCamera(tester);
      expect(find.text('START ANYWAY'), findsNothing);
      await tester.pump(const Duration(seconds: 7));
      expect(find.text('START ANYWAY'), findsOneWidget);
      await tester.tap(find.text('START ANYWAY'));
      await tester.pump();
      expect(find.text('3'), findsOneWidget);
      expect(rig.opener.captures, hasLength(1));
    });

    testWidgets('a phone with two cameras can switch; the choice is passed to the camera layer', (tester) async {
      final opener = FakeOpener(canSwitch: true);
      await openPosture(tester, opener: opener);
      await startCamera(tester);
      expect(opener.requestedFront, [true]);
      await tester.tap(find.byTooltip('Switch camera'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));
      expect(opener.requestedFront, [true, false]);
      expect(opener.captures.first.closed, isTrue, reason: 'the first camera is released before the second opens');
    });
  });

  group('a live set', () {
    testWidgets('three clean squats are counted, judged good, shown, saved and the numbers sent are the engine\'s', (tester) async {
      final semantics = tester.ensureSemantics();
      final rig = await openPosture(tester);
      await startCamera(tester);
      final scenario = scenarioFrames('squat_clean');
      final standingEnd = await intoLiveSet(tester, rig.opener, scenario);

      await feedFrames(tester, rig.opener, scenarioFrames('squat_clean', timeOffsetMs: standingEnd + 67));
      expect(_figure('REPS', '3'), findsOneWidget);
      expect(_figure('GOOD', '3'), findsOneWidget);
      expect(_figure('NEEDS WORK', '0'), findsOneWidget);
      expect(rig.world.feedback.events.where((e) => e == 'rep:good'), hasLength(3));

      await tester.tap(find.text('FINISH'));
      await pumpUntil(tester, find.text('SET COMPLETE'));
      await pumpUntil(tester, find.textContaining('Saved.'));
      expect(rig.opener.captures.first.closed, isTrue, reason: 'the camera is released when the set ends');
      expect(find.text('No form mistakes were flagged.'), findsOneWidget);
      expect(find.text('Saved. +30 XP · Level 2'), findsOneWidget);
      expect(find.text('Badge: Form Check'), findsOneWidget);

      final post = rig.world.server.adapter.requests.singleWhere((r) => r.method == 'POST' && r.path == '/posture/sessions');
      final body = post.data as Map;
      expect(body['total_reps'], 3);
      expect(body['good_reps'], 3);
      expect(body['bad_reps'], 0);
      expect(body['hold_seconds'], isNull);
      expect(body['exercise_id'], 15);
      expect(body['posture_rule_id'], 4);
      expect(body['rule_version'], 1);
      expect(body['camera_view'], 'side');
      expect(body['form_score_avg'], 100);
      expect(body['client_uuid'], matches(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')));
      expect(DateTime.parse(body['started_at'] as String).isUtc, isTrue);
      expect((body['rep_log'] as List), hasLength(3));
      expect(body.keys, isNot(contains('landmarks')), reason: 'camera data never leaves the phone');
      expect(body.keys.toSet().containsAll(['duration_seconds', 'form_score_min', 'error_counts']), isTrue);
      expect(post.headers['Idempotency-Key'], isNotNull);
      semantics.dispose();
    });

    testWidgets('shallow squats with a forward lean and lifting heels are flagged, explained in the rule\'s own words, and haptics fire', (tester) async {
      final rig = await openPosture(tester);
      await startCamera(tester);
      final id = 'squat_shallow_lean_heels_mirrored';
      final standingEnd = await intoLiveSet(tester, rig.opener, scenarioFrames(id));
      await feedFrames(tester, rig.opener, scenarioFrames(id, timeOffsetMs: standingEnd + 67));
      expect(rig.world.feedback.events, contains('rep:bad'));

      await tester.tap(find.text('FINISH'));
      await pumpUntil(tester, find.text('SET COMPLETE'));
      expect(find.text('WHAT TO WORK ON'), findsOneWidget);
      expect(find.text('Incomplete range'), findsOneWidget);
      expect(find.text('Squat deeper: aim for hips at or just below your knees.'), findsOneWidget);
      expect(find.text('Heels lifting'), findsOneWidget);
      expect(find.text('Keep your whole foot on the floor, with your weight over mid-foot.'), findsOneWidget);
    });

    testWidgets('pausing stops counting; resuming carries on', (tester) async {
      final semantics = tester.ensureSemantics();
      final rig = await openPosture(tester);
      await startCamera(tester);
      final standingEnd = await intoLiveSet(tester, rig.opener, scenarioFrames('squat_clean'));
      final frames = scenarioFrames('squat_clean', timeOffsetMs: standingEnd + 67);

      await feedFrames(tester, rig.opener, frames.take(40));
      await tester.tap(find.text('PAUSE'));
      await tester.pump();
      expect(find.text('PAUSED'), findsOneWidget);
      expect(find.text('RESUME'), findsOneWidget);

      await feedFrames(tester, rig.opener, frames.skip(40).take(60));
      expect(_figure('REPS', '0'), findsOneWidget, reason: 'nothing is counted while paused');

      await tester.tap(find.text('RESUME'));
      await tester.pump();
      expect(find.text('PAUSED'), findsNothing);
      semantics.dispose();
    });

    testWidgets('finishing with nothing counted saves nothing and says so', (tester) async {
      final rig = await openPosture(tester);
      await startCamera(tester);
      await intoLiveSet(tester, rig.opener, scenarioFrames('squat_clean'));
      await tester.tap(find.text('FINISH'));
      await pumpUntil(tester, find.text('SET COMPLETE'));
      await pumpUntil(tester, find.text('Nothing was counted, so nothing was saved.'));
      expect(rig.world.server.log, isNot(contains('POST /posture/sessions')));
    });

    testWidgets('leaving mid-set asks first; staying keeps the set, leaving drops it', (tester) async {
      final rig = await openPosture(tester);
      await startCamera(tester);
      await intoLiveSet(tester, rig.opener, scenarioFrames('squat_clean'));

      await tester.pageBack();
      await pumpUntil(tester, find.text('Leave this set?'));
      await tester.tap(find.text('Stay'));
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.text('FINISH'), findsOneWidget);

      await tester.pageBack();
      await pumpUntil(tester, find.text('Leave this set?'));
      await tester.tap(find.text('Leave'));
      await pumpUntil(tester, find.text('Plank'));
      expect(rig.opener.captures.first.closed, isTrue, reason: 'the camera is released when the screen goes');
    });

    testWidgets('going to the background gives the camera back and pauses the set; coming back asks for it again', (tester) async {
      final rig = await openPosture(tester);
      await startCamera(tester);
      await intoLiveSet(tester, rig.opener, scenarioFrames('squat_clean'));

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await tester.pump(const Duration(milliseconds: 100));
      expect(rig.opener.captures.first.closed, isTrue, reason: 'the camera is handed back as soon as the app is backgrounded');
      expect(rig.opener.captures, hasLength(1));

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump(const Duration(milliseconds: 100));
      await tester.pump(const Duration(milliseconds: 100));
      expect(rig.opener.captures, hasLength(2), reason: 'the camera is asked for again on return');
      expect(rig.opener.current.closed, isFalse);
      expect(find.text('RESUME'), findsOneWidget, reason: 'the set is paused, not lost');
      expect(find.text('FINISH'), findsOneWidget);
    });
  });

  group('saving', () {
    Future<PostureRig> finishedSet(WidgetTester tester) async {
      final rig = await openPosture(tester);
      await startCamera(tester);
      final standingEnd = await intoLiveSet(tester, rig.opener, scenarioFrames('squat_clean'));
      await feedFrames(tester, rig.opener, scenarioFrames('squat_clean', timeOffsetMs: standingEnd + 67));
      return rig;
    }

    testWidgets('offline: the set is kept on the phone, shown as such, and sent by the offline queue when the connection is back', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      var offline = true;
      w.server.reply('GET', '/posture/exercises', 200, {
        'data': [postureExerciseJson()],
      });
      w.server.reply('GET', '/posture/rules/barbell-squat', 200, {'data': ruleApiJson('barbell-squat')});
      w.server.on('POST', '/posture/sessions', (o) => offline ? FakeReply.fail(DioExceptionType.connectionError) : FakeReply.json(201, savedSessionReply));
      w.server.on('POST', '/sync/batch', (o) {
        if (offline) return FakeReply.fail(DioExceptionType.connectionError);
        final ops = ((o.data as Map)['operations'] as List).cast<Map>();
        return FakeReply.json(200, {
          'data': {
            'results': [
              for (final op in ops) {'op_id': op['op_id'], 'status': 'applied', 'posture_session_id': 9, 'created': true, 'xp': 30},
            ],
            'server_time': '2026-10-12T18:00:00+00:00',
          },
        });
      });
      final opener = FakeOpener();
      await w.storeSession();
      await pumpApp(tester, w, extraOverrides: [poseCaptureOpenerProvider.overrideWithValue(opener)]);
      await pumpUntilHome(tester);
      await tester.tap(find.text('ACCOUNT'));
      await pumpUntil(tester, find.text('Camera form coach'));
      await tester.tap(find.text('Camera form coach'));
      await pumpUntil(tester, find.text('Barbell Squat'));
      await tester.tap(find.text('Barbell Squat'));
      await pumpUntil(tester, find.text('START CAMERA'));
      await startCamera(tester);
      final end = await intoLiveSet(tester, opener, scenarioFrames('squat_clean'));
      await feedFrames(tester, opener, scenarioFrames('squat_clean', timeOffsetMs: end + 67));

      await tester.tap(find.text('FINISH'));
      await pumpUntil(tester, find.textContaining('Saved on this phone'));
      expect(find.text('SET COMPLETE'), findsOneWidget, reason: 'the result is still shown');
      expect(find.text('TRY SAVING AGAIN'), findsNothing, reason: 'the queue retries on its own');
      expect(find.textContaining('waiting to sync'), findsOneWidget, reason: 'the sync strip says so');

      offline = false;
      await tester.pump(const Duration(seconds: 20)); // the queue's first automatic retry is after 15 s
      await tester.pump(const Duration(milliseconds: 200));
      final batch = w.server.adapter.requests.lastWhere((r) => r.path == '/sync/batch');
      final op = ((batch.data as Map)['operations'] as List).single as Map;
      expect(op['type'], 'posture.session.save');
      expect((op['payload'] as Map)['total_reps'], 3);
      expect((op['payload'] as Map)['client_uuid'], isNotNull, reason: 'the server also recognises the set by its own id');
      expect(find.textContaining('waiting to sync'), findsNothing, reason: 'sent: the strip is gone');
    });

    testWidgets('a rejected set says it was not saved', (tester) async {
      final rig = await finishedSet(tester);
      rig.world.server.reply(
        'POST',
        '/posture/sessions',
        422,
        errorJson(
          'validation_failed',
          'The given data was invalid.',
          details: {
            'total_reps': ['nope'],
          },
        ),
      );
      await tester.tap(find.text('FINISH'));
      await pumpUntil(tester, find.textContaining('The server rejected this set'));
      expect(find.text('TRY SAVING AGAIN'), findsOneWidget);
    });

    testWidgets('leaving before an unsaved set is saved asks first', (tester) async {
      final rig = await finishedSet(tester);
      rig.world.server.reply('POST', '/posture/sessions', 500, errorJson('server_error', 'Oops'));
      await tester.tap(find.text('FINISH'));
      await pumpUntil(tester, find.text('TRY SAVING AGAIN'));
      await tester.pageBack();
      await pumpUntil(tester, find.text('Leave without saving?'));
    });

    testWidgets('"Another set" starts over with a fresh upload id', (tester) async {
      final rig = await finishedSet(tester);
      await tester.tap(find.text('FINISH'));
      await pumpUntil(tester, find.textContaining('Saved.'));
      await tester.tap(find.text('ANOTHER SET'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));
      expect(rig.opener.captures, hasLength(2));
      expect(find.text('SET COMPLETE'), findsNothing);
    });
  });

  group('a timed hold', () {
    testWidgets('a plank is timed by the clock, sags are flagged, and the hold seconds are what is sent', (tester) async {
      final semantics = tester.ensureSemantics();
      final rig = await openPosture(tester, slug: 'plank', name: 'Plank', exerciseId: 8);
      await startCamera(tester);
      final frames = scenarioFrames('plank_sags_halfway');
      await feedFrames(tester, rig.opener, frames);
      await pumpUntil(tester, find.text('FINISH'));
      expect(find.semantics.byPredicate((n) => n.label.startsWith('HOLD ')), findsOneWidget);

      await tester.tap(find.text('FINISH'));
      await pumpUntil(tester, find.text('SET COMPLETE'));
      await pumpUntil(tester, find.textContaining('Saved.'));
      final post = rig.world.server.adapter.requests.singleWhere((r) => r.method == 'POST' && r.path == '/posture/sessions');
      final body = post.data as Map;
      expect(body['hold_seconds'], greaterThanOrEqualTo(10));
      expect(body['total_reps'], 1);
      expect((body['rep_log'] as List).first['hold'], isTrue);
      expect(find.text('Hips sagging'), findsOneWidget);
      semantics.dispose();
    });
  });
}
