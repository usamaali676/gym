import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/features/workout_sessions/domain/session_engine.dart';

final t0 = DateTime.utc(2026, 10, 12, 18);

DateTime at(int seconds) => t0.add(Duration(seconds: seconds));

/// A: 3 sets, 90 s rest. B: 2 sets, 60 s rest. C: 1 set, no rest.
List<ExerciseStep> steps({int a = 0, int b = 0, int c = 0, bool skipA = false, bool skipB = false}) => [
      ExerciseStep(targetSets: 3, loggedSets: a, skipped: skipA, restSeconds: 90),
      ExerciseStep(targetSets: 2, loggedSets: b, skipped: skipB, restSeconds: 60),
      ExerciseStep(targetSets: 1, loggedSets: c, skipped: false, restSeconds: 0),
    ];

void main() {
  group('starting position', () {
    test('a fresh session starts at the intro of the first exercise', () {
      final s = SessionEngine.initial(steps());
      expect((s.idx, s.setIdx, s.phase), (0, 0, WorkPhase.intro));
    });

    test('a session with sets already logged resumes at the next set of that exercise', () {
      final s = SessionEngine.initial(steps(a: 2));
      expect((s.idx, s.setIdx, s.phase), (0, 2, WorkPhase.work));
    });

    test('finished and skipped exercises are passed over', () {
      expect(SessionEngine.initial(steps(a: 3)).idx, 1);
      expect(SessionEngine.initial(steps(skipA: true)).idx, 1);
      expect(SessionEngine.initial(steps(skipA: true, b: 2)).idx, 2);
    });

    test('nothing left to do is the finish phase', () {
      expect(SessionEngine.initial(steps(a: 3, b: 2, c: 1)).phase, WorkPhase.finish);
    });
  });

  group('a set is logged', () {
    test('mid exercise: rest for that exercise, then the next set', () {
      final s = SessionEngine.setLogged(SessionEngine.startSet(SessionEngine.initial(steps()), at(0)), steps(a: 1), at(40));
      expect((s.idx, s.setIdx, s.phase), (0, 1, WorkPhase.rest));
      expect(s.restEndsAt, at(40 + 90));
      expect(s.restTotalMs, 90000);
    });

    test('the last set of an exercise rests, then opens the next exercise at its intro', () {
      var s = EngineState(idx: 0, setIdx: 2, phase: WorkPhase.work);
      s = SessionEngine.setLogged(s, steps(a: 3), at(0));
      expect((s.idx, s.setIdx, s.phase), (1, 0, WorkPhase.rest));
      expect(SessionEngine.normalize(s, at(90)).phase, WorkPhase.intro);
    });

    test('an exercise without rest goes straight on', () {
      final s = SessionEngine.setLogged(const EngineState(idx: 2, setIdx: 0, phase: WorkPhase.work), steps(a: 3, b: 2, c: 1), at(0));
      expect(s.phase, WorkPhase.finish);

      final noRest = [const ExerciseStep(targetSets: 2, loggedSets: 1, skipped: false, restSeconds: 0)];
      final t = SessionEngine.setLogged(const EngineState(idx: 0, setIdx: 0, phase: WorkPhase.work), noRest, at(0));
      expect((t.idx, t.setIdx, t.phase), (0, 1, WorkPhase.work));
    });

    test('the very last set of the workout finishes without a rest', () {
      final s = SessionEngine.setLogged(const EngineState(idx: 1, setIdx: 1, phase: WorkPhase.work), [
        const ExerciseStep(targetSets: 3, loggedSets: 3, skipped: false, restSeconds: 90),
        const ExerciseStep(targetSets: 2, loggedSets: 2, skipped: false, restSeconds: 60),
      ], at(0));
      expect(s.phase, WorkPhase.finish);
      expect(s.restEndsAt, isNull);
    });

    test('skipped exercises are not visited', () {
      final s = SessionEngine.setLogged(const EngineState(idx: 0, setIdx: 2, phase: WorkPhase.work), steps(a: 3, skipB: true), at(0));
      expect(s.idx, 2);
    });
  });

  group('the rest timer comes from a timestamp', () {
    final resting = SessionEngine.setLogged(const EngineState(idx: 0, setIdx: 0, phase: WorkPhase.work), steps(a: 1), at(0)); // ends at 90 s

    test('remaining time is end minus now', () {
      expect(SessionEngine.remainingRest(resting, at(30)), const Duration(seconds: 60));
      expect(SessionEngine.remainingRest(resting, at(89)), const Duration(seconds: 1));
    });

    test('nothing changes before the end; at the end the rest finishes', () {
      expect(SessionEngine.normalize(resting, at(89)).phase, WorkPhase.rest);
      final done = SessionEngine.normalize(resting, at(90));
      expect((done.phase, done.setIdx), (WorkPhase.work, 1));
      expect(done.restEndsAt, isNull);
    });

    test('the app in the background for ten minutes: the answer is the same as if it had ticked', () {
      final back = SessionEngine.normalize(resting, at(600));
      expect(back.phase, WorkPhase.work);
      expect(SessionEngine.remainingRest(back, at(600)), Duration.zero);
    });

    test('the clock jumping forward past the end finishes the rest, backward never shows more than was set', () {
      expect(SessionEngine.normalize(resting, at(5000)).phase, WorkPhase.work);
      expect(SessionEngine.remainingRest(resting, at(-3600)), const Duration(seconds: 90));
    });

    test('+30 s extends the rest and its total', () {
      final more = SessionEngine.extendRest(resting, const Duration(seconds: 30), at(10));
      expect(SessionEngine.remainingRest(more, at(10)), const Duration(seconds: 110));
      expect(more.restTotalMs, 120000);
      expect(SessionEngine.extendRest(SessionEngine.initial(steps()), const Duration(seconds: 30), at(0)).restEndsAt, isNull, reason: 'only a rest can be extended');
    });

    test('skipping the rest goes to the next set; after the last set of an exercise, to the next exercise intro', () {
      expect(SessionEngine.skipRest(resting, at(10)).phase, WorkPhase.work);
      final toNext = SessionEngine.setLogged(const EngineState(idx: 0, setIdx: 2, phase: WorkPhase.work), steps(a: 3), at(0));
      final skipped = SessionEngine.skipRest(toNext, at(5));
      expect((skipped.idx, skipped.phase), (1, WorkPhase.intro));
    });
  });

  group('pause and resume', () {
    final resting = SessionEngine.setLogged(const EngineState(idx: 0, setIdx: 0, phase: WorkPhase.work), steps(a: 1), at(0)); // ends at 90 s

    test('pausing a rest freezes the time left, however long the pause lasts', () {
      final paused = SessionEngine.pause(resting, at(30)); // 60 s left
      expect(paused.phase, WorkPhase.paused);
      expect(SessionEngine.remainingRest(paused, at(30)), const Duration(seconds: 60));
      expect(SessionEngine.remainingRest(paused, at(30 + 3600)), const Duration(seconds: 60));
      expect(SessionEngine.normalize(paused, at(30 + 3600)).phase, WorkPhase.paused, reason: 'a paused rest never finishes by itself');
    });

    test('resuming continues with exactly the time that was left', () {
      final paused = SessionEngine.pause(resting, at(30));
      final back = SessionEngine.resume(paused, at(30 + 300)); // 5 minute phone call
      expect(back.phase, WorkPhase.rest);
      expect(SessionEngine.remainingRest(back, at(330)), const Duration(seconds: 60));
      expect(SessionEngine.normalize(back, at(330 + 59)).phase, WorkPhase.rest);
      expect(SessionEngine.normalize(back, at(330 + 60)).phase, WorkPhase.work);
      expect(back.pausedMs, 300000);
    });

    test('pausing and resuming survive being saved and restored in between (app killed during the pause)', () {
      final paused = SessionEngine.pause(resting, at(30));
      final restored = EngineState.fromJson(paused.toLocalJson())!;
      final back = SessionEngine.resume(restored, at(500));
      expect(SessionEngine.remainingRest(back, at(500)), const Duration(seconds: 60));
    });

    test('pausing during a set and resuming returns to the set, and pauses add up', () {
      var s = SessionEngine.startSet(SessionEngine.initial(steps()), at(0));
      s = SessionEngine.pause(s, at(10));
      s = SessionEngine.resume(s, at(70));
      s = SessionEngine.pause(s, at(80));
      s = SessionEngine.resume(s, at(110));
      expect(s.phase, WorkPhase.work);
      expect(s.pausedMs, 90000);
    });

    test('only a set or a rest can be paused', () {
      final intro = SessionEngine.initial(steps());
      expect(SessionEngine.pause(intro, at(0)).phase, WorkPhase.intro);
      final finish = SessionEngine.initial(steps(a: 3, b: 2, c: 1));
      expect(SessionEngine.pause(finish, at(0)).phase, WorkPhase.finish);
      expect(SessionEngine.resume(intro, at(0)), same(intro));
    });

    test('a clock that moved backwards during a pause never produces a negative pause', () {
      final paused = SessionEngine.pause(resting, at(30));
      final back = SessionEngine.resume(paused, at(10));
      expect(back.pausedMs, 0);
      expect(back.restEndsAt, resting.restEndsAt);
    });
  });

  group('active time', () {
    test('is wall time minus pauses, and stands still while paused', () {
      var s = SessionEngine.startSet(SessionEngine.initial(steps()), at(0));
      expect(SessionEngine.activeElapsed(s, startedAt: t0, now: at(100)), const Duration(seconds: 100));

      s = SessionEngine.pause(s, at(100));
      expect(SessionEngine.activeElapsed(s, startedAt: t0, now: at(100)), const Duration(seconds: 100));
      expect(SessionEngine.activeElapsed(s, startedAt: t0, now: at(700)), const Duration(seconds: 100), reason: 'frozen during the pause');

      s = SessionEngine.resume(s, at(700)); // 600 s pause
      expect(SessionEngine.activeElapsed(s, startedAt: t0, now: at(760)), const Duration(seconds: 160));
    });

    test('never negative', () {
      expect(SessionEngine.activeElapsed(SessionEngine.initial(steps()), startedAt: at(100), now: at(0)), Duration.zero);
    });
  });

  group('timed holds', () {
    test('count down from the moment the hold began and stop at zero', () {
      final s = SessionEngine.startSet(SessionEngine.initial(steps()), at(0));
      expect(SessionEngine.remainingHold(s, at(0), 45), const Duration(seconds: 45));
      expect(SessionEngine.remainingHold(s, at(10), 45), const Duration(seconds: 35));
      expect(SessionEngine.remainingHold(s, at(500), 45), Duration.zero);
    });

    test('a pause moves the end of the hold by the length of the pause', () {
      var s = SessionEngine.startSet(SessionEngine.initial(steps()), at(0));
      s = SessionEngine.pause(s, at(10)); // 35 s left
      s = SessionEngine.resume(s, at(110)); // paused 100 s
      expect(SessionEngine.remainingHold(s, at(110), 45), const Duration(seconds: 35));
      expect(SessionEngine.remainingHold(s, at(120), 45), const Duration(seconds: 25));
    });
  });

  group('skipping an exercise', () {
    test('moves to the next open exercise; skipping the last open one finishes', () {
      final s = SessionEngine.exerciseSkipped(const EngineState(idx: 0, setIdx: 0, phase: WorkPhase.intro), steps(skipA: true), at(0));
      expect((s.idx, s.phase), (1, WorkPhase.intro));

      final lastSkipped = [...steps(a: 3, b: 2).take(2), const ExerciseStep(targetSets: 1, loggedSets: 0, skipped: true, restSeconds: 0)];
      final end = SessionEngine.exerciseSkipped(const EngineState(idx: 2, setIdx: 0, phase: WorkPhase.intro), lastSkipped, at(0));
      expect(end.phase, WorkPhase.finish);
    });

    test('when only an earlier exercise is still open it is offered again', () {
      final s = SessionEngine.exerciseSkipped(const EngineState(idx: 1, setIdx: 0, phase: WorkPhase.intro), steps(a: 1, skipB: true, c: 1), at(0));
      expect((s.idx, s.setIdx, s.phase), (0, 1, WorkPhase.work));
    });
  });

  group('saved state', () {
    final s = SessionEngine.pause(SessionEngine.setLogged(const EngineState(idx: 0, setIdx: 0, phase: WorkPhase.work), steps(a: 1), at(0)), at(20));

    test('the server copy has exactly the fields the API accepts and carries absolute times', () {
      final json = s.toServerJson(startedAt: t0, now: at(20));
      expect(json.keys.toSet(), {'idx', 'setIdx', 'phase', 'rest_ends_at', 'paused_at', 'paused_ms', 'active_ms', 'saved_at'});
      expect(json['phase'], 'paused');
      expect(json['rest_ends_at'], at(90).toIso8601String());
      expect(json['active_ms'], 20000);
      expect(json.containsKey('rest_total_ms'), isFalse);
    });

    test('the local copy round-trips everything', () {
      final back = EngineState.fromJson(s.toLocalJson())!;
      expect(back.toLocalJson(), s.toLocalJson());
    });

    test('a server copy (without the local-only fields) is still usable', () {
      final back = EngineState.fromJson(s.toServerJson(startedAt: t0, now: at(20)))!;
      expect(back.phase, WorkPhase.paused);
      expect(back.restEndsAt, at(90));
      expect(back.restTotalMs, 0, reason: 'unknown: the clock-jump cap is simply not applied');
      expect(SessionEngine.remainingRest(back, at(9999)), const Duration(seconds: 70));
    });

    test('damaged or unknown data is ignored instead of crashing', () {
      expect(EngineState.fromJson(null), isNull);
      expect(EngineState.fromJson({'idx': 'x'}), isNull);
      expect(EngineState.fromJson({'idx': 0, 'setIdx': 0, 'phase': 'dancing'}), isNull);
    });
  });

  group('reconcile with what the server says was logged', () {
    test('a stored position behind the logged sets is moved forward', () {
      final stale = EngineState(idx: 0, setIdx: 0, phase: WorkPhase.intro, pausedMs: 5000);
      final s = SessionEngine.reconcile(stale, steps(a: 3, b: 1));
      expect((s.idx, s.setIdx, s.phase), (1, 1, WorkPhase.work));
      expect(s.pausedMs, 5000, reason: 'time already spent paused is kept');
    });

    test('a position that is still correct is left alone', () {
      final good = SessionEngine.setLogged(const EngineState(idx: 0, setIdx: 0, phase: WorkPhase.work), steps(a: 1), at(0));
      expect(SessionEngine.reconcile(good, steps(a: 1)), same(good));
    });

    test('a "finished" state while exercises are still open restarts at the first open one', () {
      final s = SessionEngine.reconcile(const EngineState(idx: 2, setIdx: 0, phase: WorkPhase.finish), steps(a: 1));
      expect((s.idx, s.setIdx), (0, 1));
    });
  });
}
