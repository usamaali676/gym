import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

void main() {
  Future<TestWorld> newMember({bool guest = false}) async {
    final w = await TestWorld.create();
    await w.storeSession();
    w.serveSignedInMember(user: userJson(onboarded: false, guest: guest));
    return w;
  }

  testWidgets('the choices come from the server, not from the app', (tester) async {
    final w = await newMember();
    final config = configJson();
    (config['profile_options'] as Map)['goal'] = [
      {'value': 'cutting', 'label': 'Shred it'},
      {'value': 'powerlifting', 'label': 'Brand new goal added on the server'},
    ];
    w.server.reply('GET', '/app/config', 200, {'data': config});
    await pumpApp(tester, w);
    await pumpUntil(tester, find.text('Set up your profile'));

    await tester.dragUntilVisible(find.text('Brand new goal added on the server'), find.byType(SingleChildScrollView), const Offset(0, -200));
    expect(find.text('Shred it'), findsOneWidget);
  });

  testWidgets('finishing with nothing filled in lists every missing answer and sends nothing', (tester) async {
    final w = await newMember();
    await pumpApp(tester, w);
    await pumpUntil(tester, find.text('Set up your profile'));

    await tester.dragUntilVisible(find.text('FINISH'), find.byType(SingleChildScrollView), const Offset(0, -300));
    await tester.tap(find.text('FINISH'));
    await tester.pump();

    expect(find.text('Enter your age.'), findsOneWidget);
    expect(w.server.log, isNot(contains('POST /me/onboarding')));
    await tester.dragUntilVisible(find.text('Pick at least one day.'), find.byType(SingleChildScrollView), const Offset(0, 300));
    expect(find.text('Pick at least one day.'), findsOneWidget);
  });

  testWidgets('a completed form posts exactly what the server validates, then opens the app', (tester) async {
    final w = await newMember();
    w.server.on('POST', '/me/onboarding', (_) {
      // after onboarding /me reports onboarded: true
      w.server.reply('GET', '/me', 200, {'data': userJson()});
      return FakeReply.json(200, {'data': userJson()});
    });
    await pumpApp(tester, w);
    await pumpUntil(tester, find.text('Set up your profile'));

    Future<void> type(int index, String text) async {
      await tester.enterText(find.byType(TextFormField).at(index), text);
    }

    Future<void> pick(String label) async {
      await tester.dragUntilVisible(find.text(label), find.byType(SingleChildScrollView), const Offset(0, -150));
      await tester.tap(find.text(label));
      await tester.pump();
    }

    await type(1, '29');
    await type(2, '178');
    await type(3, '82,5'); // decimal comma is accepted
    await pick('Male');
    await pick('Moderately active');
    await pick('Intermediate');
    await pick('Bulking (muscle gain)');
    await pick('Includes meat');
    await pick('Barbell and plates');
    await pick('Dumbbells');
    await pick('Mon');
    await pick('Wed');
    await pick('Fri');

    await tester.dragUntilVisible(find.text('FINISH'), find.byType(SingleChildScrollView), const Offset(0, -150));
    await tester.tap(find.text('FINISH'));
    await pumpUntilHome(tester);

    final body = w.server.adapter.requests.firstWhere((r) => r.path == '/me/onboarding').data as Map;
    expect(body, {
      'name': 'Sam Lifter',
      'age': 29,
      'sex': 'male',
      'height_cm': 178.0,
      'weight_kg': 82.5,
      'activity_level': 'moderate',
      'fitness_level': 'intermediate',
      'goal': 'bulking',
      'diet_preference': 'non_veg',
      'equipment': ['barbell', 'dumbbell'],
      'training_days': [1, 3, 5],
    });
  });

  testWidgets('a server-side rejection is shown on the field it names', (tester) async {
    final w = await newMember();
    w.server.reply('POST', '/me/onboarding', 422, errorJson('validation_failed', 'The given data was invalid.', details: {'age': ['The age must be between 14 and 90.']}));
    await pumpApp(tester, w);
    await pumpUntil(tester, find.text('Set up your profile'));

    Future<void> pick(String label) async {
      await tester.dragUntilVisible(find.text(label), find.byType(SingleChildScrollView), const Offset(0, -150));
      await tester.tap(find.text(label));
      await tester.pump();
    }

    await tester.enterText(find.byType(TextFormField).at(1), '30');
    await tester.enterText(find.byType(TextFormField).at(2), '170');
    await tester.enterText(find.byType(TextFormField).at(3), '70');
    for (final l in ['Male', 'Lightly active', 'Beginner', 'Cutting (fat loss)', 'Vegetarian', 'Dumbbells', 'Tue']) {
      await pick(l);
    }
    await tester.dragUntilVisible(find.text('FINISH'), find.byType(SingleChildScrollView), const Offset(0, -150));
    await tester.tap(find.text('FINISH'));
    await pumpUntil(tester, find.text('Please check the highlighted fields.'));
    await tester.dragUntilVisible(find.text('The age must be between 14 and 90.'), find.byType(SingleChildScrollView), const Offset(0, 300));
  });

  testWidgets('without the server choices (never reached it) the screen explains and offers a retry', (tester) async {
    final w = await TestWorld.create();
    await w.storeSession(cachedUser: userJson(onboarded: false));
    w.server.on('GET', '/me', (_) => FakeReply.json(200, {'data': userJson(onboarded: false)}));
    await pumpApp(tester, w); // /app/config -> 404 in the fake
    await pumpUntil(tester, find.text('You are offline'));
    expect(find.text('TRY AGAIN'), findsOneWidget);

    w.server.reply('GET', '/app/config', 200, {'data': configJson()});
    await tester.tap(find.text('TRY AGAIN'));
    await pumpUntil(tester, find.text('Set up your profile'));
  });
}
