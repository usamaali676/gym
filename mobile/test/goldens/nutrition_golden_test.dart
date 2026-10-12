import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../features/nutrition_test.dart' show NutritionBackend;
import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

void main() {
  testWidgets('fuel day and the food calculator', (tester) async {
    final w = await TestWorld.create();
    w.serveSignedInMember();
    final b = NutritionBackend(w);
    b.lunch.add(logEntryJson(101));
    await w.storeSession();
    await pumpApp(tester, w);
    await pumpUntilHome(tester);
    await tester.tap(find.text('FUEL'));
    await pumpUntil(tester, find.text('Chicken breast, cooked'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('fuel_day.png'));

    await tapVisible(tester, find.text('Add food to lunch'));
    await pumpUntil(tester, find.text('Chicken breast, cooked'));
    await tester.tap(find.text('Chicken breast, cooked'));
    await pumpUntil(tester, find.text('THIS AMOUNT'));
    await tester.pump(const Duration(milliseconds: 400));
    await expectLater(find.byType(MaterialApp), matchesGoldenFile('food_amount.png'));
  });
}
