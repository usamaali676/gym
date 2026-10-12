import 'package:flutter/material.dart' show TextField;

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/features/nutrition/domain/nutrition.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

/// Records what the app asks the server, and answers the way the server does: the amount is converted there.
class NutritionBackend {
  NutritionBackend(this.w) {
    w.server.on('GET', '/nutrition/day', (o) => FakeReply.json(200, {'data': nutritionDayJson(lunch: List.of(lunch), eaten: lunch.fold<double>(0, (n, e) => n + (e['kcal'] as num)))}));
    w.server.on('GET', '/foods/recent', (o) => FakeReply.json(200, {'data': [foodJson(1, 'Chicken breast, cooked')]}));
    w.server.on('GET', '/foods/favorites', (o) => FakeReply.json(200, {'data': favourites.map((i) => foodJson(i, 'Favourite $i')).toList()}));
    w.server.on('GET', '/foods/search', (o) {
      searches.add(o.uri.queryParameters['q'] ?? '');
      final q = (o.uri.queryParameters['q'] ?? '').toLowerCase();
      final all = [foodJson(1, 'Chicken breast, cooked'), foodJson(2, 'Chicken biryani', kcal: 175, protein: 9, carbs: 21, fat: 6)];
      return FakeReply.json(200, {'data': all.where((f) => (f['name'] as String).toLowerCase().contains(q)).toList(), 'meta': {}});
    });
    w.server.on('GET', '/foods/1', (o) => FakeReply.json(200, {'data': foodJson(1, 'Chicken breast, cooked', protein: 20)}));
    w.server.on('POST', '/nutrition/calculate', (o) {
      final b = Map<String, dynamic>.from(o.data as Map);
      calculations.add(b);
      final grams = b['unit'] == 'grams' ? (b['quantity'] as num).toDouble() : (b['serving_id'] == 12 ? 150.0 : 120.0) * (b['quantity'] as num).toDouble();
      final f = foodJson(1, 'Chicken breast, cooked', protein: 20);
      return FakeReply.json(200, {
        'data': {
          'food': f,
          'grams': grams,
          'serving_label': b['unit'] == 'serving' ? (b['serving_id'] == 12 ? '1 small breast (150 g)' : '1 palm-size piece (120 g)') : null,
          'macros': {'kcal': grams * 1.65, 'protein_g': grams * proteinFactor, 'carbs_g': 0, 'fat_g': grams * 0.036, 'fibre_g': 0},
        },
      });
    });
    w.server.on('POST', '/food-logs', (o) {
      final b = Map<String, dynamic>.from(o.data as Map);
      logs.add(b);
      lunch.add(logEntryJson(100 + logs.length, meal: b['meal'] as String));
      return FakeReply.json(201, {'data': {'log': lunch.last, 'day': {}}});
    });
    w.server.on('PATCH', '/food-logs/101', (o) {
      patches.add(Map<String, dynamic>.from(o.data as Map));
      return FakeReply.json(200, {'data': {'log': lunch.first, 'day': {}}});
    });
    w.server.on('DELETE', '/food-logs/101', (o) {
      deletes++;
      lunch.clear();
      return FakeReply.json(200, {'data': {}});
    });
    w.server.on('PUT', '/foods/1/favorite', (o) {
      favourites.add(1);
      return FakeReply.json(200, {'data': {'food_id': 1, 'favorite': true}});
    });
    w.server.on('DELETE', '/foods/1/favorite', (o) {
      favourites.remove(1);
      return FakeReply.json(200, {'data': {'food_id': 1, 'favorite': false}});
    });
  }

  final TestWorld w;
  final List<Map<String, dynamic>> lunch = [];
  final List<Map<String, dynamic>> logs = [];
  final List<Map<String, dynamic>> calculations = [];
  final List<Map<String, dynamic>> patches = [];
  final List<String> searches = [];
  final Set<int> favourites = {};
  int deletes = 0;

  /// grams -> protein. 0.2 is 20 g per 100 g.
  double proteinFactor = 0.2;
}

Future<NutritionBackend> openFuel(WidgetTester tester, TestWorld w) async {
  w.serveSignedInMember();
  final backend = NutritionBackend(w);
  await w.storeSession();
  await pumpApp(tester, w);
  await pumpUntilHome(tester);
  await tester.tap(find.text('FUEL'));
  await pumpUntil(tester, find.text('CALORIES'));
  return backend;
}

