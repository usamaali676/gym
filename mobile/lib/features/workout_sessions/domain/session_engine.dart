// The workout's state machine. Pure Dart: no clock, no timers, no I/O. Every method takes `now`, and every timer is
// derived from an absolute timestamp, so the same state gives the same answer after a phone call, backgrounding, an
// app kill or a restart. Nothing here "counts seconds".
//
//   intro --startSet--> work --setLogged--> rest --(time passes / skip)--> work | intro --...--> finish
//                          \--pause--> paused --resume--> work | rest

enum WorkPhase { intro, work, rest, paused, finish }

/// What the engine needs to know about one exercise of the session.
class ExerciseStep {
  const ExerciseStep({required this.targetSets, required this.loggedSets, required this.skipped, required this.restSeconds});

  final int targetSets;
  final int loggedSets;
  final bool skipped;
  final int restSeconds;

  bool get isOpen => !skipped && loggedSets < targetSets;
}

class EngineState {
  const EngineState({
    required this.idx,
    required this.setIdx,
    required this.phase,
    this.restEndsAt,
    this.pausedAt,
    this.pausedMs = 0,
    this.restTotalMs = 0,
    this.workStartedAt,
    this.savedAt,
  });

  /// Exercise index and 0-based set index of the NEXT thing to do.
  final int idx;
  final int setIdx;
  final WorkPhase phase;

  /// While resting: when the rest ends. While paused during a rest: when it WOULD end had the pause not happened,
  /// so the time left is `restEndsAt - pausedAt` and resuming shifts it by the length of the pause.
  final DateTime? restEndsAt;
  final DateTime? pausedAt;

  /// Total length of finished pauses.
  final int pausedMs;

  /// Local only (not sent to the server): the length of the current rest, so a backwards clock jump can never show
  /// more than was set, and the progress ring has a denominator.
  final int restTotalMs;

  /// Local only: when the current timed hold began.
  final DateTime? workStartedAt;
  final DateTime? savedAt;

  bool get isPaused => phase == WorkPhase.paused;
  bool get pausedDuringRest => isPaused && restEndsAt != null;

  EngineState copyWith({
    int? idx,
    int? setIdx,
    WorkPhase? phase,
    DateTime? restEndsAt,
    bool clearRestEndsAt = false,
    DateTime? pausedAt,
    bool clearPausedAt = false,
    int? pausedMs,
    int? restTotalMs,
    DateTime? workStartedAt,
    bool clearWorkStartedAt = false,
    DateTime? savedAt,
  }) =>
      EngineState(
        idx: idx ?? this.idx,
        setIdx: setIdx ?? this.setIdx,
        phase: phase ?? this.phase,
        restEndsAt: clearRestEndsAt ? null : (restEndsAt ?? this.restEndsAt),
        pausedAt: clearPausedAt ? null : (pausedAt ?? this.pausedAt),
        pausedMs: pausedMs ?? this.pausedMs,
        restTotalMs: restTotalMs ?? this.restTotalMs,
        workStartedAt: clearWorkStartedAt ? null : (workStartedAt ?? this.workStartedAt),
        savedAt: savedAt ?? this.savedAt,
      );

  /// The subset `PUT /workout-sessions/{id}/state` accepts. `active_ms` is computed for the server's benefit.
  Map<String, dynamic> toServerJson({required DateTime startedAt, required DateTime now}) => {
        'idx': idx,
        'setIdx': setIdx,
        'phase': phase.name,
        if (restEndsAt != null) 'rest_ends_at': restEndsAt!.toUtc().toIso8601String(),
        if (pausedAt != null) 'paused_at': pausedAt!.toUtc().toIso8601String(),
        'paused_ms': pausedMs,
        'active_ms': SessionEngine.activeElapsed(this, startedAt: startedAt, now: now).inMilliseconds,
        'saved_at': now.toUtc().toIso8601String(),
      };

  Map<String, dynamic> toLocalJson() => {
        'idx': idx,
        'setIdx': setIdx,
        'phase': phase.name,
        'rest_ends_at': restEndsAt?.toUtc().toIso8601String(),
        'paused_at': pausedAt?.toUtc().toIso8601String(),
        'paused_ms': pausedMs,
        'rest_total_ms': restTotalMs,
        'work_started_at': workStartedAt?.toUtc().toIso8601String(),
        'saved_at': savedAt?.toUtc().toIso8601String(),
      };

