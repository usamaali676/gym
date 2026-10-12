import 'plan.dart';

/// A programme (training plan) as listed by `GET /programs` and detailed by `GET /programs/{id}`.
class Programme {
  const Programme({
    required this.id,
    required this.name,
    required this.description,
    required this.isDefault,
    required this.isSelected,
    required this.locked,
    required this.requiredEntitlement,
    required this.trainingDays,
    required this.phasesCount,
  });

  final int id;
  final String name;
  final String? description;
  final bool isDefault;
  final bool isSelected;

  /// The plan the member is on does not include this programme. The server then sends only this card.
  final bool locked;
  final String? requiredEntitlement;
  final int? trainingDays;
  final int? phasesCount;

  factory Programme.fromJson(Map<String, dynamic> j) => Programme(
        id: (j['id'] as num).toInt(),
        name: (j['name'] ?? '').toString(),
        description: j['description'] as String?,
        isDefault: j['is_default'] == true,
        isSelected: j['is_selected'] == true,
        locked: j['locked'] == true,
        requiredEntitlement: j['required_entitlement'] as String?,
        trainingDays: (j['training_days'] as num?)?.toInt(),
        phasesCount: (j['phases_count'] as num?)?.toInt(),
      );
}

/// A sprint or phase an admin defined (weeks 1-4 "Foundation", ...). Programmes without any are simply weekly.
class ProgrammePhase {
  const ProgrammePhase({required this.kind, required this.name, required this.summary, required this.startsWeek, required this.endsWeek});

  /// `sprint` or `phase`
  final String kind;
  final String name;
  final String? summary;
  final int? startsWeek;
  final int? endsWeek;

  String get weeks => startsWeek == null ? '' : (endsWeek == null || endsWeek == startsWeek ? 'Week $startsWeek' : 'Weeks $startsWeek-$endsWeek');

  factory ProgrammePhase.fromJson(Map<String, dynamic> j) => ProgrammePhase(
        kind: (j['kind'] ?? 'phase').toString(),
        name: (j['name'] ?? '').toString(),
        summary: j['summary'] as String?,
        startsWeek: (j['starts_week'] as num?)?.toInt(),
        endsWeek: (j['ends_week'] as num?)?.toInt(),
      );
}

class ProgrammeDay {
  const ProgrammeDay({required this.header, required this.estimatedMinutes, required this.exercises});
  final DayHeader header;
  final int estimatedMinutes;
  final List<PlanExercise> exercises;

  factory ProgrammeDay.fromJson(Map<String, dynamic> j) => ProgrammeDay(
        header: DayHeader.fromJson(j),
        estimatedMinutes: (j['estimated_minutes'] as num?)?.toInt() ?? 0,
        exercises: [for (final e in (j['exercises'] as List? ?? const [])) PlanExercise.fromJson(Map<String, dynamic>.from(e as Map))],
      );
}

class ProgrammeDetail {
  const ProgrammeDetail({required this.programme, required this.phases, required this.days});
  final Programme programme;
  final List<ProgrammePhase> phases;
  final List<ProgrammeDay> days;

  factory ProgrammeDetail.fromJson(Object? json) {
    final j = Map<String, dynamic>.from(json! as Map);
    return ProgrammeDetail(
      programme: Programme.fromJson(j),
      phases: [for (final p in (j['phases'] as List? ?? const [])) ProgrammePhase.fromJson(Map<String, dynamic>.from(p as Map))],
      days: [for (final d in (j['days'] as List? ?? const [])) ProgrammeDay.fromJson(Map<String, dynamic>.from(d as Map))],
    );
  }
}
