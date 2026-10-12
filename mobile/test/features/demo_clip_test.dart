import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/app/theme/app_theme.dart';
import 'package:ironpulse/features/exercise_library/domain/exercise.dart';
import 'package:ironpulse/features/exercise_library/presentation/demo_clip.dart';
import 'package:ironpulse/features/exercise_library/presentation/exercise_detail_screen.dart';

import 'package:ironpulse/core/providers.dart' show noAutoRetry;

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

class FakePlayer implements ClipPlayer {
  FakePlayer(this.url, this.owner);
  final String url;
  final PlayerLog owner;
  Completer<void>? gate;
  Object? failWith;
  bool playing = false;
  bool disposed = false;

  @override
  Future<void> initialize() async {
    owner.initialized.add(url);
    if (gate != null) await gate!.future;
    if (failWith != null) throw failWith!;
  }

  @override
  Future<void> play() async {
    playing = true;
    owner.plays++;
  }

  @override
  Future<void> pause() async {
    playing = false;
    owner.pauses++;
  }

  @override
  bool get isPlaying => playing;
  @override
  double get aspectRatio => 1;
  @override
  Widget view() => Container(key: ValueKey('video:$url'), color: Colors.green);
  @override
  Future<void> dispose() async {
    disposed = true;
    owner.disposed.add(url);
  }
}

class PlayerLog {
  final created = <FakePlayer>[];
  final initialized = <String>[];
  final disposed = <String>[];
  int plays = 0;
  int pauses = 0;
  Completer<void>? gateNext;
  Object? failNext;

  ClipPlayer make(String url) {
    final p = FakePlayer(url, this)
      ..gate = gateNext
      ..failWith = failNext;
    created.add(p);
    return p;
  }
}

Map<String, dynamic> clipJson(int id, String view, {String kind = 'demo_video', String mime = 'video/mp4'}) =>
    {'id': id, 'kind': kind, 'view': view, 'url': 'https://media.test/$kind-$view.${kind == 'demo_video' ? 'mp4' : 'jpg'}', 'checksum': 'c$id', 'mime': mime, 'bytes': 1000, 'width': 640, 'height': 640, 'duration_ms': kind == 'demo_video' ? 3400 : null};

List<ExerciseMedia> media(List<Map<String, dynamic>> raw) => [for (final m in raw) ExerciseMedia.fromJson(m)];

Future<PlayerLog> pumpClips(WidgetTester tester, List<ExerciseMedia> m, {bool reduceMotion = false}) async {
  final log = PlayerLog();
  await pumpClipsWith(tester, m, log, reduceMotion: reduceMotion);
  return log;
}

Future<void> pumpClipsWith(WidgetTester tester, List<ExerciseMedia> m, PlayerLog log, {bool reduceMotion = false}) async {
  usePhoneScreen(tester);
  await tester.pumpWidget(ProviderScope(
    overrides: [clipPlayerFactoryProvider.overrideWithValue(log.make)],
    child: MaterialApp(
      theme: buildAppTheme(),
      home: MediaQuery(
        data: MediaQueryData(size: const Size(360, 740), disableAnimations: reduceMotion),
        child: Scaffold(body: SingleChildScrollView(padding: const EdgeInsets.all(16), child: DemoClips(media: m, exerciseName: 'Goblet squat'))),
      ),
    ),
  ));
  await tester.pump();
  await tester.pump();
}

