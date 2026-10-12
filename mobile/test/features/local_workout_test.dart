import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/features/workout_sessions/data/local_workout.dart';
import 'package:shared_preferences/shared_preferences.dart';

Future<SharedPreferences> prefsWith(Map<String, Object> values) async {
  SharedPreferences.setMockInitialValues(values);
  return SharedPreferences.getInstance();
}

void main() {
  test('finds the workout this account has in progress on the phone', () async {
    final prefs = await prefsWith({'ip.cache.42.session.7.engine': '{}', 'ip.cache.42.session.7.data': '{}'});
    expect(localWorkoutInProgress(prefs, '42'), 7);
  });

  test('another account\'s workout is not found, and neither is anything while signed out', () async {
    final prefs = await prefsWith({'ip.cache.43.session.9.engine': '{}'});
    expect(localWorkoutInProgress(prefs, '42'), isNull);
    expect(localWorkoutInProgress(prefs, null), isNull);
    expect(localWorkoutInProgress(prefs, '4'), isNull, reason: 'account 4 is not account 43');
  });

  test('a finished workout (its resume state was removed) is not in progress', () async {
    final prefs = await prefsWith({'ip.cache.42.session.7.data': '{}'});
    expect(localWorkoutInProgress(prefs, '42'), isNull);
  });

  test('with more than one, the newest is the one in progress', () async {
    final prefs = await prefsWith({'ip.cache.42.session.7.engine': '{}', 'ip.cache.42.session.12.engine': '{}'});
    expect(localWorkoutInProgress(prefs, '42'), 12);
  });
}
