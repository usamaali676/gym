import 'package:flutter_test/flutter_test.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

/// The server will change under a phone that has not been updated, or answer with something unexpected (a proxy's error page, a
/// half-deployed release). The app must show a plain error with a way to retry, never a red screen or a crash. Every main
/// screen is opened with each odd answer.
void main() {
  const odd = <String, Object?>{
    'a string instead of an object': 'oops',
    'a list instead of an object': <Object?>[],
    'null data': null,
    'an empty object': <String, Object?>{},
    'unexpected keys only': {'unexpected': true},
    'wrong types': {'id': 'not a number', 'name': 5, 'items': 'x', 'days': {}, 'meals': 3},
  };

  for (final entry in odd.entries) {
    testWidgets('every tab survives ${entry.key}', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      final body = {'data': entry.value};
      for (final path in ['/dashboard', '/plan/week', '/programs', '/nutrition/day', '/progress/summary', '/progress/measurements', '/progress/records', '/achievements', '/membership', '/membership/plans']) {
        w.server.reply('GET', path, 200, body);
      }
      w.server.on('GET', '/exercises', (o) => FakeReply.json(200, body));
      await w.storeSession();
      await pumpApp(tester, w);
      await tester.pump(const Duration(seconds: 1));
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull, reason: 'the dashboard must not throw');

      for (final tab in ['TRAIN', 'FUEL', 'PROGRESS', 'ACCOUNT']) {
        await tester.tap(find.text(tab));
        await tester.pump(const Duration(seconds: 1));
        await tester.pump(const Duration(milliseconds: 500));
        expect(tester.takeException(), isNull, reason: '$tab must not throw');
      }

      await tester.tap(find.text('TRAIN'));
      await tester.pump(const Duration(seconds: 1));
      for (final segment in ['Programmes', 'Exercises']) {
        await tester.tap(find.text(segment));
        await tester.pump(const Duration(seconds: 1));
        await tester.pump(const Duration(milliseconds: 500));
        expect(tester.takeException(), isNull, reason: 'Train / $segment must not throw');
      }
      await tester.tap(find.text('PROGRESS'));
      await tester.pump(const Duration(seconds: 1));
      for (final segment in ['Body', 'Badges']) {
        await tester.tap(find.text(segment));
        await tester.pump(const Duration(seconds: 1));
        await tester.pump(const Duration(milliseconds: 500));
        expect(tester.takeException(), isNull, reason: 'Progress / $segment must not throw');
      }
    });
  }

  for (final entry in odd.entries) {
    testWidgets('starting a workout and opening an exercise survive ${entry.key}', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      final body = {'data': entry.value};
      w.server.reply('POST', '/workout-sessions', 201, body);
      w.server.reply('GET', '/workout-sessions/active', 200, body);
      w.server.reply('GET', '/exercises/exercise-1', 200, body);
      w.server.reply('GET', '/programs/1', 200, body);
      w.server.reply('GET', '/plan/days/3', 200, body);
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);

      await tester.tap(find.text('START WORKOUT'));
      for (var i = 0; i < 10; i++) {
        await tester.pump(const Duration(milliseconds: 200));
      }
      expect(tester.takeException(), isNull, reason: 'starting a workout must not throw');

      // Back to the app shell if the workout screen opened.
      if (find.text('TRAIN').evaluate().isEmpty) {
        await tester.pageBack();
        await tester.pump(const Duration(milliseconds: 500));
      }
      if (find.text('TRAIN').evaluate().isNotEmpty) {
        await tester.tap(find.text('TRAIN'));
        await tester.pump(const Duration(seconds: 1));
        await tester.tap(find.text('Exercises'));
        await tester.pump(const Duration(seconds: 1));
        expect(tester.takeException(), isNull);
      }
      await tester.pump(const Duration(seconds: 6)); // let snack bars and debounce timers finish
    });
  }
}