void main() {
  group('which clips the app can play', () {
    test('only demo videos, one per view, in the order 45°, side, front', () {
      final m = media([
        clipJson(1, 'front'),
        clipJson(2, 'angle'),
        clipJson(3, 'angle', kind: 'thumbnail', mime: 'image/jpeg'),
        clipJson(4, 'side'),
        clipJson(5, 'angle', kind: 'image', mime: 'image/jpeg'),
        clipJson(6, 'angle'), // a second angle clip is ignored
        clipJson(7, 'angle', kind: 'instruction_video'),
      ]);
      expect(DemoClips.playable(m).map((c) => c.view), ['angle', 'side', 'front']);
      expect(DemoClips.playable(m).first.id, 2);
    });

    test('nothing playable without a demo video, or with an empty address', () {
      expect(DemoClips.playable(media([clipJson(1, 'angle', kind: 'thumbnail', mime: 'image/jpeg')])), isEmpty);
      expect(DemoClips.playable(media([clipJson(1, 'angle', kind: 'instruction_video')])), isEmpty, reason: 'only the demonstration loop is shown here');
      expect(DemoClips.playable(const []), isEmpty);
      expect(DemoClips.playable([const ExerciseMedia(id: 1, kind: 'demo_video', view: 'angle', url: '', checksum: null, mime: 'video/mp4')]), isEmpty);
    });
  });

  group('the clip', () {
    testWidgets('an exercise without a clip shows nothing at all', (tester) async {
      final log = await pumpClips(tester, media([clipJson(1, 'angle', kind: 'thumbnail', mime: 'image/jpeg')]));
      expect(find.byType(DemoClips), findsOneWidget);
      expect(find.textContaining('Animated demonstration'), findsNothing);
      expect(find.byIcon(Icons.play_circle_outline), findsNothing);
      expect(log.created, isEmpty, reason: 'no player is made for nothing');
    });

    testWidgets('plays by itself, says what it is, and a tap pauses and resumes it', (tester) async {
      final log = await pumpClips(tester, media([clipJson(1, 'angle')]));

      expect(log.initialized, ['https://media.test/demo_video-angle.mp4']);
      expect(find.byKey(const ValueKey('video:https://media.test/demo_video-angle.mp4')), findsOneWidget);
      expect(find.textContaining('rendered figure, not a recording of a person'), findsOneWidget);
      expect(log.created.single.playing, isTrue);
      expect(find.bySemanticsLabel(RegExp('Playing. Double tap to pause')), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('video:https://media.test/demo_video-angle.mp4')));
      await tester.pump();
      expect(log.created.single.playing, isFalse);
      expect(find.byIcon(Icons.play_circle_outline), findsOneWidget);
      expect(find.bySemanticsLabel(RegExp('Paused. Double tap to play')), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('video:https://media.test/demo_video-angle.mp4')));
      await tester.pump();
      expect(log.created.single.playing, isTrue);
      expect(find.byIcon(Icons.play_circle_outline), findsNothing);
    });

    testWidgets('shows a spinner while it loads', (tester) async {
      final log = PlayerLog()..gateNext = Completer<void>();
      await pumpClipsWith(tester, media([clipJson(1, 'angle')]), log);
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      expect(find.byKey(const ValueKey('video:https://media.test/demo_video-angle.mp4')), findsNothing);

      log.gateNext!.complete();
      await tester.pump();
      await tester.pump();
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(log.created.single.playing, isTrue);
    });

    testWidgets('a clip that cannot load says so and Try again makes a new attempt', (tester) async {
      final log = PlayerLog()..failNext = StateError('network');
      await pumpClipsWith(tester, media([clipJson(1, 'angle')]), log);
      expect(find.textContaining('The video could not be loaded'), findsOneWidget);
      expect(find.byIcon(Icons.play_circle_outline), findsNothing, reason: 'there is nothing to play');

      log.failNext = null;
      await tester.tap(find.text('TRY AGAIN'));
      await tester.pump();
      await tester.pump();
      expect(log.created, hasLength(2));
      expect(log.disposed, hasLength(1), reason: 'the failed player is released');
      expect(find.textContaining('could not be loaded'), findsNothing);
      expect(log.created.last.playing, isTrue);
    });

    testWidgets('with "reduce motion" on, it waits for the member to press play', (tester) async {
      final log = await pumpClips(tester, media([clipJson(1, 'angle')]), reduceMotion: true);
      expect(log.created, isEmpty);
      expect(find.byIcon(Icons.play_circle_outline), findsOneWidget);

      await tester.tap(find.byIcon(Icons.play_circle_outline));
      await tester.pump();
      await tester.pump();
      expect(log.created.single.playing, isTrue);
    });

    testWidgets('pauses when the app goes to the background and stays paused on return', (tester) async {
      final log = await pumpClips(tester, media([clipJson(1, 'angle')]));
      expect(log.created.single.playing, isTrue);

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await tester.pump();
      expect(log.created.single.playing, isFalse);

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();
      expect(log.created.single.playing, isFalse, reason: 'the member presses play when they want it');
      expect(find.byIcon(Icons.play_circle_outline), findsOneWidget);
    });

    testWidgets('several views offer a switch; choosing one loads that clip and releases the first', (tester) async {
      final log = await pumpClips(tester, media([clipJson(1, 'angle'), clipJson(2, 'side'), clipJson(3, 'front')]));
      expect(find.text('45°'), findsOneWidget);
      expect(find.text('Side'), findsOneWidget);
      expect(find.text('Front'), findsOneWidget);

      await tester.tap(find.text('Side'));
      await tester.pump();
      await tester.pump();
      expect(log.initialized, ['https://media.test/demo_video-angle.mp4', 'https://media.test/demo_video-side.mp4']);
      expect(log.disposed, ['https://media.test/demo_video-angle.mp4']);
      expect(find.byKey(const ValueKey('video:https://media.test/demo_video-side.mp4')), findsOneWidget);
    });

    testWidgets('one view has no switch', (tester) async {
      await pumpClips(tester, media([clipJson(1, 'angle')]));
      expect(find.text('Side'), findsNothing);
    });

    testWidgets('leaving the screen releases the player', (tester) async {
      final log = await pumpClips(tester, media([clipJson(1, 'angle')]));
      await tester.pumpWidget(const SizedBox());
      expect(log.disposed, hasLength(1));
    });

    testWidgets('a player that finishes loading after the screen is gone does not throw', (tester) async {
      final log = PlayerLog()..gateNext = Completer<void>();
      await pumpClipsWith(tester, media([clipJson(1, 'angle')]), log);
      await tester.pumpWidget(const SizedBox());
      log.gateNext!.complete();
      await tester.pump();
      expect(tester.takeException(), isNull);
      expect(log.created.single.playing, isFalse, reason: 'never started after being released');
    });
  });

  group('on the exercise page', () {
    Future<PlayerLog> openDetail(WidgetTester tester, String slug, Map<String, dynamic> exercise) async {
      final w = await TestWorld.create();
      w.serveSignedInMember();
      w.server.reply('GET', '/exercises/$slug', 200, {'data': exercise});
      await w.storeSession();
      final log = PlayerLog();
      usePhoneScreen(tester);
      await tester.pumpWidget(ProviderScope(
        retry: noAutoRetry,
        overrides: [...w.overrides, clipPlayerFactoryProvider.overrideWithValue(log.make)],
        child: MaterialApp(theme: buildAppTheme(), home: ExerciseDetailScreen(slug: slug)),
      ));
      await pumpUntil(tester, find.text('FORM CUES'));
      await tester.pump();
      return log;
    }

    testWidgets('the clip plays at the top of the page, above the title', (tester) async {
      final log = await openDetail(tester, 'exercise-1', exerciseJson(1, name: 'Goblet squat', full: true, media: [clipJson(1, 'angle'), clipJson(2, 'angle', kind: 'thumbnail', mime: 'image/jpeg')]));
      expect(log.created.single.playing, isTrue);
      final clipY = tester.getTopLeft(find.byType(DemoClips)).dy;
      final titleY = tester.getTopLeft(find.text('Goblet squat').first).dy;
      expect(clipY, lessThan(titleY));
    });

    testWidgets('an exercise without a clip has no video area and no gap', (tester) async {
      final log = await openDetail(tester, 'exercise-2', exerciseJson(2, name: 'Plank', full: true));
      expect(log.created, isEmpty);
      expect(find.textContaining('Animated demonstration'), findsNothing);
      final titleTop = tester.getTopLeft(find.text('Plank').first).dy;
      expect(titleTop, lessThan(90), reason: 'the title starts right under the app bar, with no gap where a video would be (was $titleTop)');
    });
  });
}
