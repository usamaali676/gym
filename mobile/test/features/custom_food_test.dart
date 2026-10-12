import 'package:flutter/material.dart' show TextField;
import 'package:dio/dio.dart';
import 'dart:ui' show Size;
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

/// The member's own foods: numbers per 100 g from a label. The server does the checking and the later maths.
class FoodsBackend {
  FoodsBackend(this.w) {
    w.server.on('GET', '/nutrition/day', (o) => FakeReply.json(200, {'data': nutritionDayJson()}));
    w.server.on('GET', '/foods/recent', (o) => FakeReply.json(200, {'data': <Object?>[]}));
    w.server.on('GET', '/foods/search', (o) => FakeReply.json(200, {'data': <Object?>[], 'meta': {}}));
    w.server.on('POST', '/foods', (o) {
      posts.add(Map<String, dynamic>.from(o.data as Map));
      return reply ?? FakeReply.json(201, {'data': foodJson(77, (o.data as Map)['name'] as String, kcal: 120, protein: 6, carbs: 18, fat: 3, custom: true)});
    });
    w.server.on('GET', '/foods/77', (o) => FakeReply.json(200, {'data': foodJson(77, 'Homemade granola', kcal: 120, protein: 6, carbs: 18, fat: 3, custom: true)}));
    w.server.on('POST', '/nutrition/calculate', (o) => FakeReply.json(200, {'data': {'food': foodJson(77, 'Homemade granola', custom: true), 'grams': 120, 'serving_label': null, 'macros': {'kcal': 144, 'protein_g': 7.2, 'carbs_g': 21.6, 'fat_g': 3.6, 'fibre_g': 0}}}));
  }

  final TestWorld w;
  final List<Map<String, dynamic>> posts = [];
  FakeReply? reply;
}

Future<FoodsBackend> openSearch(WidgetTester tester, TestWorld w, {String? typed}) async {
  w.serveSignedInMember();
  final b = FoodsBackend(w);
  await w.storeSession();
  await pumpApp(tester, w);
  await pumpUntilHome(tester);
  await tester.tap(find.text('FUEL'));
  await pumpUntil(tester, find.text('CALORIES'));
  await tapVisible(tester, find.text('Add food to lunch'));
  await pumpUntil(tester, find.text('Nothing logged recently'));
  if (typed != null) {
    await tester.enterText(find.byType(TextField), typed);
    await tester.pump(const Duration(milliseconds: 500));
    await pumpUntil(tester, find.text('No foods found'));
  }
  return b;
}

/// A tall screen so that every field of the form is built (a list only builds what is near the visible area).
void useTallScreen(WidgetTester tester) {
  tester.view.physicalSize = const Size(360, 1800);
  addTearDown(tester.view.reset);
}

/// The nine inputs in order: name, brand, kcal, protein, carbs, fat, fibre, serving name, serving grams.
Future<void> type(WidgetTester tester, Map<int, String> values) async {
  for (final e in values.entries) {
    await tester.enterText(find.byType(TextField).at(e.key), e.value);
  }
  await tester.pump();
}

