import '../../../core/api/api_client.dart';
import '../../../core/api/loaded.dart';
import '../../../core/storage/json_cache.dart';
import '../domain/exercise.dart';

class ExerciseRepository {
  ExerciseRepository({required this._api, required this._cache});

  final ApiClient _api;
  final JsonCache _cache;

  /// One page of the catalogue. Only the first page of each filter combination is kept for offline use.
  Future<Loaded<ExercisePage>> page(LibraryFilters filters, int page) async {
    Future<Object?> fetch() async {
      final res = await _api.get('/exercises', query: filters.toQuery(page));
      return {'data': res.data, 'meta': res.meta};
    }

    if (page > 1) {
      return Loaded(ExercisePage.fromJson(await fetch()));
    }
    return loadWithCache(cache: _cache, key: 'exercises.${filters.key}.1', fetch: fetch, parse: ExercisePage.fromJson);
  }

  Future<Loaded<Exercise>> exercise(String slug) => loadWithCache(
        cache: _cache,
        key: 'exercise.$slug',
        fetch: () async => (await _api.get('/exercises/$slug')).data,
        parse: (json) => Exercise.fromJson(Map<String, dynamic>.from(json! as Map)),
      );
}
