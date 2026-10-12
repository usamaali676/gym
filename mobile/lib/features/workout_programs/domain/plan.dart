// The weekly plan as `GET /plan/week` and `GET /plan/days/{weekday}` describe it.

DateTime _day(Object? v) => DateTime.parse(v.toString());

/// completed | skipped | in_progress | rest | missed | today | upcoming
typedef DayStatus = String;

class DayHeader {
  const DayHeader({required this.id, required this.weekday, required this.name, required this.title, required this.label, required this.focus, required this.isRest, this.description, this.recoveryNote});

  final int id;
  final int weekday;
  final String name;
  final String title;
  final String? label;
  final String? focus;
  final bool isRest;
  final String? description;
  final String? recoveryNote;

  factory DayHeader.fromJson(Map<String, dynamic> j) => DayHeader(
        id: (j['id'] as num?)?.toInt() ?? 0,
        weekday: (j['weekday'] as num?)?.toInt() ?? (j['day_of_week'] as num?)?.toInt() ?? 1,
        name: (j['name'] ?? '').toString(),
        title: (j['title'] ?? j['name'] ?? '').toString(),
        label: j['label'] as String?,
        focus: j['focus'] as String?,
        isRest: j['is_rest'] == true,
        description: j['description'] as String?,
        recoveryNote: j['recovery_note'] as String?,
      );
}

class WeekDayEntry {
  const WeekDayEntry({required this.date, required this.weekday, required this.status, required this.isToday, required this.isTraining, required this.day, required this.sessionId, required this.sessionStatus});

  final DateTime date;
  final int weekday;
  final DayStatus status;
  final bool isToday;
  final bool isTraining;
  final DayHeader? day;
  final int? sessionId;
  final String? sessionStatus;

  factory WeekDayEntry.fromJson(Map<String, dynamic> j) {
    final session = j['session'] is Map ? Map<String, dynamic>.from(j['session'] as Map) : null;
    return WeekDayEntry(
      date: _day(j['date']),
      weekday: (j['weekday'] as num).toInt(),
      status: (j['status'] ?? 'upcoming').toString(),
      isToday: j['is_today'] == true,
      isTraining: j['is_training'] == true,
      day: j['day'] is Map ? DayHeader.fromJson(Map<String, dynamic>.from(j['day'] as Map)) : null,
      sessionId: (session?['id'] as num?)?.toInt(),
      sessionStatus: session?['status'] as String?,
    );
  }
}

class WeekPlan {
  const WeekPlan({required this.programName, required this.weekNumber, required this.monday, required this.sunday, required this.isThisWeek, required this.done, required this.target, required this.days});

  final String? programName;
  final int weekNumber;
  final DateTime monday;
  final DateTime sunday;
  final bool isThisWeek;
  final int done;
  final int target;
  final List<WeekDayEntry> days;

  factory WeekPlan.fromJson(Object? json) {
    final j = Map<String, dynamic>.from(json! as Map);
    final progress = Map<String, dynamic>.from(j['progress'] as Map? ?? const {});
    final program = j['program'] is Map ? Map<String, dynamic>.from(j['program'] as Map) : null;
    return WeekPlan(
      programName: program?['name'] as String?,
      weekNumber: (j['week_number'] as num?)?.toInt() ?? 1,
      monday: _day(j['monday']),
      sunday: _day(j['sunday']),
      isThisWeek: j['is_this_week'] == true,
      done: (progress['done'] as num?)?.toInt() ?? 0,
      target: (progress['target'] as num?)?.toInt() ?? 0,
      days: [for (final d in (j['days'] as List? ?? const [])) WeekDayEntry.fromJson(Map<String, dynamic>.from(d as Map))],
    );
  }
}

/// What the server suggests for the next time: strings it composed ("60 kg × 8, 8, 7" -> "62.5 kg × 8").
class OverloadTarget {
  const OverloadTarget({required this.last, required this.next, required this.reason});
  final String last;
  final String next;
  final String reason;

