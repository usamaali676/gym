import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/features/authentication/presentation/auth_controller.dart';
import 'package:ironpulse/features/exercise_library/domain/exercise.dart';
import 'package:ironpulse/features/exercise_library/presentation/library_controller.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

Future<void> openLibrary(WidgetTester tester, TestWorld w) async {
  await w.storeSession();
  await pumpApp(tester, w);
  await pumpUntilHome(tester);
  await tester.tap(find.text('TRAIN'));
  await pumpUntil(tester, find.text('Exercises'));
  await tester.tap(find.text('Exercises'));
  await pumpUntil(tester, find.text('Exercise 1'));
}

Iterable<RequestOptions> exerciseRequests(TestWorld w) => w.server.adapter.requests.where((r) => r.path == '/exercises');

void main() {
  group('models', () {
    test('a full exercise carries instructions; a teaser does not', () {
      final full = Exercise.fromJson(exerciseJson(1, full: true, posture: true));
      expect(full.formCues, hasLength(2));
      expect(full.postureSupported, isTrue);
      expect(full.defaultRestSeconds, 120);

      final teaser = Exercise.fromJson(exerciseJson(2, locked: true));
      expect(teaser.locked, isTrue);
      expect(teaser.formCues, isEmpty);
      expect(teaser.description, isNull);
    });

    test('a page knows whether more follows', () {
      final p = ExercisePage.fromJson({'data': [exerciseJson(1)], 'meta': {'current_page': 1, 'last_page': 3, 'total': 45}});
      expect(p.hasMore, isTrue);
      expect(p.total, 45);
      expect(ExercisePage.fromJson({'data': [], 'meta': {'current_page': 3, 'last_page': 3}}).hasMore, isFalse);
    });

    test('filters build the query and a stable cache key', () {
      const f = LibraryFilters(query: ' Bench ', muscle: 'chest');
      expect(f.toQuery(2), {'q': 'Bench', 'muscle': 'chest', 'page': 2});
      expect(f.key, 'bench|chest||');
      expect(const LibraryFilters().toQuery(1), {'page': 1});
      expect(f.copyWith(muscle: null).muscle, isNull, reason: 'null clears a filter');
      expect(f.copyWith(query: 'x').muscle, 'chest', reason: 'untouched filters stay');
      expect(const LibraryFilters().isActive, isFalse);
      expect(f.isActive, isTrue);
    });
  });

  group('library controller', () {
    test('an answer for filters the member has since changed is discarded', () async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.serveSignedInMember();
      final slow = Completer<FakeReply>();
      w.server.on('GET', '/exercises', (o) async {
        if (o.uri.queryParameters['muscle'] == 'chest') return slow.future; // this answer arrives late
        return exercisePageReply(o, total: 3);
      });
      final c = w.container();
      c.read(libraryControllerProvider); // first load (no filter) -> 3 items
      await settle();
      expect(c.read(libraryControllerProvider).items, hasLength(3));

      final controller = c.read(libraryControllerProvider.notifier);
      controller.setMuscle('chest'); // slow
      controller.setMuscle('back'); // fast, newer
      await settle();
      expect(c.read(libraryControllerProvider).filters.muscle, 'back');

      slow.complete(exercisePageReply(RequestOptions(path: '/exercises', queryParameters: {'muscle': 'chest'}), total: 9));
      await settle();

      final state = c.read(libraryControllerProvider);
      expect(state.filters.muscle, 'back');
      expect(state.items, hasLength(3), reason: 'the late chest answer (9 items) must not replace the back list');
    });

    test('loading more appends each exercise once even if the catalogue shifted', () async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.serveSignedInMember();
      w.server.on('GET', '/exercises', (o) {
        final page = int.parse(o.uri.queryParameters['page'] ?? '1');
        // page 2 repeats the last item of page 1 (something was inserted meanwhile)
        final ids = page == 1 ? [1, 2, 3] : [3, 4, 5];
        return FakeReply.json(200, {'data': [for (final i in ids) exerciseJson(i)], 'meta': {'current_page': page, 'last_page': 2, 'total': 5}});
      });
      final c = w.container();
      c.read(libraryControllerProvider);
      await settle();

      await c.read(libraryControllerProvider.notifier).loadMore();

      final state = c.read(libraryControllerProvider);
      expect(state.items.map((e) => e.id), [1, 2, 3, 4, 5]);
      expect(state.hasMore, isFalse);
    });

    test('loadMore does nothing while offline data from the cache is shown', () async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.serveSignedInMember();
      final c = w.container();
      c.read(authControllerProvider); // sign in first: nothing is cached for an anonymous visitor
      await settle();
      c.read(libraryControllerProvider);
      await settle();
      expect(c.read(libraryControllerProvider).hasMore, isTrue);
      final requests = exerciseRequests(w).length;

      w.server.on('GET', '/exercises', (o) => FakeReply.fail(DioExceptionType.connectionError));
      await c.read(libraryControllerProvider.notifier).refresh();
      expect(c.read(libraryControllerProvider).fromCache, isTrue);
      await c.read(libraryControllerProvider.notifier).loadMore();

      expect(exerciseRequests(w).skip(requests).every((r) => r.uri.queryParameters['page'] == '1'), isTrue, reason: 'only the refresh (retried) hit the server; no page 2 was requested');
    });
  });

  group('screen', () {
    testWidgets('shows the first page, the count, and loads the next page near the end', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await openLibrary(tester, w);

      expect(find.text('45 exercises'), findsOneWidget);
      expect(exerciseRequests(w).single.uri.queryParameters['page'], '1');

      await tester.dragUntilVisible(find.text('Exercise 20'), find.byType(ListView).last, const Offset(0, -300));
      await tester.pump(const Duration(milliseconds: 400));
      await pumpUntil(tester, find.text('Exercise 21'));
      expect(exerciseRequests(w).map((r) => r.uri.queryParameters['page']), ['1', '2']);
    });

    testWidgets('typing is debounced into one search request', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await openLibrary(tester, w);
      final before = exerciseRequests(w).length;

      await tester.enterText(find.byType(TextField), 'E');
      await tester.pump(const Duration(milliseconds: 100));
      await tester.enterText(find.byType(TextField), 'Ex');
      await tester.pump(const Duration(milliseconds: 100));
      await tester.enterText(find.byType(TextField), 'Exercise 3');
      await tester.pump(const Duration(milliseconds: 500));
      await pumpUntil(tester, find.text('Exercise 3'));

      final searches = exerciseRequests(w).skip(before).toList();
      expect(searches, hasLength(1));
      expect(searches.single.uri.queryParameters['q'], 'Exercise 3');
    });

    testWidgets('choosing a muscle filters on the server and can be cleared', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await openLibrary(tester, w);

      await tester.tap(find.text('Muscle'));
      await pumpUntil(tester, find.text('Muscle group'));
      await tester.pump(const Duration(milliseconds: 400)); // let the sheet finish sliding in
      await tester.tap(find.text('Back'));
      await tester.pump(const Duration(milliseconds: 300));
      await pumpUntil(tester, find.text('Clear filters'));

      expect(exerciseRequests(w).last.uri.queryParameters['muscle'], 'back');
      expect(find.text('Back'), findsWidgets);

      await tester.tap(find.text('Clear filters'));
      await tester.pump(const Duration(milliseconds: 300));
      await pumpUntil(tester, find.text('Muscle'));
      expect(exerciseRequests(w).last.uri.queryParameters.containsKey('muscle'), isFalse);
    });

    testWidgets('no match shows an explanation with a way out', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await openLibrary(tester, w);

      await tester.enterText(find.byType(TextField), 'zzz');
      await tester.pump(const Duration(milliseconds: 500));
      await pumpUntil(tester, find.text('No exercises match'));
      expect(find.text('CLEAR FILTERS'), findsOneWidget);

      await tester.tap(find.text('CLEAR FILTERS'));
      await tester.pump(const Duration(milliseconds: 500));
      await pumpUntil(tester, find.text('Exercise 1'));
    });

    testWidgets('premium exercises are shown as locked teasers and open as an explanation', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.on('GET', '/exercises', (o) => exercisePageReply(o, locked: {2}));
      w.server.reply('GET', '/exercises/exercise-2', 200, {'data': exerciseJson(2, locked: true)});
      await openLibrary(tester, w);

      expect(find.text('Premium'), findsOneWidget);
      await tester.tap(find.text('Exercise 2'));
      await pumpUntil(tester, find.text('Not in your membership'));
      expect(find.textContaining('Premium exercise library is not included'), findsOneWidget);
      expect(find.text('FORM CUES'), findsNothing);
    });

    testWidgets('the detail page shows instructions, mistakes, breathing and posture support', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/exercises/exercise-1', 200, {'data': exerciseJson(1, name: 'Bench press', full: true, posture: true)});
      await openLibrary(tester, w);

      await tester.tap(find.text('Exercise 1'));
      await pumpUntil(tester, find.text('FORM CUES'));
      expect(find.text('Shoulder blades together'), findsOneWidget);
      expect(find.text('Bouncing the bar'), findsOneWidget);
      expect(find.text('Inhale down, exhale up.'), findsOneWidget);
      expect(find.text('Barbell and plates'), findsOneWidget, reason: 'equipment label comes from the server catalogue');
      expect(find.text('Camera form coach supported'), findsOneWidget);
      expect(find.text('Typical rest: 120 s'), findsOneWidget);
    });

    testWidgets('a failed first load shows the reason with a retry; offline falls back to the saved first page', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await openLibrary(tester, w);
      await tester.pump(const Duration(milliseconds: 300));

      w.server.on('GET', '/exercises', (o) => FakeReply.fail(DioExceptionType.connectionError));
      await tester.fling(find.text('Exercise 1'), const Offset(0, 400), 1000);
      await pumpUntil(tester, find.text('Offline. Showing saved data.'));
      expect(find.text('Exercise 1'), findsOneWidget);
    });

    testWidgets('a server error with nothing saved shows an error and retry works', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/exercises', 500, errorJson('server_error', 'Broken.'));
      await w.storeSession();
      await pumpApp(tester, w);
      await pumpUntilHome(tester);
      await tester.tap(find.text('TRAIN'));
      await pumpUntil(tester, find.text('Exercises'));
      await tester.tap(find.text('Exercises'));
      await pumpUntil(tester, find.text('Something went wrong'));

      w.server.on('GET', '/exercises', (o) => exercisePageReply(o));
      await tester.tap(find.text('TRY AGAIN'));
      await pumpUntil(tester, find.text('Exercise 1'));
    });

    testWidgets('a failure while loading the next page keeps the list and offers a retry', (tester) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      await openLibrary(tester, w);

      w.server.on('GET', '/exercises', (o) => o.uri.queryParameters['page'] == '2' ? FakeReply.json(500, errorJson('server_error', 'Broken.')) : exercisePageReply(o));
      await tester.dragUntilVisible(find.text('Exercise 20'), find.byType(ListView).last, const Offset(0, -300));
      await pumpUntil(tester, find.text('COULD NOT LOAD MORE. TRY AGAIN'));
      expect(find.text('Exercise 20'), findsOneWidget, reason: 'the loaded part of the list stays');

      w.server.on('GET', '/exercises', (o) => exercisePageReply(o));
      await tester.tap(find.text('COULD NOT LOAD MORE. TRY AGAIN'));
      await pumpUntil(tester, find.text('Exercise 21'));
    });
  });
}
