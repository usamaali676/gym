import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/harness.dart';
import '../support/pump_app.dart';

void main() {
  testWidgets('progress: overview, body and badges', (tester) async {
    final w = await TestWorld.create();
    w.serveSignedInMember();
    await w.storeSession();
    await pumpApp(tester, w);
    await pumpUntilHome(tester);
    await tester.tap(find.text('PROGRESS'));
    await pumpUntil(tester, find.text('WEIGHT'));
    await tester.pump(const Duration(milliseconds: 600));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('progress_overview.png'));

    await tester.tap(find.text('Body'));
    await pumpUntil(tester, find.text('LOG MEASUREMENT'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('progress_body.png'));

    await tester.tap(find.text('Badges'));
    await pumpUntil(tester, find.text('First Workout'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('progress_badges.png'));
  });
}
