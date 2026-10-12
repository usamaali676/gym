import '../../membership/domain/membership.dart';

/// The home screen's data: one call, `GET /dashboard`.
class Dashboard {
  const Dashboard({
    required this.greetingPeriod,
    required this.workout,
    required this.weekDays,
    required this.weekDone,
    required this.weekTarget,
    required this.streak,
    required this.level,
    required this.xpTotal,
    required this.xpInto,
    required this.xpNeeded,
    required this.nutrition,
    required this.quote,
    required this.entitlements,
  });

  final String greetingPeriod;
  final TodayWorkout workout;
  final List<WeekDay> weekDays;
  final int weekDone;
  final int weekTarget;
  final int streak;
  final int level;
  final int xpTotal;
  final int xpInto;
  final int xpNeeded;

  /// `null` when the plan does not include nutrition.
  final NutritionGlance? nutrition;
  final String? quote;
  final Entitlements entitlements;

  double get xpProgress => xpNeeded <= 0 ? 0 : (xpInto / xpNeeded).clamp(0, 1).toDouble();

  factory Dashboard.fromJson(Object? json) {
    final j = Map<String, dynamic>.from(json! as Map);
    final week = Map<String, dynamic>.from(j['week'] as Map? ?? const {});
    final xp = Map<String, dynamic>.from(j['xp'] as Map? ?? const {});
    final membership = Map<String, dynamic>.from(j['membership'] as Map? ?? const {});
    return Dashboard(
      greetingPeriod: (j['greeting_period'] ?? 'morning').toString(),
      workout: TodayWorkout.fromJson(Map<String, dynamic>.from(j['workout'] as Map? ?? const {})),
      weekDays: [for (final d in (week['days'] as List? ?? const [])) WeekDay.fromJson(Map<String, dynamic>.from(d as Map))],
      weekDone: (week['done'] as num?)?.toInt() ?? 0,
      weekTarget: (week['target'] as num?)?.toInt() ?? 0,
      streak: (j['streak'] as num?)?.toInt() ?? 0,
      level: (xp['level'] as num?)?.toInt() ?? 1,
      xpTotal: (xp['total'] as num?)?.toInt() ?? 0,
      xpInto: (xp['into'] as num?)?.toInt() ?? 0,
      xpNeeded: (xp['needed'] as num?)?.toInt() ?? 1,
      nutrition: j['nutrition'] is Map ? NutritionGlance.fromJson(Map<String, dynamic>.from(j['nutrition'] as Map)) : null,
      quote: j['quote'] as String?,
      entitlements: Entitlements.fromJson(membership['entitlements']),
    );
  }
}

class TodayWorkout {
  const TodayWorkout({required this.status, required this.title, required this.label, required this.focus, required this.isRest, required this.estimatedMinutes, required this.openSessionId, required this.weekday});

  /// completed | skipped | in_progress | rest | missed | today | upcoming
  final String status;
  final String? title;
  final String? label;
  final String? focus;
  final bool isRest;
  final int estimatedMinutes;

  /// A workout the member started and has not finished (offer "Resume").
  final int? openSessionId;
  final int? weekday;

  bool get hasDay => title != null;
  bool get isCompleted => status == 'completed';

  factory TodayWorkout.fromJson(Map<String, dynamic> j) {
    final day = j['day'] is Map ? Map<String, dynamic>.from(j['day'] as Map) : null;
    return TodayWorkout(
      status: (j['status'] ?? 'rest').toString(),
      title: day?['title'] as String?,
      label: day?['label'] as String?,
      focus: day?['focus'] as String?,
      isRest: day?['is_rest'] == true || j['status'] == 'rest',
      estimatedMinutes: (j['estimated_minutes'] as num?)?.toInt() ?? 0,
      openSessionId: (j['open_session_id'] as num?)?.toInt(),
      weekday: (day?['weekday'] as num?)?.toInt(),
    );
  }
}

class WeekDay {
  const WeekDay({required this.date, required this.weekday, required this.status, required this.isToday});
  final DateTime date;
  final int weekday;
  final String status;
  final bool isToday;

  factory WeekDay.fromJson(Map<String, dynamic> j) => WeekDay(
        date: DateTime.parse(j['date'].toString()),
        weekday: (j['weekday'] as num).toInt(),
        status: (j['status'] ?? 'upcoming').toString(),
        isToday: j['is_today'] == true,
      );
}

class NutritionGlance {
  const NutritionGlance({required this.caloriesTarget, required this.caloriesEaten, required this.proteinTarget, required this.proteinEaten});
  final double caloriesTarget;
  final double caloriesEaten;
  final double proteinTarget;
  final double proteinEaten;

  factory NutritionGlance.fromJson(Map<String, dynamic> j) {
    final t = Map<String, dynamic>.from(j['targets'] as Map? ?? const {});
    final d = Map<String, dynamic>.from(j['totals'] as Map? ?? const {});
    double n(Object? v) => (v as num?)?.toDouble() ?? 0;
    return NutritionGlance(caloriesTarget: n(t['calories']), caloriesEaten: n(d['calories']), proteinTarget: n(t['protein_g']), proteinEaten: n(d['protein_g']));
  }
}
