import '../../../core/api/api_client.dart';
import '../../../core/api/loaded.dart';
import '../../../core/storage/json_cache.dart';
import '../domain/membership.dart';

class MembershipRepository {
  MembershipRepository({required this._api, required this._cache});

  final ApiClient _api;
  final JsonCache _cache;

  Future<Loaded<Membership>> membership() => loadWithCache(
        cache: _cache,
        key: 'membership',
        fetch: () async => (await _api.get('/membership')).data,
        parse: Membership.fromJson,
      );

  /// Published plans (public endpoint; same list the website shows).
  Future<Loaded<List<PlanInfo>>> plans() => loadWithCache(
        cache: _cache,
        key: 'membership.plans',
        fetch: () async => (await _api.get('/membership/plans', auth: false)).data,
        parse: (json) => [for (final p in json! as List) PlanInfo.fromJson(Map<String, dynamic>.from(p as Map))],
      );
}
