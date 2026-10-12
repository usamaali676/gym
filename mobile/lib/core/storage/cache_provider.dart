import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/authentication/presentation/auth_controller.dart';
import '../providers.dart';
import 'json_cache.dart';

/// The cache of the signed-in account (`anon` while nobody is signed in).
final jsonCacheProvider = Provider<JsonCache>((ref) {
  // Rebuilt only when the account changes (not on every refresh of the same account).
  final scope = ref.watch(authControllerProvider.select((a) => a is SignedIn ? '${a.user.id}' : JsonCache.anonymous));
  return JsonCache(ref.watch(sharedPreferencesProvider), scope: scope);
});
