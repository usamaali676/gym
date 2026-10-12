import '../../../core/api/api_client.dart';
import '../../../core/api/loaded.dart';
import '../../../core/storage/json_cache.dart';
import '../domain/plan.dart';
import '../domain/programme.dart';

class PlanRepository {
  PlanRepository({required this._api, required this._cache});

  final ApiClient _api;
  final JsonCache _cache;

  /// The week containing [anchor] (the current week when null).
  Future<Loaded<WeekPlan>> week({DateTime? anchor}) => loadWithCache(
        cache: _cache,
        key: 'plan.week.${anchor == null ? 'now' : dateParam(anchor)}',
        fetch: () async => (await _api.get('/plan/week', query: {'date': ?(anchor == null ? null : dateParam(anchor))})).data,
        parse: WeekPlan.fromJson,
      );

  Future<Loaded<DayDetail>> day(int weekday, {DateTime? date}) => loadWithCache(
        cache: _cache,
        key: 'plan.day.$weekday.${date == null ? 'now' : dateParam(date)}',
        fetch: () async => (await _api.get('/plan/days/$weekday', query: {'date': ?(date == null ? null : dateParam(date))})).data,
        parse: DayDetail.fromJson,
      );

  Future<void> skipDay(int weekday, DateTime date) async => _api.post('/plan/days/$weekday/skip', body: {'date': dateParam(date)}, idempotent: true);

  Future<void> unskipDay(int weekday, DateTime date) async => _api.delete('/plan/days/$weekday/skip', body: {'date': dateParam(date)});

  Future<Loaded<List<Programme>>> programmes() => loadWithCache(
        cache: _cache,
        key: 'programmes',
        fetch: () async => (await _api.get('/programs')).data,
        parse: (json) => [for (final p in json! as List) Programme.fromJson(Map<String, dynamic>.from(p as Map))],
      );

  Future<Loaded<ProgrammeDetail>> programme(int id) => loadWithCache(
        cache: _cache,
        key: 'programme.$id',
        fetch: () async => (await _api.get('/programs/$id')).data,
        parse: ProgrammeDetail.fromJson,
      );

  /// Make [id] the member's current programme. Past workouts stay attached to what was actually done.
  Future<void> selectProgramme(int id) async => _api.post('/me/program', body: {'program_id': id}, idempotent: true);
}
