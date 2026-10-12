// Progress data, all computed on the server: BMI, 30-day change, trend, lean mass, records and XP are shown as given.

double? _d(Object? v) => (v as num?)?.toDouble();

class WeightPoint {
  const WeightPoint(this.date, this.kg);
  final DateTime date;
  final double kg;
}

class Measurement {
  const Measurement({required this.id, required this.date, required this.weightKg, required this.bodyFatPct, required this.waistCm, required this.notes, required this.circumferences});

  final int id;
  final DateTime date;
  final double? weightKg;
  final double? bodyFatPct;
  final double? waistCm;
  final String? notes;

  /// neck / shoulders / chest / waist / hips / bicep (left, right) / thigh / calf, in cm, when logged.
  final Map<String, double> circumferences;

  factory Measurement.fromJson(Map<String, dynamic> j) {
    const keys = ['neck_cm', 'shoulders_cm', 'chest_cm', 'waist_cm', 'hips_cm', 'bicep_left_cm', 'bicep_right_cm', 'thigh_cm', 'calf_cm'];
    return Measurement(
      id: (j['id'] as num).toInt(),
      date: DateTime.parse(j['measured_on'].toString()),
      weightKg: _d(j['weight_kg']),
      bodyFatPct: _d(j['body_fat_pct']),
      waistCm: _d(j['waist_cm']),
      notes: j['notes'] as String?,
      circumferences: {
        for (final k in keys)
          if (_d(j[k]) != null) k: _d(j[k])!,
      },
    );
  }
}

class ProgressSummary {
  const ProgressSummary({required this.latest, required this.weightPoints, required this.targetWeightKg, required this.change30dKg, required this.trendPerWeekKg, required this.bmi, required this.bmiCategory, required this.bodyFatPct, required this.bodyFatSource, required this.leanKg});

  final Measurement? latest;
  final List<WeightPoint> weightPoints;
  final double? targetWeightKg;
  final double? change30dKg;
  final double? trendPerWeekKg;
  final double? bmi;
  final String? bmiCategory;
  final double? bodyFatPct;

  /// logged | estimated: an estimate is labelled as one.
  final String? bodyFatSource;
  final double? leanKg;

  factory ProgressSummary.fromJson(Object? json) {
    final j = Map<String, dynamic>.from(json! as Map);
    return ProgressSummary(
      latest: j['latest'] is Map ? Measurement.fromJson(Map<String, dynamic>.from(j['latest'] as Map)) : null,
      weightPoints: [
        for (final p in (j['weight_points'] as List? ?? const [])) WeightPoint(DateTime.parse((p as Map)['date'].toString()), (p['weight_kg'] as num).toDouble()),
      ],
      targetWeightKg: _d(j['target_weight_kg']),
      change30dKg: _d(j['change_30d_kg']),
      trendPerWeekKg: _d(j['trend_per_week_kg']),
      bmi: _d(j['bmi']),
      bmiCategory: j['bmi_category'] as String?,
      bodyFatPct: _d(j['body_fat_pct']),
      bodyFatSource: j['body_fat_source'] as String?,
      leanKg: _d(j['lean_kg']),
    );
  }
}

class PersonalRecord {
  const PersonalRecord({required this.exerciseName, required this.slug, required this.bestWeightKg, required this.est1rm, required this.bestSetVolume, required this.latest});

  final String exerciseName;
  final String slug;
  final double? bestWeightKg;
  final double? est1rm;
  final double? bestSetVolume;
  final DateTime? latest;

  factory PersonalRecord.fromJson(Map<String, dynamic> j) {
    final ex = Map<String, dynamic>.from(j['exercise'] as Map? ?? const {});
    final rec = Map<String, dynamic>.from(j['records'] as Map? ?? const {});
    double? v(String key) => rec[key] is Map ? _d((rec[key] as Map)['value']) : null;
    return PersonalRecord(
      exerciseName: (ex['name'] ?? '').toString(),
      slug: (ex['slug'] ?? '').toString(),
      bestWeightKg: v('max_weight'),
      est1rm: v('est_1rm'),
      bestSetVolume: v('best_set_volume'),
      latest: DateTime.tryParse((j['latest'] ?? '').toString())?.toLocal(),
    );
  }
}

