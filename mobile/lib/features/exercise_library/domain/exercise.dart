/// An exercise as `GET /exercises` and `GET /exercises/{slug}` return it. A premium exercise outside the member's
/// plan arrives as a teaser (`locked: true`, no instructions): the server withholds the rest.
class ExerciseMedia {
  const ExerciseMedia({required this.id, required this.kind, required this.view, required this.url, required this.checksum, required this.mime, this.durationMs});

  final int id;

  /// demo_video | instruction_video | image | thumbnail
  final String kind;

  /// angle | side | front
  final String? view;

  /// Signed and expiring: cache the file by [id] + [checksum], never by URL.
  final String url;
  final String? checksum;
  final String? mime;
  final int? durationMs;

  factory ExerciseMedia.fromJson(Map<String, dynamic> j) => ExerciseMedia(
        id: (j['id'] as num).toInt(),
        kind: (j['kind'] ?? '').toString(),
        view: j['view'] as String?,
        url: (j['url'] ?? '').toString(),
        checksum: j['checksum'] as String?,
        mime: j['mime'] as String?,
        durationMs: (j['duration_ms'] as num?)?.toInt(),
      );
}

class Exercise {
  const Exercise({
    required this.id,
    required this.slug,
    required this.name,
    required this.muscleGroup,
    required this.muscleLabel,
    required this.secondaryGroups,
    required this.mechanic,
    required this.difficulty,
    required this.equipment,
    required this.isTimed,
    required this.perSide,
    required this.locked,
    required this.requiredEntitlement,
    required this.description,
    required this.formCues,
    required this.commonMistakes,
    required this.breathing,
    required this.defaultRestSeconds,
    required this.defaultHoldSeconds,
    required this.postureSupported,
    required this.media,
  });

  final int id;
  final String slug;
  final String name;
  final String muscleGroup;
  final String muscleLabel;
  final List<String> secondaryGroups;
  final String? mechanic;
  final String? difficulty;
  final List<String> equipment;
  final bool isTimed;
  final bool perSide;
  final bool locked;
  final String? requiredEntitlement;
  final String? description;
  final List<String> formCues;
  final List<String> commonMistakes;
  final String? breathing;
  final int? defaultRestSeconds;
  final int? defaultHoldSeconds;
  final bool postureSupported;
  final List<ExerciseMedia> media;

  factory Exercise.fromJson(Map<String, dynamic> j) {
    List<String> strings(Object? v) => [for (final x in (v as List? ?? const [])) x.toString()];
    final rest = j['rest'] is Map ? Map<String, dynamic>.from(j['rest'] as Map) : const <String, dynamic>{};
    final posture = j['posture'] is Map ? Map<String, dynamic>.from(j['posture'] as Map) : const <String, dynamic>{};
    return Exercise(
      id: (j['id'] as num).toInt(),
      slug: (j['slug'] ?? '').toString(),
      name: (j['name'] ?? '').toString(),
      muscleGroup: (j['muscle_group'] ?? '').toString(),
      muscleLabel: (j['muscle_group_label'] ?? j['muscle_group'] ?? '').toString(),
      secondaryGroups: strings(j['secondary_groups']),
      mechanic: j['mechanic'] as String?,
      difficulty: j['difficulty'] as String?,
      equipment: strings(j['equipment']),
      isTimed: j['is_timed'] == true,
      perSide: j['per_side'] == true,
      locked: j['locked'] == true,
      requiredEntitlement: j['required_entitlement'] as String?,
      description: j['description'] as String?,
      formCues: strings(j['form_cues']),
      commonMistakes: strings(j['common_mistakes']),
      breathing: j['breathing'] as String?,
      defaultRestSeconds: (rest['default_seconds'] as num?)?.toInt(),
      defaultHoldSeconds: (j['default_hold_seconds'] as num?)?.toInt(),
      postureSupported: posture['supported'] == true,
      media: [for (final m in (j['media'] as List? ?? const [])) ExerciseMedia.fromJson(Map<String, dynamic>.from(m as Map))],
    );
  }
}

class ExercisePage {
  const ExercisePage({required this.items, required this.page, required this.lastPage, required this.total});
  final List<Exercise> items;
  final int page;
  final int lastPage;
  final int total;

  bool get hasMore => page < lastPage;

  /// `{"data": [...], "meta": {"current_page", "last_page", "total"}}`
  factory ExercisePage.fromJson(Object? json) {
    final j = Map<String, dynamic>.from(json! as Map);
    final meta = j['meta'] is Map ? Map<String, dynamic>.from(j['meta'] as Map) : const <String, dynamic>{};
    final items = [for (final e in (j['data'] as List? ?? const [])) Exercise.fromJson(Map<String, dynamic>.from(e as Map))];
    return ExercisePage(
      items: items,
      page: (meta['current_page'] as num?)?.toInt() ?? 1,
      lastPage: (meta['last_page'] as num?)?.toInt() ?? 1,
      total: (meta['total'] as num?)?.toInt() ?? items.length,
    );
  }
}

class LibraryFilters {
  const LibraryFilters({this.query = '', this.muscle, this.equipment, this.difficulty});
  final String query;
  final String? muscle;
  final String? equipment;
  final String? difficulty;

  bool get isActive => query.isNotEmpty || muscle != null || equipment != null || difficulty != null;

  LibraryFilters copyWith({String? query, Object? muscle = _keep, Object? equipment = _keep, Object? difficulty = _keep}) => LibraryFilters(
        query: query ?? this.query,
        muscle: identical(muscle, _keep) ? this.muscle : muscle as String?,
        equipment: identical(equipment, _keep) ? this.equipment : equipment as String?,
        difficulty: identical(difficulty, _keep) ? this.difficulty : difficulty as String?,
      );

  Map<String, dynamic> toQuery(int page) => {
        if (query.trim().isNotEmpty) 'q': query.trim(),
        'muscle': ?muscle,
        'equipment': ?equipment,
        'difficulty': ?difficulty,
        'page': page,
      };

  /// A cache key that is the same for the same filters.
  String get key => '${query.trim().toLowerCase()}|${muscle ?? ''}|${equipment ?? ''}|${difficulty ?? ''}'.replaceAll(RegExp(r'[^a-z0-9|_-]'), '_');

  static const _keep = Object();
}
