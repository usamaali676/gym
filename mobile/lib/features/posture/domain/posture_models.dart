import 'package:flutter/foundation.dart';

import 'posture_analyzer.dart';
import 'rule_engine.dart';

/// An exercise the camera coach can judge (`GET /posture/exercises`).
class PostureExercise {
  const PostureExercise({required this.id, required this.slug, required this.name, required this.muscleGroup, required this.ruleId, required this.ruleVersion, required this.tier, required this.cameraView});

  final int id;
  final String slug;
  final String name;
  final String muscleGroup;
  final int ruleId;
  final int ruleVersion;

  /// full | generic | simplified: how purpose-built the rule is.
  final String tier;

  /// side | front | three_quarter
  final String cameraView;

  factory PostureExercise.fromJson(Map<String, dynamic> j) {
    final ex = Map<String, dynamic>.from(j['exercise'] as Map);
    return PostureExercise(
      id: (ex['id'] as num).toInt(),
      slug: ex['slug'] as String,
      name: ex['name'] as String,
      muscleGroup: (ex['muscle_group'] ?? '').toString(),
      ruleId: (j['rule_id'] as num).toInt(),
      ruleVersion: (j['rule_version'] as num).toInt(),
      tier: (j['tier'] ?? 'generic').toString(),
      cameraView: (j['camera_view'] ?? 'side').toString(),
    );
  }

  String get tierLabel => switch (tier) {
        'full' => 'Full rules',
        'simplified' => 'Simplified rules',
        _ => 'Generic rules',
      };

  String get viewLabel => switch (cameraView) {
        'front' => 'Film from the front',
        'three_quarter' => 'Film at an angle',
        _ => 'Film from the side',
      };
}

/// The on-screen instructions that come with a rule.
class PostureGuidance {
  const PostureGuidance({required this.title, required this.steps, required this.note, required this.tracks, required this.accuracy});

  final String title;
  final List<String> steps;
  final String note;

  /// What the coach looks at, in plain words.
  final List<String> tracks;

  /// An honest statement of what this rule can and cannot do.
  final String accuracy;

  factory PostureGuidance.fromJson(Object? json) {
    final j = json is Map ? Map<String, dynamic>.from(json) : const <String, dynamic>{};
    final camera = j['camera'] is Map ? Map<String, dynamic>.from(j['camera'] as Map) : const <String, dynamic>{};
    List<String> strings(Object? v) => [for (final s in (v as List? ?? const [])) s.toString()];
    return PostureGuidance(
      title: (camera['title'] ?? 'Set up your camera').toString(),
      steps: strings(camera['steps']),
      note: (camera['note'] ?? '').toString(),
      tracks: strings(j['tracks']),
      accuracy: (j['accuracy'] ?? '').toString(),
    );
  }
}

/// The rule the server published for an exercise, ready to run.
class PostureRule {
  PostureRule({required this.exercise, required this.ruleId, required this.version, required this.tier, required this.cameraView, required this.config, required this.compiled, required this.guidance});

  final PostureExercise exercise;
  final int ruleId;
  final int version;
  final String tier;
  final String cameraView;
  final Map<String, dynamic> config;
  final CompiledRule compiled;
  final PostureGuidance guidance;

  bool get timed => compiled.isHold;

  /// Label and advice for an error id in a summary, taken from the rule itself.
  String errorLabel(String id) => switch (id) {
        'range' => 'Incomplete range',
        'tempo' => 'Too fast',
        _ => compiled.checks.where((c) => c.id == id).firstOrNull?.label ?? id,
      };

  String errorAdvice(String id) => switch (id) {
        'range' => (compiled.rep is MovementRep) ? (compiled.rep as MovementRep).partialMessage : 'Go through the full range of motion.',
        'tempo' => 'Slow down and control the weight.',
        _ => compiled.checks.where((c) => c.id == id).firstOrNull?.message ?? '',
      };

  /// Throws [PostureRuleUnusable] when the rule cannot be run by this version of the app.
  factory PostureRule.fromJson(Object? json) {
    final j = Map<String, dynamic>.from(json! as Map);
    final ex = Map<String, dynamic>.from(j['exercise'] as Map);
    final config = Map<String, dynamic>.from(j['config'] as Map);
    final CompiledRule compiled;
    try {
      compiled = compileRule(config);
    } on RuleConfigError catch (e) {
      final newer = config['schema'] is num && (config['schema'] as num) > ruleSchema;
      throw PostureRuleUnusable(newer ? 'This exercise needs a newer version of the app.' : 'The coach rule for this exercise is not valid, so it cannot be used.', e.errors);
    }
    return PostureRule(
      exercise: PostureExercise(
        id: (ex['id'] as num).toInt(),
        slug: ex['slug'] as String,
        name: ex['name'] as String,
        muscleGroup: '',
        ruleId: (j['rule_id'] as num).toInt(),
        ruleVersion: (j['rule_version'] as num).toInt(),
        tier: (j['tier'] ?? 'generic').toString(),
        cameraView: (j['camera_view'] ?? 'side').toString(),
      ),
      ruleId: (j['rule_id'] as num).toInt(),
      version: (j['rule_version'] as num).toInt(),
      tier: (j['tier'] ?? 'generic').toString(),
      cameraView: (j['camera_view'] ?? 'side').toString(),
      config: config,
      compiled: compiled,
      guidance: PostureGuidance.fromJson(j['guidance']),
    );
  }
}

class PostureRuleUnusable implements Exception {
  const PostureRuleUnusable(this.message, [this.problems = const []]);

  final String message;
  final List<String> problems;

  @override
  String toString() => message;
}

/// What the server answered when a set was saved.
class PostureSaveResult {
  const PostureSaveResult({required this.created, required this.xpEarned, required this.level, required this.xpTotal, required this.badges});

  final bool created;
  final int xpEarned;
  final int level;
  final int xpTotal;
  final List<String> badges;

  factory PostureSaveResult.fromJson(Map<String, dynamic> j) {
    final xp = Map<String, dynamic>.from(j['xp'] as Map? ?? const {});
    final level = Map<String, dynamic>.from(j['level'] as Map? ?? const {});
    return PostureSaveResult(
      created: j['created'] == true,
      xpEarned: (xp['total'] as num?)?.toInt() ?? 0,
      level: (level['level'] as num?)?.toInt() ?? 1,
      xpTotal: (level['xp_total'] as num?)?.toInt() ?? 0,
      badges: [for (final b in (j['badges'] as List? ?? const [])) (b as Map)['name'].toString()],
    );
  }
}

/// The set that was just judged, with what the server needs to store it.
@immutable
class FinishedSet {
  const FinishedSet({required this.summary, required this.startedAt, required this.clientUuid, required this.avgFps});

  final PostureSummary summary;
  final DateTime startedAt;
  final String clientUuid;
  final double? avgFps;
}
