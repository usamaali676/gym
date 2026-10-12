// Shared by the posture flow and golden tests: open the coach through the real navigation, drive the fake camera.
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/features/posture/data/pose_capture.dart';
import 'package:ironpulse/features/posture/presentation/posture_providers.dart';

import '../../support/harness.dart';
import '../../support/pump_app.dart';
import 'posture_support.dart';

const savedSessionReply = {
  'data': {
    'id': 9,
    'created': true,
    'xp': {'session': 10, 'reps': 20, 'total': 30},
    'badges': [
      {'slug': 'posture-first', 'name': 'Form Check', 'icon': 'scan', 'tier': 'bronze', 'xp_reward': 25},
    ],
    'level': {'level': 2, 'into': 30, 'needed': 150, 'pct': 0.2, 'next_at': 150, 'xp_total': 130},
  },
};

class PostureRig {
  PostureRig(this.world, this.opener);

  final TestWorld world;
  final FakeOpener opener;
}

Future<PostureRig> openPosture(WidgetTester tester, {FakeOpener? opener, String slug = 'barbell-squat', String name = 'Barbell Squat', int exerciseId = 15}) async {
  final w = await TestWorld.create();
  w.serveSignedInMember();
  w.server.reply('GET', '/posture/exercises', 200, {
    'data': [
      postureExerciseJson(id: exerciseId, slug: slug, name: name),
      slug == 'plank' ? postureExerciseJson() : postureExerciseJson(id: 8, slug: 'plank', name: 'Plank', group: 'core', tier: 'generic'),
    ],
  });
  w.server.reply('GET', '/posture/rules/$slug', 200, {'data': ruleApiJson(slug, exerciseId: exerciseId, name: name)});
  w.server.reply('GET', '/posture/rules/plank', 200, {'data': ruleApiJson('plank', exerciseId: 8, name: 'Plank')});
  w.server.reply('POST', '/posture/sessions', 201, savedSessionReply);
  final o = opener ?? FakeOpener();
  await w.storeSession();
  await pumpApp(tester, w, extraOverrides: [poseCaptureOpenerProvider.overrideWithValue(o)]);
  await pumpUntilHome(tester);

  await tester.tap(find.text('ACCOUNT'));
  await pumpUntil(tester, find.text('Camera form coach'));
  await tester.tap(find.text('Camera form coach'));
  await pumpUntil(tester, find.text(name));
  await tester.tap(find.text(name));
  await pumpUntil(tester, find.text('START CAMERA'));
  return PostureRig(w, o);
}

Future<void> startCamera(WidgetTester tester) async {
  await tester.tap(find.text('START CAMERA'));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 100));
}

/// Pushes [frames] into the camera one by one, letting the app's clock advance by the time between them.
Future<double> feedFrames(WidgetTester tester, FakeOpener opener, Iterable<PoseFrame> frames) async {
  double? previous;
  var last = 0.0;
  for (final f in frames) {
    opener.current.emit(f);
    final dt = previous == null ? 67.0 : (f.tMs - previous).clamp(1.0, 500.0);
    previous = f.tMs;
    last = f.tMs;
    await tester.pump(Duration(milliseconds: dt.round()));
  }
  return last;
}

/// Stand still until the app has seen the start position, counted down and gone live.
Future<double> intoLiveSet(WidgetTester tester, FakeOpener opener, List<PoseFrame> scenario) async {
  final end = await feedFrames(tester, opener, holdStill(scenario.first, seconds: 5.5));
  expect(find.text('FINISH'), findsOneWidget, reason: 'the countdown should have finished and the set should be live');
  return end;
}
