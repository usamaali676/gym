import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../features/posture/posture_rig.dart';
import '../features/posture/posture_support.dart';
import '../support/pump_app.dart';

void main() {
  testWidgets('camera coach: intro, a live squat with the skeleton, and the summary', (tester) async {
    final rig = await openPosture(tester);
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('posture_intro.png'));

    await startCamera(tester);
    final end = await intoLiveSet(tester, rig.opener, scenarioFrames('squat_clean'));

    // the bottom of the first squat: the skeleton is drawn over the (fake, dark) camera picture
    final frames = scenarioFrames('squat_clean', timeOffsetMs: end + 67);
    await feedFrames(tester, rig.opener, frames.where((f) => f.tMs - frames.first.tMs <= 2500));
    await tester.pump(const Duration(milliseconds: 200));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('posture_live.png'));

    await feedFrames(tester, rig.opener, frames.where((f) => f.tMs - frames.first.tMs > 2500));
    await tester.tap(find.text('FINISH'));
    await pumpUntil(tester, find.textContaining('Saved.'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('posture_summary.png'));
  });

  testWidgets('camera coach: a set with form problems shows what to work on', (tester) async {
    final rig = await openPosture(tester);
    await startCamera(tester);
    const id = 'squat_shallow_lean_heels_mirrored';
    final end = await intoLiveSet(tester, rig.opener, scenarioFrames(id));
    final frames = scenarioFrames(id, timeOffsetMs: end + 67);
    await feedFrames(tester, rig.opener, frames.where((f) => f.tMs - frames.first.tMs <= 4500));
    await tester.pump(const Duration(milliseconds: 200));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('posture_live_problem.png'));

    await feedFrames(tester, rig.opener, frames.where((f) => f.tMs - frames.first.tMs > 4500));
    await tester.tap(find.text('FINISH'));
    await pumpUntil(tester, find.textContaining('Saved.'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('posture_summary_problems.png'));
  });
}
