import '../../../core/api/api_client.dart';
import '../../../core/api/loaded.dart';
import '../../../core/storage/json_cache.dart';
import '../domain/dashboard.dart';

class DashboardRepository {
  DashboardRepository({required this._api, required this._cache});

  final ApiClient _api;
  final JsonCache _cache;

  Future<Loaded<Dashboard>> load() => loadWithCache(
        cache: _cache,
        key: 'dashboard',
        fetch: () async => (await _api.get('/dashboard')).data,
        parse: Dashboard.fromJson,
      );
}