  factory OverloadTarget.fromJson(Map<String, dynamic> j) => OverloadTarget(last: (j['last'] ?? '').toString(), next: (j['next'] ?? '').toString(), reason: (j['reason'] ?? '').toString());
}

class PlanExercise {
  const PlanExercise({
    required this.itemId,
    required this.position,
    required this.name,
    required this.exerciseId,
    required this.slug,
    required this.muscleGroup,
    required this.isTimed,
    required this.sets,
    required this.repsLabel,
    required this.focus,
    required this.notes,
    required this.restSeconds,
    required this.swapped,
    required this.target,
  });

  final int? itemId;
  final int position;
  final String name;
  final int? exerciseId;
  final String? slug;
  final String? muscleGroup;
  final bool isTimed;
  final int sets;
  final String repsLabel;
  final String? focus;
  final String? notes;
  final int restSeconds;
  final bool swapped;
  final OverloadTarget? target;

  /// Used by both `/plan/days/{n}` (nested `exercise`) and `/programs/{id}` (flat) payloads.
  factory PlanExercise.fromJson(Map<String, dynamic> j) {
    final ex = j['exercise'] is Map ? Map<String, dynamic>.from(j['exercise'] as Map) : const <String, dynamic>{};
    return PlanExercise(
      itemId: ((j['item_id'] ?? j['id']) as num?)?.toInt(),
      position: (j['position'] as num?)?.toInt() ?? 0,
      name: (j['name'] ?? ex['name'] ?? '').toString(),
      exerciseId: ((ex['id'] ?? j['exercise_id']) as num?)?.toInt(),
      slug: (ex['slug'] ?? j['exercise_slug']) as String?,
      muscleGroup: (ex['muscle_group'] ?? j['muscle_group']) as String?,
      isTimed: (ex['is_timed'] ?? j['is_timed']) == true,
      sets: (j['sets'] as num?)?.toInt() ?? 0,
      repsLabel: (j['reps_label'] ?? '').toString(),
      focus: j['focus'] as String?,
      notes: j['notes'] as String?,
      restSeconds: (j['rest_seconds'] as num?)?.toInt() ?? 0,
      swapped: j['swapped'] == true,
      target: j['target'] is Map ? OverloadTarget.fromJson(Map<String, dynamic>.from(j['target'] as Map)) : null,
    );
  }
}

class DaySession {
  const DaySession({required this.id, required this.status, required this.xpEarned});
  final int id;
  final String status;
  final int xpEarned;
}

class DayDetail {
  const DayDetail({required this.date, required this.weekday, required this.weekNumber, required this.status, required this.estimatedMinutes, required this.canAct, required this.isFuture, required this.header, required this.session, required this.exercises});

  final DateTime date;
  final int weekday;
  final int weekNumber;
  final DayStatus status;
  final int estimatedMinutes;

  /// The member may start / skip this day now (a training day, today or a few days back).
  final bool canAct;
  final bool isFuture;
  final DayHeader header;
  final DaySession? session;
  final List<PlanExercise> exercises;

  factory DayDetail.fromJson(Object? json) {
    final j = Map<String, dynamic>.from(json! as Map);
    final session = j['session'] is Map ? Map<String, dynamic>.from(j['session'] as Map) : null;
    return DayDetail(
      date: _day(j['date']),
      weekday: (j['weekday'] as num).toInt(),
      weekNumber: (j['week_number'] as num?)?.toInt() ?? 1,
      status: (j['status'] ?? 'upcoming').toString(),
      estimatedMinutes: (j['estimated_minutes'] as num?)?.toInt() ?? 0,
      canAct: j['can_act'] == true,
      isFuture: j['is_future'] == true,
      header: DayHeader.fromJson(Map<String, dynamic>.from(j['day'] as Map)),
      session: session == null ? null : DaySession(id: (session['id'] as num).toInt(), status: session['status'].toString(), xpEarned: (session['xp_earned'] as num?)?.toInt() ?? 0),
      exercises: [for (final e in (j['exercises'] as List? ?? const [])) PlanExercise.fromJson(Map<String, dynamic>.from(e as Map))],
    );
  }
}

String dateParam(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
