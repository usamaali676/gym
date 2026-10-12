/// The signed-in member, as `/me` and the auth endpoints return it.
class AuthUser {
  const AuthUser({
    required this.id,
    required this.name,
    required this.email,
    required this.isGuest,
    required this.isAdmin,
    required this.onboarded,
    required this.level,
    required this.xpTotal,
    required this.workoutStreak,
    required this.longestWorkoutStreak,
    required this.raw,
  });

  final int id;
  final String name;
  final String? email;
  final bool isGuest;
  final bool isAdmin;
  final bool onboarded;
  final int level;
  final int xpTotal;
  final int workoutStreak;
  final int longestWorkoutStreak;

  /// The JSON this was built from, kept for the offline cache.
  final Map<String, dynamic> raw;

  factory AuthUser.fromJson(Map<String, dynamic> json) => AuthUser(
        id: (json['id'] as num).toInt(),
        name: (json['name'] ?? '').toString(),
        email: json['email'] as String?,
        isGuest: json['is_guest'] == true,
        isAdmin: json['role'] == 'admin',
        onboarded: json['onboarded'] == true,
        level: (json['level'] as num?)?.toInt() ?? 1,
        xpTotal: (json['xp_total'] as num?)?.toInt() ?? 0,
        workoutStreak: (json['workout_streak'] as num?)?.toInt() ?? 0,
        longestWorkoutStreak: (json['longest_workout_streak'] as num?)?.toInt() ?? 0,
        raw: json,
      );
}
