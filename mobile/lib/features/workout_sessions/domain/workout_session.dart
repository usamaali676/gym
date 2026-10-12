// A workout in progress (or finished), as `GET /workout-sessions/{id}` returns it.

DateTime? _time(Object? v) => v is String ? DateTime.tryParse(v)?.toUtc() : null;

class LoggedSet {
  const LoggedSet({required this.id, required this.number, required this.weightKg, required this.reps, required this.durationSeconds, required this.isPr, this.pending = false});

  /// Negative for a set that exists only on this phone so far.
  final int id;

  /// 1-based
  final int number;
  final double? weightKg;
  final int? reps;
  final int? durationSeconds;
  final bool isPr;

  /// Logged on this phone but not yet accepted by the server (it is in the offline queue).
  final bool pending;

  LoggedSet copyWith({bool? pending}) => LoggedSet(id: id, number: number, weightKg: weightKg, reps: reps, durationSeconds: durationSeconds, isPr: isPr, pending: pending ?? this.pending);

  factory LoggedSet.fromJson(Map<String, dynamic> j) => LoggedSet(
        id: (j['id'] as num).toInt(),
        number: (j['number'] as num).toInt(),
        weightKg: (j['weight_kg'] as num?)?.toDouble(),
        reps: (j['reps'] as num?)?.toInt(),
        durationSeconds: (j['duration_seconds'] as num?)?.toInt(),
        isPr: j['is_pr'] == true,
      );
}

class Suggestion {
  const Suggestion({required this.weightKg, required this.reps, required this.reason});
  final double? weightKg;
  final int? reps;
  final String? reason;

  factory Suggestion.fromJson(Map<String, dynamic> j) => Suggestion(weightKg: (j['weight_kg'] as num?)?.toDouble(), reps: (j['reps'] as num?)?.toInt(), reason: j['reason'] as String?);
}

class SessionExercise {
  const SessionExercise({
    required this.id,
    required this.exerciseId,
    required this.slug,
    required this.name,
    required this.status,
    required this.muscleGroupLabel,
    required this.isTimed,
    required this.holdSeconds,
    required this.targetSets,
    required this.repsMin,
    required this.repsMax,
    required this.restSeconds,
    required this.suggested,
    required this.lastSummary,
    required this.formCues,
    required this.commonMistakes,
    required this.breathing,
    required this.canSwap,
    required this.swappedFrom,
    required this.sets,
  });

  /// The session-exercise id: what set logging and skipping refer to.
  final int id;
  final int exerciseId;
  final String slug;
  final String name;

  /// pending | done | skipped
  final String status;
  final String? muscleGroupLabel;
  final bool isTimed;
  final int? holdSeconds;
  final int targetSets;
  final int? repsMin;
  final int? repsMax;
  final int restSeconds;
  final Suggestion suggested;

  /// "60 kg × 8, 8, 7" built from the server's `last` (null when the member has no history).
  final String? lastSummary;
  final List<String> formCues;
  final List<String> commonMistakes;
  final String? breathing;
  final bool canSwap;
  final String? swappedFrom;
  final List<LoggedSet> sets;

  bool get isSkipped => status == 'skipped';
  bool get isDone => status == 'done' || sets.length >= targetSets;
  bool get isOpen => !isSkipped && !isDone;

  String get repsLabel => repsMin == null ? '' : (repsMin == repsMax ? '$repsMin' : '$repsMin-$repsMax');

  SessionExercise copyWith({String? status, List<LoggedSet>? sets}) => SessionExercise(
        id: id,
        exerciseId: exerciseId,
        slug: slug,
        name: name,
        status: status ?? this.status,
        muscleGroupLabel: muscleGroupLabel,
        isTimed: isTimed,
        holdSeconds: holdSeconds,
        targetSets: targetSets,
        repsMin: repsMin,
        repsMax: repsMax,
        restSeconds: restSeconds,
        suggested: suggested,
        lastSummary: lastSummary,
        formCues: formCues,
        commonMistakes: commonMistakes,
        breathing: breathing,
        canSwap: canSwap,
        swappedFrom: swappedFrom,
        sets: sets ?? this.sets,
      );