void main() {
  testWidgets('"Add your own food" is always on the search screen, also when nothing was found, and carries the typed name', (tester) async {
    final w = await TestWorld.create();
    await openSearch(tester, w, typed: 'homemade granola');

    expect(find.text('ADD YOUR OWN FOOD'), findsOneWidget);
    await tester.tap(find.text('ADD YOUR OWN FOOD'));
    await pumpUntil(tester, find.text('DIET TYPE'));
    expect(tester.widgetList<TextField>(find.byType(TextField)).first.controller!.text, 'homemade granola');
  });

  testWidgets('saving sends the label numbers per 100 g, then opens the amount screen for the new food', (tester) async {
    final w = await TestWorld.create();
    final backend = await openSearch(tester, w);
    await tester.tap(find.text('ADD YOUR OWN FOOD'));
    await pumpUntil(tester, find.text('DIET TYPE'));
    useTallScreen(tester);
    await tester.pump(const Duration(milliseconds: 300));

    await type(tester, {0: 'Homemade granola', 1: 'Mum\'s', 2: '120', 3: '6', 4: '18', 5: '3', 6: '2', 7: '1 cup', 8: '90'});
    await tester.tap(find.text('Includes meat'));
    await tester.pump();
    await tester.tap(find.text('SAVE AND CHOOSE AMOUNT'));
    await pumpUntil(tester, find.text('THIS AMOUNT'));

    expect(backend.posts.single, {
      'name': 'Homemade granola',
      'brand': 'Mum\'s',
      'diet_type': 'non_veg',
      'kcal_100g': 120.0,
      'protein_100g': 6.0,
      'carbs_100g': 18.0,
      'fat_100g': 3.0,
      'fibre_100g': 2.0,
      'servings': [
        {'label': '1 cup', 'grams': 90.0},
      ],
    });
    expect(find.text('Homemade granola'), findsWidgets);
  });

  testWidgets('empty, non-numeric and half-filled forms are stopped on the phone with a message under the field, nothing is sent', (tester) async {
    final w = await TestWorld.create();
    final backend = await openSearch(tester, w);
    await tester.tap(find.text('ADD YOUR OWN FOOD'));
    await pumpUntil(tester, find.text('DIET TYPE'));
    useTallScreen(tester);
    await tester.pump(const Duration(milliseconds: 300));

    await tester.tap(find.text('SAVE AND CHOOSE AMOUNT'));
    await tester.pump();
    expect(find.text('Enter the food\'s name.'), findsOneWidget);
    expect(find.text('Choose a diet type.'), findsOneWidget);
    expect(find.text('Enter a number (0 if none).'), findsNWidgets(4));

    await type(tester, {0: 'Granola', 2: 'abc', 3: '6', 4: '18', 5: '3', 7: '1 cup'});
    await tester.tap(find.text('Includes meat'));
    await tester.tap(find.text('SAVE AND CHOOSE AMOUNT'));
    await tester.pump();
    expect(find.text('Use numbers only.'), findsOneWidget);
    expect(find.textContaining('Fill in both the serving name and its grams'), findsOneWidget);
    expect(backend.posts, isEmpty);
  });

  testWidgets('a number with a decimal comma is understood', (tester) async {
    final w = await TestWorld.create();
    final backend = await openSearch(tester, w);
    await tester.tap(find.text('ADD YOUR OWN FOOD'));
    await pumpUntil(tester, find.text('DIET TYPE'));
    useTallScreen(tester);
    await tester.pump(const Duration(milliseconds: 300));

    await type(tester, {0: 'Yoghurt', 2: '62,5', 3: '4,5', 4: '6', 5: '2,2'});
    await tester.tap(find.text('Vegetarian'));
    await tester.tap(find.text('SAVE AND CHOOSE AMOUNT'));
    await pumpUntil(tester, find.text('THIS AMOUNT'));
    expect(backend.posts.single['kcal_100g'], 62.5);
    expect(backend.posts.single['protein_100g'], 4.5);
    expect(backend.posts.single.containsKey('fibre_100g'), isFalse, reason: 'optional and left empty: not sent');
    expect(backend.posts.single.containsKey('servings'), isFalse);
  });

  testWidgets('the server\'s own check (calories that do not match the macros) is shown under the field and what was typed is kept', (tester) async {
    final w = await TestWorld.create();
    final backend = await openSearch(tester, w);
    backend.reply = FakeReply.json(422, errorJson('validation_failed', 'The given data was invalid.', details: {'kcal_100g': ['Calories do not match the macros (about 126 kcal expected from protein, carbs and fat). Check the numbers on the label.']}));
    await tester.tap(find.text('ADD YOUR OWN FOOD'));
    await pumpUntil(tester, find.text('DIET TYPE'));
    useTallScreen(tester);
    await tester.pump(const Duration(milliseconds: 300));

    await type(tester, {0: 'Granola', 2: '500', 3: '6', 4: '18', 5: '3'});
    await tester.tap(find.text('Includes meat'));
    await tester.tap(find.text('SAVE AND CHOOSE AMOUNT'));
    await pumpUntil(tester, find.textContaining('Calories do not match the macros'));

    expect(find.text('THIS AMOUNT'), findsNothing, reason: 'still on the form');
    expect(tester.widget<TextField>(find.byType(TextField).at(2)).controller!.text, '500');
  });

  testWidgets('without a connection it says so and keeps the form', (tester) async {
    final w = await TestWorld.create();
    final backend = await openSearch(tester, w);
    backend.reply = FakeReply.fail(DioExceptionType.connectionError);
    await tester.tap(find.text('ADD YOUR OWN FOOD'));
    await pumpUntil(tester, find.text('DIET TYPE'));
    useTallScreen(tester);
    await tester.pump(const Duration(milliseconds: 300));

    await type(tester, {0: 'Granola', 2: '120', 3: '6', 4: '18', 5: '3'});
    await tester.tap(find.text('Includes meat'));
    await tester.tap(find.text('SAVE AND CHOOSE AMOUNT'));
    await pumpUntil(tester, find.textContaining('needs a connection'));
    expect(tester.widget<TextField>(find.byType(TextField).first).controller!.text, 'Granola');
  });
}
