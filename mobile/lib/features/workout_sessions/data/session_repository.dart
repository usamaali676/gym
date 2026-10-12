import 'package:uuid/uuid.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_error.dart';
import '../../../core/storage/json_cache.dart';
import '../domain/session_engine.dart';
import '../domain/workout_session.dart';

/// The result of logging one set, as the server computed it.
class SetResult {
  const SetResult({required this.set, required this.isPr, required this.xpAwarded, required this.exerciseStatus});
  final LoggedSet set;
  final bool isPr;
  final int xpAwarded;
  final String exerciseStatus;
}

class FinishedSummary {
  const FinishedSummary({required this.title, required this.activeSeconds, required this.volumeKg, required this.setsCompleted, required this.xpEarned, required this.exercisesDone, required this.exercisesTotal, required this.personalRecords, required this.levelUp, required this.level, required this.streak, required this.badges, required this.xpBreakdown});

  final String title;
  final int activeSeconds;
  final double? volumeKg;
  final int setsCompleted;
  final int xpEarned;
  final int exercisesDone;
  final int exercisesTotal;
  final List<String> personalRecords;
  final bool levelUp;
  final int level;
  final int streak;
  final List<String> badges;
  final List<(String, int)> xpBreakdown;

  factory FinishedSummary.fromJson(Object? json) {
    final j = Map<String, dynamic>.from(json! as Map);
    final s = Map<String, dynamic>.from(j['session'] as Map? ?? const {});
    // {"set_complete": 40, "personal_record": 25, ...}: the server stores XP by type; show the type as words.
    final breakdown = <(String, int)>[];
    final raw = j['xp_breakdown'];
    if (raw is Map) {
      for (final e in raw.entries) {
        final words = e.key.toString().replaceAll('_', ' ');
        breakdown.add(('${words[0].toUpperCase()}${words.substring(1)}', (e.value as num?)?.toInt() ?? 0));
      }
    }
    return FinishedSummary(
      title: (s['title'] ?? 'Workout').toString(),
      activeSeconds: (s['active_seconds'] as num?)?.toInt() ?? 0,
      volumeKg: (s['volume_kg'] as num?)?.toDouble(),
      setsCompleted: (s['sets_completed'] as num?)?.toInt() ?? 0,
      xpEarned: (s['xp_earned'] as num?)?.toInt() ?? 0,
      exercisesDone: (j['exercises_done'] as num?)?.toInt() ?? 0,
      exercisesTotal: (j['exercises_total'] as num?)?.toInt() ?? 0,
      personalRecords: [
        for (final p in (j['personal_records'] as List? ?? const []))
          if (p is Map) '${p['exercise']}: ${p['weight_kg'] ?? 'bodyweight'}${p['weight_kg'] == null ? '' : ' kg'} × ${p['reps']}',
      ],
      levelUp: j['level_up'] == true,
      level: (j['level'] as num?)?.toInt() ?? 1,
      streak: (j['streak'] as num?)?.toInt() ?? 0,
      badges: [
        for (final b in (j['badges'] as List? ?? const []))
          if (b is Map) (b['name'] ?? '').toString(),
      ],
      xpBreakdown: breakdown,
    );
  }
}

/// Talks to `/workout-sessions/*`. Resume state is also kept on the phone, because a workout must survive an app
/// kill and a gym with no signal.
class SessionRepository {
  SessionRepository({required this._api, required this._cache});

  final ApiClient _api;
  final JsonCache _cache;

  static const _uuid = Uuid();

  /// Start (or resume) the workout of a weekday. The client uuid makes a retried start harmless.
  Future<WorkoutSession> start({int? weekday, DateTime? date}) async {
    final res = await _api.post(
      '/workout-sessions',
      idempotent: true,
      body: {
        'weekday': ?weekday,
        if (date != null) 'date': '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}',
        'client_uuid': _uuid.v4(),
      },
    );
    return WorkoutSession.fromJson(res.data);
  }

  Future<WorkoutSession> load(int id) async => WorkoutSession.fromJson((await _api.get('/workout-sessions/$id')).data);

  /// The unfinished workout, if the member has one.
  Future<WorkoutSession?> active() async {
    final res = await _api.get('/workout-sessions/active');
    return res.data == null ? null : WorkoutSession.fromJson(res.data);
  }

  /// The body of the offline queue's `workout.set.log` operation: the same fields the online endpoint takes.
  static Map<String, dynamic> setPayload(int sessionId, SetEntry entry) => {'session_id': sessionId, ...entry.toJson()};

  Future<SetResult> logSet(int sessionId, SetEntry entry) async {
    final res = await _api.post('/workout-sessions/$sessionId/sets', body: entry.toJson(), idempotent: true);
    final d = res.map;
    return SetResult(
      set: LoggedSet.fromJson(Map<String, dynamic>.from(d['set'] as Map)),
      isPr: d['is_pr'] == true,
      xpAwarded: (d['xp'] as num?)?.toInt() ?? 0,
      exerciseStatus: (d['exercise_status'] ?? 'pending').toString(),
    );
  }

  Future<void> deleteSet(int sessionId, int setId) async => _api.delete('/workout-sessions/$sessionId/sets/$setId');

  Future<void> skipExercise(int sessionId, int sessionExerciseId) async => _api.post('/workout-sessions/$sessionId/exercises/$sessionExerciseId/skip', idempotent: true);

  /// Resume convenience: failures are ignored because the phone keeps the same state locally.
  Future<void> saveState(int sessionId, Map<String, dynamic> state) async {
    try {
      await _api.put('/workout-sessions/$sessionId/state', body: {'state': state});
    } on ApiError {
      // Offline or the session ended elsewhere: the local copy is what matters.
    }
  }

  Future<void> finish(int sessionId, {required int activeSeconds}) async =>
      _api.post('/workout-sessions/$sessionId/finish', body: {'active_seconds': activeSeconds.clamp(0, 21600)}, idempotent: true);

  Future<void> abandon(int sessionId) async => _api.post('/workout-sessions/$sessionId/abandon', idempotent: true);

  Future<FinishedSummary> summary(int sessionId) async => FinishedSummary.fromJson((await _api.get('/workout-sessions/$sessionId/summary')).data);

  // ------------------------------------------------------------------------------------------ local copy

  String _key(int id) => 'session.$id.engine';

  EngineState? readLocal(int sessionId) => EngineState.fromJson(_cache.read(_key(sessionId))?.data is Map ? Map<String, dynamic>.from(_cache.read(_key(sessionId))!.data! as Map) : null);

  Future<void> writeLocal(int sessionId, EngineState state) => _cache.write(_key(sessionId), state.toLocalJson());

  Future<void> clearLocal(int sessionId) => _cache.remove(_key(sessionId));
}