  /// From the local copy or the server's. Unknown / damaged input yields `null` (the caller starts fresh).
  static EngineState? fromJson(Map<String, dynamic>? j) {
    if (j == null) return null;
    try {
      DateTime? t(Object? v) => v is String ? DateTime.parse(v).toUtc() : null;
      final phase = WorkPhase.values.where((p) => p.name == j['phase']).firstOrNull;
      if (phase == null) return null;
      return EngineState(
        idx: (j['idx'] as num).toInt(),
        setIdx: (j['setIdx'] as num).toInt(),
        phase: phase,
        restEndsAt: t(j['rest_ends_at']),
        pausedAt: t(j['paused_at']),
        pausedMs: (j['paused_ms'] as num?)?.toInt() ?? 0,
        restTotalMs: (j['rest_total_ms'] as num?)?.toInt() ?? 0,
        workStartedAt: t(j['work_started_at']),
        savedAt: t(j['saved_at']),
      );
    } catch (_) {
      return null;
    }
  }
}

abstract final class SessionEngine {
  // ------------------------------------------------------------------------------------------------ positions

  /// Where a session stands given what has been logged: the first exercise that still has sets to do.
  static EngineState initial(List<ExerciseStep> steps) {
    final i = _firstOpen(steps, 0);
    if (i == null) return const EngineState(idx: 0, setIdx: 0, phase: WorkPhase.finish);
    final s = steps[i].loggedSets;
    return EngineState(idx: i, setIdx: s, phase: s == 0 ? WorkPhase.intro : WorkPhase.work);
  }

  static int? _firstOpen(List<ExerciseStep> steps, int from) {
    for (var i = from; i < steps.length; i++) {
      if (steps[i].isOpen) return i;
    }
    return null;
  }

  /// Re-anchors a stored state to what the server says has actually been logged (another device, a lost save):
  /// if the stored position points at something that is already done, move to the first open exercise.
  static EngineState reconcile(EngineState s, List<ExerciseStep> steps) {
    if (s.phase == WorkPhase.finish) return _firstOpen(steps, 0) == null ? s : initial(steps);
    if (s.idx >= steps.length || !steps[s.idx].isOpen || s.setIdx < steps[s.idx].loggedSets) {
      return initial(steps).copyWith(pausedMs: s.pausedMs);
    }
    return s;
  }

  // -------------------------------------------------------------------------------------------------- actions

  /// The member begins the set shown on screen (from the intro, or after the rest).
  static EngineState startSet(EngineState s, DateTime now) {
    if (s.phase != WorkPhase.intro && s.phase != WorkPhase.work && s.phase != WorkPhase.rest) return s;
    return s.copyWith(phase: WorkPhase.work, clearRestEndsAt: true, workStartedAt: now, savedAt: now);
  }

  /// A set was logged. [steps] must already include it. Enters the rest (when there is more to do and the exercise
  /// has a rest time) and points at the next set or exercise.
  static EngineState setLogged(EngineState s, List<ExerciseStep> steps, DateTime now) {
    final current = s.idx < steps.length ? steps[s.idx] : null;
    int? nextIdx;
    var nextSet = 0;
    if (current != null && current.isOpen) {
      nextIdx = s.idx;
      nextSet = current.loggedSets;
    } else {
      nextIdx = _firstOpen(steps, s.idx + 1) ?? _firstOpen(steps, 0);
      nextSet = nextIdx == null ? 0 : steps[nextIdx].loggedSets;
    }
    if (nextIdx == null) {
      return EngineState(idx: s.idx, setIdx: s.setIdx, phase: WorkPhase.finish, pausedMs: s.pausedMs, savedAt: now);
    }

    final restMs = (current?.restSeconds ?? 0) * 1000;
    if (restMs <= 0) {
      return EngineState(idx: nextIdx, setIdx: nextSet, phase: nextSet == 0 ? WorkPhase.intro : WorkPhase.work, pausedMs: s.pausedMs, savedAt: now);
    }
    return EngineState(
      idx: nextIdx,
      setIdx: nextSet,
      phase: WorkPhase.rest,
      restEndsAt: now.add(Duration(milliseconds: restMs)),
      restTotalMs: restMs,
      pausedMs: s.pausedMs,
      savedAt: now,
    );
  }