class StrengthPoint {
  const StrengthPoint({required this.date, required this.e1rm, required this.topWeight, required this.volume});
  final DateTime date;
  final double? e1rm;
  final double? topWeight;
  final double? volume;
}

class StrengthSeries {
  const StrengthSeries({required this.name, required this.points});
  final String name;
  final List<StrengthPoint> points;

  factory StrengthSeries.fromJson(Object? json) {
    final j = Map<String, dynamic>.from(json! as Map);
    return StrengthSeries(
      name: ((j['exercise'] as Map?)?['name'] ?? '').toString(),
      points: [
        for (final p in (j['series'] as List? ?? const []))
          StrengthPoint(date: DateTime.parse((p as Map)['date'].toString()), e1rm: _d(p['e1rm']), topWeight: _d(p['top_weight']), volume: _d(p['volume'])),
      ],
    );
  }
}

class BadgeProgress {
  const BadgeProgress({required this.value, required this.target, required this.pct});
  final double value;
  final double target;
  final double pct;
}

class BadgeInfo {
  const BadgeInfo({required this.slug, required this.name, required this.description, required this.category, required this.tier, required this.xpReward, required this.earned, required this.awardedAt, required this.progress});

  final String slug;
  final String name;
  final String description;
  final String category;
  final String tier;
  final int xpReward;
  final bool earned;
  final DateTime? awardedAt;
  final BadgeProgress? progress;

  factory BadgeInfo.fromJson(Map<String, dynamic> j) => BadgeInfo(
        slug: (j['slug'] ?? '').toString(),
        name: (j['name'] ?? '').toString(),
        description: (j['description'] ?? '').toString(),
        category: (j['category'] ?? '').toString(),
        tier: (j['tier'] ?? 'bronze').toString(),
        xpReward: (j['xp_reward'] as num?)?.toInt() ?? 0,
        earned: j['earned'] == true,
        awardedAt: DateTime.tryParse((j['awarded_at'] ?? '').toString())?.toLocal(),
        progress: j['progress'] is Map
            ? BadgeProgress(value: _d((j['progress'] as Map)['value']) ?? 0, target: _d((j['progress'] as Map)['target']) ?? 1, pct: _d((j['progress'] as Map)['pct']) ?? 0)
            : null,
      );
}

class Achievements {
  const Achievements({required this.level, required this.xpTotal, required this.xpInto, required this.xpNeeded, required this.streak, required this.longestStreak, required this.earnedCount, required this.badges});

  final int level;
  final int xpTotal;
  final int xpInto;
  final int xpNeeded;
  final int streak;
  final int longestStreak;
  final int earnedCount;
  final List<BadgeInfo> badges;

  double get xpProgress => xpNeeded <= 0 ? 0 : (xpInto / xpNeeded).clamp(0, 1).toDouble();

  factory Achievements.fromJson(Object? json) {
    final j = Map<String, dynamic>.from(json! as Map);
    final xp = Map<String, dynamic>.from(j['xp'] as Map? ?? const {});
    final streak = Map<String, dynamic>.from(j['streak'] as Map? ?? const {});
    return Achievements(
      level: (xp['level'] as num?)?.toInt() ?? 1,
      xpTotal: (xp['total'] as num?)?.toInt() ?? 0,
      xpInto: (xp['into'] as num?)?.toInt() ?? 0,
      xpNeeded: (xp['needed'] as num?)?.toInt() ?? 1,
      streak: (streak['current'] as num?)?.toInt() ?? 0,
      longestStreak: (streak['longest'] as num?)?.toInt() ?? 0,
      earnedCount: (j['earned_count'] as num?)?.toInt() ?? 0,
      badges: [for (final b in (j['badges'] as List? ?? const [])) BadgeInfo.fromJson(Map<String, dynamic>.from(b as Map))],
    );
  }
}
