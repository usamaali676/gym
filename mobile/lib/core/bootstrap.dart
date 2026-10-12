import 'package:shared_preferences/shared_preferences.dart';

import 'storage/secure_store.dart';

/// iOS keeps Keychain items after the app is deleted, so a reinstall could resurrect an old token. Preferences are
/// removed with the app: if this flag is missing, nothing in the keychain belongs to this install and it is wiped.
Future<void> wipeStaleSecretsOnFreshInstall(SharedPreferences prefs, SecureStore store) async {
  const flag = 'ip.installed';
  if (prefs.getBool(flag) == true) return;
  await store.deleteAll();
  await prefs.setBool(flag, true);
}