  /// Skip the rest early.
  static EngineState skipRest(EngineState s, DateTime now) => s.phase == WorkPhase.rest ? _afterRest(s, now) : s;

  /// "+30 s" on the rest timer.
  static EngineState extendRest(EngineState s, Duration by, DateTime now) {
    if (s.phase != WorkPhase.rest || s.restEndsAt == null) return s;
    return s.copyWith(restEndsAt: s.restEndsAt!.add(by), restTotalMs: s.restTotalMs + by.inMilliseconds, savedAt: now);
  }

  /// The member skipped the current exercise ([steps] already marks it skipped).
  static EngineState exerciseSkipped(EngineState s, List<ExerciseStep> steps, DateTime now) {
    final next = _firstOpen(steps, s.idx + 1) ?? _firstOpen(steps, 0);
    if (next == null) return EngineState(idx: s.idx, setIdx: s.setIdx, phase: WorkPhase.finish, pausedMs: s.pausedMs, savedAt: now);
    final done = steps[next].loggedSets;
    return EngineState(idx: next, setIdx: done, phase: done == 0 ? WorkPhase.intro : WorkPhase.work, pausedMs: s.pausedMs, savedAt: now);
  }

  static EngineState pause(EngineState s, DateTime now) {
    if (s.phase != WorkPhase.work && s.phase != WorkPhase.rest) return s;
    return s.copyWith(phase: WorkPhase.paused, pausedAt: now, savedAt: now);
  }

  static EngineState resume(EngineState s, DateTime now) {
    if (!s.isPaused || s.pausedAt == null) return s;
    final paused = now.difference(s.pausedAt!);
    final length = paused.isNegative ? Duration.zero : paused;
    final inRest = s.restEndsAt != null;
    return s.copyWith(
      phase: inRest ? WorkPhase.rest : WorkPhase.work,
      restEndsAt: inRest ? s.restEndsAt!.add(length) : null,
      clearPausedAt: true,
      pausedMs: s.pausedMs + length.inMilliseconds,
      workStartedAt: inRest ? null : (s.workStartedAt?.add(length)),
      savedAt: now,
    );
  }

  /// Call on every tick and when the app returns to the foreground: finishes a rest whose time has run out.
  static EngineState normalize(EngineState s, DateTime now) {
    if (s.phase == WorkPhase.rest && s.restEndsAt != null && !now.isBefore(s.restEndsAt!)) return _afterRest(s, now);
    return s;
  }

  static EngineState _afterRest(EngineState s, DateTime now) =>
      s.copyWith(phase: s.setIdx == 0 ? WorkPhase.intro : WorkPhase.work, clearRestEndsAt: true, restTotalMs: 0, workStartedAt: s.setIdx == 0 ? null : now, savedAt: now);

  // ----------------------------------------------------------------------------------------------- readouts

  /// Time left in the rest (zero when not resting). Never longer than the rest that was set, even if the device clock
  /// was moved backwards.
  static Duration remainingRest(EngineState s, DateTime now) {
    if (s.restEndsAt == null) return Duration.zero;
    final reference = s.isPaused ? s.pausedAt! : now;
    var left = s.restEndsAt!.difference(reference);
    if (left.isNegative) return Duration.zero;
    final cap = Duration(milliseconds: s.restTotalMs);
    if (s.restTotalMs > 0 && left > cap) left = cap;
    return left;
  }

  /// Time left in a timed hold started at [workStartedAt] (zero when not holding).
  static Duration remainingHold(EngineState s, DateTime now, int holdSeconds) {
    final start = s.workStartedAt;
    if (start == null || s.phase != WorkPhase.work) return Duration(seconds: holdSeconds);
    final left = Duration(seconds: holdSeconds) - now.difference(start);
    if (left.isNegative) return Duration.zero;
    return left > Duration(seconds: holdSeconds) ? Duration(seconds: holdSeconds) : left;
  }

  /// Time actually spent working out: wall time since [startedAt] minus every pause (including one in progress).
  static Duration activeElapsed(EngineState s, {required DateTime startedAt, required DateTime now}) {
    final end = s.isPaused && s.pausedAt != null ? s.pausedAt! : now;
    final total = end.difference(startedAt) - Duration(milliseconds: s.pausedMs);
    return total.isNegative ? Duration.zero : total;
  }
}
