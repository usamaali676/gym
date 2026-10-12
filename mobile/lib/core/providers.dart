import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../app/config/environment.dart';
import 'api/api_client.dart';
import 'authentication/session_manager.dart';
import 'authentication/token_store.dart';
import 'device/device_identity.dart';
import 'storage/secure_store.dart';

/// Overridden in `main()` (and in tests).
final sharedPreferencesProvider = Provider<SharedPreferences>((ref) => throw UnimplementedError('sharedPreferencesProvider must be overridden'));

final secureStoreProvider = Provider<SecureStore>((ref) => FlutterSecureStore());

final tokenStoreProvider = Provider<TokenStore>((ref) => TokenStore(ref.watch(secureStoreProvider)));

final deviceIdentityProvider = Provider<DeviceIdentity>(
  (ref) => DeviceIdentity(store: ref.watch(secureStoreProvider), env: ref.watch(appEnvironmentProvider)),
);

final sessionManagerProvider = Provider<SessionManager>((ref) {
  final manager = SessionManager(
    store: ref.watch(tokenStoreProvider),
    refreshDio: buildDio(ref.watch(appEnvironmentProvider)),
  );
  ref.onDispose(manager.dispose);
  return manager;
});

final apiClientProvider = Provider<ApiClient>((ref) {
  final client = ApiClient(env: ref.watch(appEnvironmentProvider), tokens: ref.watch(sessionManagerProvider));
  ref.onDispose(client.dispose);
  return client;
});