  factory SessionExercise.fromJson(Map<String, dynamic> j) {
    List<String> strings(Object? v) => [for (final x in (v as List? ?? const [])) x.toString()];
    final last = j['last'] is Map ? Map<String, dynamic>.from(j['last'] as Map) : null;
    String? summary;
    if (last != null) {
      final parts = <String>[];
      double? weight;
      for (final s in (last['sets'] as List? ?? const [])) {
        final m = Map<String, dynamic>.from(s as Map);
        weight ??= (m['weight'] as num?)?.toDouble();
        parts.add(m['duration'] != null && (m['reps'] == null || m['reps'] == 0) ? '${m['duration']}s' : '${m['reps']}');
      }
      if (parts.isNotEmpty) summary = '${weight == null ? 'Bodyweight' : '${_kg(weight)} kg'} × ${parts.join(', ')}';
    }
    return SessionExercise(
      id: (j['id'] as num).toInt(),
      exerciseId: (j['exercise_id'] as num).toInt(),
      slug: (j['slug'] ?? '').toString(),
      name: (j['name'] ?? '').toString(),
      status: (j['status'] ?? 'pending').toString(),
      muscleGroupLabel: (j['muscle_group_label'] ?? j['muscle_group']) as String?,
      isTimed: j['is_timed'] == true,
      holdSeconds: (j['hold_seconds'] as num?)?.toInt(),
      targetSets: (j['target_sets'] as num?)?.toInt() ?? 1,
      repsMin: (j['reps_min'] as num?)?.toInt(),
      repsMax: (j['reps_max'] as num?)?.toInt(),
      restSeconds: (j['rest_seconds'] as num?)?.toInt() ?? 0,
      suggested: Suggestion.fromJson(Map<String, dynamic>.from(j['suggested'] as Map? ?? const {})),
      lastSummary: summary,
      formCues: strings(j['form_cues']),
      commonMistakes: strings(j['common_mistakes']),
      breathing: j['breathing'] as String?,
      canSwap: j['can_swap'] == true,
      swappedFrom: j['swapped_from'] as String?,
      sets: [for (final s in (j['sets'] as List? ?? const [])) LoggedSet.fromJson(Map<String, dynamic>.from(s as Map))],
    );
  }
}

String _kg(double v) => v == v.roundToDouble() ? v.toStringAsFixed(0) : v.toString();

class WorkoutSession {
  const WorkoutSession({required this.id, required this.status, required this.title, required this.startedAt, required this.serverTime, required this.exercises, required this.clientState, required this.soundOn});

  final int id;

  /// in_progress | paused | completed | skipped | abandoned
  final String status;
  final String title;
  final DateTime startedAt;
  final DateTime serverTime;
  final List<SessionExercise> exercises;

  /// The resume state the server holds (the engine's server-side subset), or null.
  final Map<String, dynamic>? clientState;
  final bool soundOn;

  bool get isOpen => status == 'in_progress' || status == 'paused';
  int get setsLogged => exercises.fold(0, (n, e) => n + e.sets.length);

  WorkoutSession withExercises(List<SessionExercise> exercises) => WorkoutSession(id: id, status: status, title: title, startedAt: startedAt, serverTime: serverTime, exercises: exercises, clientState: clientState, soundOn: soundOn);

  WorkoutSession withExercise(int index, SessionExercise exercise) =>
      WorkoutSession(id: id, status: status, title: title, startedAt: startedAt, serverTime: serverTime, exercises: [for (var i = 0; i < exercises.length; i++) i == index ? exercise : exercises[i]], clientState: clientState, soundOn: soundOn);

  factory WorkoutSession.fromJson(Object? json) {
    final j = Map<String, dynamic>.from(json! as Map);
    final prefs = Map<String, dynamic>.from(j['preferences'] as Map? ?? const {});
    return WorkoutSession(
      id: (j['id'] as num).toInt(),
      status: (j['status'] ?? 'in_progress').toString(),
      title: (j['title'] ?? 'Workout').toString(),
      startedAt: _time(j['started_at']) ?? DateTime.now().toUtc(),
      serverTime: _time(j['server_time']) ?? DateTime.now().toUtc(),
      exercises: [for (final e in (j['exercises'] as List? ?? const [])) SessionExercise.fromJson(Map<String, dynamic>.from(e as Map))],
      clientState: j['client_state'] is Map ? Map<String, dynamic>.from(j['client_state'] as Map) : null,
      soundOn: prefs['sound'] == true,
    );
  }
}

/// What the member entered for one set.
class SetEntry {
  const SetEntry({required this.sessionExerciseId, required this.setNumber, this.weightKg, this.reps, this.durationSeconds, this.rpe});

  final int sessionExerciseId;

  /// 1-based
  final int setNumber;
  final double? weightKg;
  final int? reps;
  final int? durationSeconds;
  final double? rpe;

  Map<String, dynamic> toJson() => {
        'session_exercise_id': sessionExerciseId,
        'set_number': setNumber,
        'weight_kg': ?weightKg,
        'reps': ?reps,
        'duration_seconds': ?durationSeconds,
        'rpe': ?rpe,
      };
}
