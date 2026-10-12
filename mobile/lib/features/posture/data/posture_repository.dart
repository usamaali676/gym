import '../../../core/api/api_client.dart';
import '../../../core/api/api_error.dart';
import '../../../core/api/loaded.dart';
import '../../../core/storage/json_cache.dart';
import '../domain/posture_models.dart';

class PostureRepository {
  PostureRepository({required this._api, required this._cache});

  final ApiClient _api;
  final JsonCache _cache;

  Future<Loaded<List<PostureExercise>>> exercises() => loadWithCache(
        cache: _cache,
        key: 'posture.exercises',
        fetch: () async => (await _api.get('/posture/exercises')).data,
        parse: (json) => [for (final e in json! as List) PostureExercise.fromJson(Map<String, dynamic>.from(e as Map))],
      );

  /// The rule is cached so a set can start without a connection once it has been fetched.
  Future<Loaded<PostureRule>> rule(String slug) =>
      loadWithCache(cache: _cache, key: 'posture.rule.$slug', fetch: () async => (await _api.get('/posture/rules/$slug')).data, parse: PostureRule.fromJson);

  /// The body of `POST /posture/sessions` and of the offline queue's `posture.session.save`: numbers only, never
  /// pictures or landmarks.
  static Map<String, dynamic> payload(PostureRule rule, FinishedSet set, {int? workoutSessionId}) {
    final s = set.summary;
    final fps = set.avgFps;
    return {
      'client_uuid': set.clientUuid,
      'exercise_id': rule.exercise.id,
      'posture_rule_id': rule.ruleId,
      'workout_session_id': ?workoutSessionId,
      'rule_version': rule.version,
      'camera_view': rule.cameraView,
      'started_at': set.startedAt.toUtc().toIso8601String(),
      'avg_fps': ?(fps == null ? null : (fps * 10).round() / 10),
      ...s.toJson(),
      'duration_seconds': s.durationSeconds < 1 ? 1 : s.durationSeconds,
    };
  }

  /// Saves the numeric summary of a set. The same [FinishedSet] can be sent again safely: the server recognises its
  /// `client_uuid`.
  Future<PostureSaveResult> save(PostureRule rule, FinishedSet set, {int? workoutSessionId}) => readServer(() async {
    final response = await _api.post('/posture/sessions', idempotent: true, body: payload(rule, set, workoutSessionId: workoutSessionId));
    return PostureSaveResult.fromJson(response.map);
  });
}
