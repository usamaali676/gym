import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';

/// Where the app talks to and which build this is. Nothing secret lives here: the API base URL is public, and
/// the apps carry no keys (store/Firebase configuration is added by the build, never an API secret).
class AppEnvironment {
  const AppEnvironment({
    required this.apiBaseUrl,
    required this.platform,
    required this.appVersion,
    required this.appBuild,
  });

  /// Root of the Laravel site, e.g. `https://gym.example.com` (no `/api/v1`, no trailing slash).
  final String apiBaseUrl;

  /// `android` or `ios`, sent as `X-App-Platform`.
  final String platform;

  /// Semantic version from pubspec, sent as `X-App-Version`.
  final String appVersion;
  final String appBuild;

  String get apiRoot => '$apiBaseUrl/api/v1';

  /// Release builds must be given the server: `flutter build apk --dart-define=API_BASE_URL=https://...`.
  bool get isConfigured => Uri.tryParse(apiBaseUrl)?.hasAuthority ?? false;

  /// Plain `http://` is accepted only for local development; a release build refuses it (the token would travel in
  /// clear text). Android's cleartext policy also blocks it unless the debug manifest allows it.
  bool get isSecureEnough => apiBaseUrl.startsWith('https://') || !kReleaseMode;

  static const _definedBaseUrl = String.fromEnvironment('API_BASE_URL');

  /// Default for local development: the Android emulator reaches the host machine at 10.0.2.2.
  static String defaultBaseUrl({required bool isAndroid}) {
    if (_definedBaseUrl.isNotEmpty) return _definedBaseUrl.replaceFirst(RegExp(r'/+$'), '');
    if (kReleaseMode) return '';
    return isAndroid ? 'http://10.0.2.2:8000' : 'http://localhost:8000';
  }

  static Future<AppEnvironment> load() async {
    final info = await PackageInfo.fromPlatform();
    final isAndroid = Platform.isAndroid;
    return AppEnvironment(
      apiBaseUrl: defaultBaseUrl(isAndroid: isAndroid),
      platform: isAndroid ? 'android' : 'ios',
      appVersion: info.version,
      appBuild: info.buildNumber,
    );
  }
}

/// Overridden in `main()` once the package info has been read.
final appEnvironmentProvider = Provider<AppEnvironment>((ref) => throw UnimplementedError('appEnvironmentProvider must be overridden'));
