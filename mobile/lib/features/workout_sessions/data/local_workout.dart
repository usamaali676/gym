import 'package:shared_preferences/shared_preferences.dart';

/// The workout this phone is in the middle of, if any, found from the resume state kept on the phone
/// (`SessionRepository.writeLocal`; it is removed when a workout is finished or abandoned).
///
/// This is what lets a member keep training while the server is in maintenance or an update is waiting: their sets go to
/// the offline queue and are sent when the server is back.
int? localWorkoutInProgress(SharedPreferences prefs, String? scope) {
  if (scope == null) return null;
  final pattern = RegExp('^ip\\.cache\\.${RegExp.escape(scope)}\\.session\\.(\\d+)\\.engine\$');
  int? found;
  for (final key in prefs.getKeys()) {
    final m = pattern.firstMatch(key);
    if (m == null) continue;
    final id = int.parse(m.group(1)!);
    if (found == null || id > found) found = id;
  }
  return found;
}
