/// What `GET /app/config` says: version policy for this build, maintenance, feature switches and the choices the
/// profile forms offer. Parsed leniently: unknown fields are ignored, missing ones fall back to safe defaults.
class AppConfig {
  const AppConfig({
    required this.versionStatus,
    required this.storeUrl,
    required this.latestVersion,
    required this.releaseNotes,
    required this.maintenance,
    required this.guestMode,
    required this.password,
    required this.paymentProviders,
    required this.pushEnabled,
    required this.coachEnabled,
    required this.profileOptions,
    required this.libraryOptions,
    required this.entitlementCatalog,
    required this.limitLabels,
    required this.settings,
    required this.serverTime,
  });

  final VersionStatus versionStatus;
  final String? storeUrl;
  final String? latestVersion;
  final String? releaseNotes;
  final Maintenance? maintenance;
  final bool guestMode;

  /// What a new password must contain (the server re-checks).
  final PasswordPolicy password;
  final List<String> paymentProviders;
  final bool pushEnabled;
  final bool coachEnabled;
  final Map<String, List<ProfileOption>> profileOptions;

  /// Filter choices of the exercise library (`muscle`, `difficulty`); equipment reuses [profileOptions].
  final Map<String, List<ProfileOption>> libraryOptions;

  /// What a membership can unlock, with the server's wording (key, label, description).
  final List<EntitlementInfo> entitlementCatalog;

  /// Labels for numeric plan limits, by key.
  final Map<String, String> limitLabels;

  /// Dashboard-managed switches and copy (`features.help_center`, `support.email`, ...).
  final Map<String, dynamic> settings;
  final DateTime? serverTime;

  bool setting(String key, {bool fallback = true}) {
    final v = settings[key];
    return v is bool ? v : fallback;
  }

  String? settingText(String key) {
    final v = settings[key];
    return v is String && v.isNotEmpty ? v : null;
  }

  List<ProfileOption> options(String name) => profileOptions[name] ?? const [];
  List<ProfileOption> libraryChoices(String name) => name == 'equipment' ? options('equipment') : (libraryOptions[name] ?? const []);

  factory AppConfig.fromJson(Map<String, dynamic> json) {
    final policy = json['version_policy'] is Map ? Map<String, dynamic>.from(json['version_policy'] as Map) : const <String, dynamic>{};
    final maintenance = json['maintenance'] is Map ? Map<String, dynamic>.from(json['maintenance'] as Map) : const <String, dynamic>{};
    final auth = json['auth'] is Map ? Map<String, dynamic>.from(json['auth'] as Map) : const <String, dynamic>{};
    final features = json['features'] is Map ? Map<String, dynamic>.from(json['features'] as Map) : const <String, dynamic>{};
    final rawOptions = json['profile_options'] is Map ? Map<String, dynamic>.from(json['profile_options'] as Map) : const <String, dynamic>{};
    final rawLibrary = json['library_options'] is Map ? Map<String, dynamic>.from(json['library_options'] as Map) : const <String, dynamic>{};
    Map<String, List<ProfileOption>> parseOptions(Map<String, dynamic> raw) => {
          for (final e in raw.entries)
            e.key: [
              for (final o in (e.value as List? ?? const []))
                if (o is Map) ProfileOption(o['value'], (o['label'] ?? o['value']).toString()),
            ],
        };

    return AppConfig(
      versionStatus: VersionStatus.parse(policy['status'] as String?),
      storeUrl: _text(policy['store_url']),
      latestVersion: _text(policy['latest']),
      releaseNotes: _text(policy['release_notes']),
      maintenance: maintenance['enabled'] == true
          ? Maintenance(
              message: _text(maintenance['message']) ?? 'IronPulse is down for maintenance. Please try again shortly.',
              endsAt: DateTime.tryParse((maintenance['ends_at'] ?? '').toString())?.toUtc(),
            )
          : null,
      guestMode: auth['guest_mode'] == true,
      password: PasswordPolicy.fromJson(auth['password']),
      paymentProviders: [for (final p in (features['payments'] as List? ?? const [])) p.toString()],
      pushEnabled: features['push'] == true,
      coachEnabled: features['coach'] == true,
      profileOptions: parseOptions(rawOptions),
      libraryOptions: parseOptions(rawLibrary),
      entitlementCatalog: [
        for (final e in (json['entitlements'] as List? ?? const []))
          if (e is Map) EntitlementInfo(e['key'].toString(), (e['label'] ?? e['key']).toString(), (e['description'] ?? '').toString()),
      ],
      limitLabels: {
        for (final l in (json['limits'] as List? ?? const []))
          if (l is Map) l['key'].toString(): (l['label'] ?? l['key']).toString(),
      },
      settings: json['settings'] is Map ? Map<String, dynamic>.from(json['settings'] as Map) : const {},
      serverTime: DateTime.tryParse((json['server_time'] ?? '').toString())?.toUtc(),
    );
  }

  static String? _text(Object? v) => v is String && v.isNotEmpty ? v : null;
}

enum VersionStatus {
  ok,
  updateRecommended,
  updateRequired;

  static VersionStatus parse(String? raw) => switch (raw) {
        'update_required' => VersionStatus.updateRequired,
        'update_recommended' => VersionStatus.updateRecommended,
        _ => VersionStatus.ok,
      };
}

class Maintenance {
  const Maintenance({required this.message, this.endsAt});
  final String message;
  final DateTime? endsAt;
}

class ProfileOption {
  const ProfileOption(this.value, this.label);

  /// String for enums (`cutting`), int for weekdays (1 = Monday).
  final Object? value;
  final String label;
}

class EntitlementInfo {
  const EntitlementInfo(this.key, this.label, this.description);
  final String key;
  final String label;
  final String description;
}

class PasswordPolicy {
  const PasswordPolicy({this.min = 8, this.letters = false, this.numbers = false});

  /// Used before the server's policy has been downloaded: only the length is checked client-side then.
  static const fallback = PasswordPolicy();

  final int min;
  final bool letters;
  final bool numbers;

  factory PasswordPolicy.fromJson(Object? json) {
    if (json is! Map) return fallback;
    return PasswordPolicy(min: (json['min'] as num?)?.toInt() ?? 8, letters: json['letters'] == true, numbers: json['numbers'] == true);
  }

  /// "At least 8 characters, with letters and numbers."
  String get hint {
    final parts = [if (letters) 'letters', if (numbers) 'numbers'];
    return 'At least $min characters${parts.isEmpty ? '' : ', with ${parts.join(' and ')}'}.';
  }

  String? check(String value) {
    if (value.length < min) return 'Use at least $min characters.';
    if (letters && !RegExp(r'\p{L}', unicode: true).hasMatch(value)) return 'Add at least one letter.';
    if (numbers && !RegExp(r'\p{N}', unicode: true).hasMatch(value)) return 'Add at least one number.';
    return null;
  }
}
