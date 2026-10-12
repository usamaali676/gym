import '../../../core/api/api_client.dart';
import '../../../core/api/loaded.dart';
import '../../../core/storage/json_cache.dart';
import '../../workout_programs/domain/plan.dart' show dateParam;
import '../domain/progress.dart';

class ProgressRepository {
  ProgressRepository({required this._api, required this._cache});

  final ApiClient _api;
  final JsonCache _cache;

  Future<Loaded<ProgressSummary>> summary() => loadWithCache(cache: _cache, key: 'progress.summary', fetch: () async => (await _api.get('/progress/summary')).data, parse: ProgressSummary.fromJson);

  Future<Loaded<List<Measurement>>> measurements() => loadWithCache(
        cache: _cache,
        key: 'progress.measurements',
        fetch: () async => (await _api.get('/progress/measurements')).data,
        parse: (json) => [for (final m in json! as List) Measurement.fromJson(Map<String, dynamic>.from(m as Map))],
      );

  Future<Loaded<List<PersonalRecord>>> records() => loadWithCache(
        cache: _cache,
        key: 'progress.records',
        fetch: () async => (await _api.get('/progress/records')).data,
        parse: (json) => [for (final r in json! as List) PersonalRecord.fromJson(Map<String, dynamic>.from(r as Map))],
      );

  Future<Loaded<StrengthSeries>> strength(String slug) =>
      loadWithCache(cache: _cache, key: 'progress.strength.$slug', fetch: () async => (await _api.get('/progress/strength/$slug')).data, parse: StrengthSeries.fromJson);

  Future<Loaded<Achievements>> achievements() => loadWithCache(cache: _cache, key: 'achievements', fetch: () async => (await _api.get('/achievements')).data, parse: Achievements.fromJson);

  /// The body of `POST /progress/measurements` and of the offline queue's `measurement.save`.
  static Map<String, dynamic> measurementPayload({required DateTime date, double? weightKg, double? bodyFatPct, double? waistCm, String? notes}) =>
      {'measured_on': dateParam(date), 'weight_kg': ?weightKg, 'body_fat_pct': ?bodyFatPct, 'waist_cm': ?waistCm, 'notes': ?notes};

  /// Saves a body measurement. A second one for the same day replaces the first (the server decides).
  Future<void> saveMeasurement({required DateTime date, double? weightKg, double? bodyFatPct, double? waistCm, String? notes}) async =>
      _api.post('/progress/measurements', idempotent: true, body: measurementPayload(date: date, weightKg: weightKg, bodyFatPct: bodyFatPct, waistCm: waistCm, notes: notes));

  Future<void> deleteMeasurement(int id) async => _api.delete('/progress/measurements/$id');
}
