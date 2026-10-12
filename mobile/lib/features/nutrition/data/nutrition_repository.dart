import '../../../core/api/api_client.dart';
import '../../../core/api/api_error.dart';
import '../../../core/api/loaded.dart';
import '../../../core/storage/json_cache.dart';
import '../../workout_programs/domain/plan.dart' show dateParam;
import '../domain/nutrition.dart';

class NutritionRepository {
  NutritionRepository({required this._api, required this._cache});

  final ApiClient _api;
  final JsonCache _cache;

  /// One day: totals, targets, what is left and the meals eaten. `null` is the member's own "today".
  Future<Loaded<NutritionDay>> day([DateTime? date]) => loadWithCache(
        cache: _cache,
        key: 'nutrition.day.${date == null ? 'now' : dateParam(date)}',
        fetch: () async => (await _api.get('/nutrition/day', query: {'date': ?(date == null ? null : dateParam(date))})).data,
        parse: NutritionDay.fromJson,
      );

  Future<List<Food>> search(String query) => readServer(() async {
    final res = await _api.get('/foods/search', query: {'q': query.trim()});
    return [for (final f in res.list) Food.fromJson(Map<String, dynamic>.from(f as Map))];
  });

  Future<List<Food>> recent() => readServer(() async => [for (final f in (await _api.get('/foods/recent')).list) Food.fromJson(Map<String, dynamic>.from(f as Map))]);

  Future<List<Food>> favorites() => readServer(() async => [for (final f in (await _api.get('/foods/favorites')).list) Food.fromJson(Map<String, dynamic>.from(f as Map))]);

  Future<Food> food(int id) => readServer(() async => Food.fromJson((await _api.get('/foods/$id')).map));

  Future<void> setFavorite(int foodId, bool favorite) async {
    if (favorite) {
      await _api.put('/foods/$foodId/favorite');
    } else {
      await _api.delete('/foods/$foodId/favorite');
    }
  }

  /// What [quantity] of a food is worth, computed by the server (nothing is saved).
  Future<Calculation> calculate({required int foodId, required double quantity, required String unit, int? servingId}) => readServer(() async {
    final res = await _api.post('/nutrition/calculate', body: {'food_id': foodId, 'quantity': quantity, 'unit': unit, 'serving_id': ?servingId});
    return Calculation.fromJson(res.data);
  });

  Future<FoodLogEntry> log({required int foodId, required String meal, required DateTime date, required double quantity, required String unit, int? servingId}) => readServer(() async {
    final res = await _api.post(
      '/food-logs',
      idempotent: true,
      body: {'food_id': foodId, 'meal': meal, 'logged_on': dateParam(date), 'quantity': quantity, 'unit': unit, 'serving_id': ?servingId},
    );
    return FoodLogEntry.fromJson(Map<String, dynamic>.from(res.map['log'] as Map));
  });

  Future<void> updateLog(int id, {required double quantity, required String unit, int? servingId, String? meal}) async =>
      _api.patch('/food-logs/$id', body: {'quantity': quantity, 'unit': unit, 'serving_id': ?servingId, 'meal': ?meal});

  Future<void> deleteLog(int id) async => _api.delete('/food-logs/$id');
}