void main() {
  group('models', () {
    test('a day parses totals, targets, remaining, ideas and meals (energy is "calories" in totals, "kcal" in entries)', () {
      final d = NutritionDay.fromJson(nutritionDayJson(lunch: [logEntryJson(1)], eaten: 247.5));
      expect(d.totals.calories, 247.5);
      expect(d.targets!.calories, 2400);
      expect(d.remaining!.calories, 2152.5);
      expect(d.meals.map((m) => m.meal), ['breakfast', 'lunch', 'snack', 'dinner']);
      expect(d.meals[1].entries.single.macros.calories, 247.5);
      expect(d.meals[1].entries.single.amountLabel, '150 g');
      expect(d.ideas.single.name, 'Prawns, cooked');
    });

    test('a member whose targets cannot be built still gets a day', () {
      final d = NutritionDay.fromJson(nutritionDayJson(targets: false));
      expect(d.targets, isNull);
      expect(d.remaining, isNull);
    });

    test('servings: the default one is picked first', () {
      final f = Food.fromJson(foodJson(1, 'x'));
      expect(f.defaultServing!.label, '1 palm-size piece (120 g)');
    });
  });

  group('day', () {
    testWidgets('shows calories and macros against the targets, the meals and the ideas', (tester) async {
      final w = await TestWorld.create();
      final b = await openFuel(tester, w);
      b.lunch.add(logEntryJson(101));
      await tester.fling(find.text('CALORIES'), const Offset(0, 400), 1000);
      await pumpUntil(tester, find.text('Chicken breast, cooked'));

      expect(find.text('248'), findsWidgets); // 247.5 rounds (total and the entry's own calories)
      expect(find.text(' / 2400 kcal'), findsOneWidget);
      expect(find.text('2153 kcal left'), findsOneWidget);
      await tester.ensureVisible(find.text('150 g · 47 g protein'));
      expect(find.text('150 g · 47 g protein'), findsOneWidget);
      expect(find.text('LUNCH'), findsOneWidget);
      expect(find.text('Training day'), findsOneWidget);
    });

    testWidgets('over the target is said in words', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      NutritionBackend(w);
      w.server.reply('GET', '/nutrition/day', 200, {'data': nutritionDayJson(eaten: 2700)});
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('FUEL'));
      await pumpUntil(tester, find.text('300 kcal over your target'));
    });

    testWidgets('a member whose targets could not be built is told what to do, and can still see what they ate', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      NutritionBackend(w);
      w.server.reply('GET', '/nutrition/day', 200, {'data': nutritionDayJson(targets: false)});
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('FUEL'));
      await pumpUntil(tester, find.textContaining('could not be built yet'));
    });

    testWidgets('previous day asks the server for that date; Next is unavailable on today', (tester) async {
      final w = await TestWorld.create();
      final b = await openFuel(tester, w);
      expect(b.w, w);

      w.server.on('GET', '/nutrition/day', (o) => FakeReply.json(200, {'data': nutritionDayJson(date: o.uri.queryParameters['date'] ?? '2026-10-12')}));
      await tester.tap(find.byTooltip('Previous day'));
      await pumpUntil(tester, find.text('Oct 11, 2026'));
      expect(w.server.adapter.requests.where((r) => r.path == '/nutrition/day').last.uri.queryParameters['date'], '2026-10-11');
      expect(find.text('TODAY'), findsNothing);
    });

    testWidgets('a plan without nutrition gets the locked screen', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/nutrition/day', 403, errorJson('entitlement_required', 'Your membership does not include this feature.', details: {'required_entitlement': 'nutrition'}));
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('FUEL'));
      await pumpUntil(tester, find.text('Not in your membership'));
      expect(find.textContaining('Nutrition and food tracking is not included'), findsOneWidget);
    });

    testWidgets('offline shows the last day that was loaded, marked as saved data', (tester) async {
      final w = await TestWorld.create();
      await openFuel(tester, w);
      await tester.pump(const Duration(milliseconds: 300));

      w.server.on('GET', '/nutrition/day', (o) => FakeReply.fail(DioExceptionType.connectionError));
      await tester.fling(find.text('CALORIES'), const Offset(0, 400), 1000);
      await pumpUntil(tester, find.textContaining('Offline. Showing data saved at'));
    });
  });

  group('adding food', () {
    Future<NutritionBackend> openAmount(WidgetTester tester, TestWorld w) async {
      final b = await openFuel(tester, w);
      await tapVisible(tester, find.text('Add food to lunch'));
      await pumpUntil(tester, find.text('Chicken breast, cooked'));
      await tester.tap(find.text('Chicken breast, cooked'));
      await pumpUntil(tester, find.text('THIS AMOUNT'));
      await tester.pump(const Duration(milliseconds: 400));
      return b;
    }

    testWidgets('search is debounced, runs on the server and shows per-100 g values', (tester) async {
      final w = await TestWorld.create();
      final b = await openFuel(tester, w);
      await tapVisible(tester, find.text('Add food to lunch'));
      await pumpUntil(tester, find.text('Chicken breast, cooked'));

      await tester.enterText(find.byType(TextField), 'c');
      await tester.pump(const Duration(milliseconds: 100));
      await tester.enterText(find.byType(TextField), 'chick');
      await tester.pump(const Duration(milliseconds: 500));
      await pumpUntil(tester, find.text('Chicken biryani'));

      expect(b.searches, ['chick']);
      expect(find.text('175 kcal · 9 g protein per 100 g'), findsOneWidget);
    });

    testWidgets('a serving is the default; the calculator shows the server\'s figures and the breakdown', (tester) async {
      final w = await TestWorld.create();
      final b = await openAmount(tester, w);

      expect(b.calculations.first, {'food_id': 1, 'quantity': 1.0, 'unit': 'serving', 'serving_id': 11});
      expect(find.text('120 g'), findsOneWidget);
      expect(find.text('120 g × 20 g protein per 100 g = 24 g protein'), findsOneWidget);
    });

    testWidgets('150 g of a food with 20 g protein per 100 g is 30 g protein: the number comes from the server', (tester) async {
      final w = await TestWorld.create();
      final b = await openAmount(tester, w);

      await tester.tap(find.text('Grams'));
      await tester.pump(const Duration(milliseconds: 500));
      await pumpUntil(tester, find.text('THIS AMOUNT'));
      // 120 g carried over from the serving; set 150 g
      await tester.enterText(find.byType(TextField).last, '150');
      await tester.pump(const Duration(milliseconds: 500));
      await pumpUntil(tester, find.text('150 g × 20 g protein per 100 g = 30 g protein'));

      expect(b.calculations.last['quantity'], 150.0);
      expect(b.calculations.last['unit'], 'grams');
    });

    testWidgets('the app does no food maths: whatever number the server returns is what is shown', (tester) async {
      final w = await TestWorld.create();
      final b = await openAmount(tester, w);
      b.proteinFactor = 0.2583; // the server's answer is not the naive product

      await tester.tap(find.text('Grams'));
      await tester.pump(const Duration(milliseconds: 500));
      await tester.enterText(find.byType(TextField).last, '150');
      await tester.pump(const Duration(milliseconds: 500));
      await pumpUntil(tester, find.text('150 g × 20 g protein per 100 g = 38.7 g protein'));
    });

    testWidgets('logging sends the amount, the meal and the day, once, and the day shows it', (tester) async {
      final w = await TestWorld.create();
      final b = await openAmount(tester, w);

      await tapVisible(tester, find.text('LOG LUNCH'));
      await pumpUntil(tester, find.text('LUNCH'));
      await pumpUntil(tester, find.text('Chicken breast, cooked'));

      expect(b.logs, hasLength(1));
      expect(b.logs.single['meal'], 'lunch');
      expect(b.logs.single['unit'], 'serving');
      expect(b.logs.single['serving_id'], 11);
      expect(b.logs.single.containsKey('kcal'), isFalse, reason: 'the app never sends macros, only the amount');
      expect(b.logs.single['logged_on'], isNotNull);
      final post = w.server.adapter.requests.firstWhere((r) => r.path == '/food-logs');
      expect(post.headers['Idempotency-Key'], isNotNull);
    });

    testWidgets('a food can be logged to a different meal', (tester) async {
      final w = await TestWorld.create();
      final b = await openAmount(tester, w);

      await tapVisible(tester, find.text('Dinner'));
      await tester.pump();
      await tapVisible(tester, find.text('LOG DINNER'));
      await pumpUntil(tester, find.text('LUNCH'));
      expect(b.logs.single['meal'], 'dinner');
    });

    testWidgets('a failed log shows why and keeps the screen', (tester) async {
      final w = await TestWorld.create();
      await openAmount(tester, w);
      w.server.reply('POST', '/food-logs', 422, errorJson('validation_failed', 'You cannot log food that far back.', details: {'logged_on': ['You cannot log food that far back.']}));

      await tapVisible(tester, find.text('LOG LUNCH'));
      await pumpUntil(tester, find.text('You cannot log food that far back.'));
      expect(find.text('THIS AMOUNT'), findsOneWidget);
    });

    testWidgets('offline, calculating says so and logging is not possible', (tester) async {
      final w = await TestWorld.create();
      await openAmount(tester, w);
      w.server.on('POST', '/nutrition/calculate', (o) => FakeReply.fail(DioExceptionType.connectionError));

      await tester.tap(find.byTooltip('Increase Number of servings'));
      await tester.pump(const Duration(milliseconds: 500));
      await pumpUntil(tester, find.textContaining('You are offline'));
    });

    testWidgets('the calculator (no meal) shows the numbers but offers no log button', (tester) async {
      final w = await TestWorld.create();
      await openFuel(tester, w);
      await tester.tap(find.text('Calculator'));
      await pumpUntil(tester, find.text('Chicken breast, cooked'));
      await tester.tap(find.text('Chicken breast, cooked'));
      await pumpUntil(tester, find.text('THIS AMOUNT'));
      expect(find.textContaining('LOG '), findsNothing);
      expect(find.text('SAVE CHANGES'), findsNothing);
    });

    testWidgets('favourites can be toggled and appear in the Favourites list', (tester) async {
      final w = await TestWorld.create();
      final b = await openAmount(tester, w);

      await tester.tap(find.byTooltip('Add to favourites'));
      await pumpUntil(tester, find.byTooltip('Remove from favourites'));
      expect(b.favourites, {1});

      await tester.tap(find.byTooltip('Remove from favourites'));
      await pumpUntil(tester, find.byTooltip('Add to favourites'));
      expect(b.favourites, isEmpty);
    });
  });

  group('editing and removing', () {
    testWidgets('tapping an entry reopens it to change the amount (server recalculates)', (tester) async {
      final w = await TestWorld.create();
      final b = await openFuel(tester, w);
      b.lunch.add(logEntryJson(101));
      await tester.fling(find.text('CALORIES'), const Offset(0, 400), 1000);
      await pumpUntil(tester, find.text('Chicken breast, cooked'));

      await tapVisible(tester, find.text('Chicken breast, cooked'));
      await pumpUntil(tester, find.text('Edit entry'));
      await pumpUntil(tester, find.text('THIS AMOUNT'));
      await tester.pump(const Duration(milliseconds: 400));
      await tapVisible(tester, find.text('SAVE CHANGES'));
      await pumpUntil(tester, find.text('LUNCH'));

      expect(b.patches.single['quantity'], 150.0);
      expect(b.patches.single['unit'], 'grams');
    });

    testWidgets('removing asks first, then deletes on the server', (tester) async {
      final w = await TestWorld.create();
      final b = await openFuel(tester, w);
      b.lunch.add(logEntryJson(101));
      await tester.fling(find.text('CALORIES'), const Offset(0, 400), 1000);
      await pumpUntil(tester, find.text('Chicken breast, cooked'));

      await tapVisible(tester, find.byTooltip('Remove Chicken breast, cooked'));
      await pumpUntil(tester, find.text('Remove this food?'));
      await tester.tap(find.text('Cancel'));
      await tester.pump(const Duration(milliseconds: 400));
      expect(b.deletes, 0);

      await tapVisible(tester, find.byTooltip('Remove Chicken breast, cooked'));
      await pumpUntil(tester, find.text('Remove this food?'));
      await tester.tap(find.text('REMOVE'));
      await pumpUntil(tester, find.text('Add food to lunch'));
      await tester.pump(const Duration(milliseconds: 300));
      expect(b.deletes, 1);
      expect(find.text('Chicken breast, cooked'), findsNothing);
    });
  });
}
