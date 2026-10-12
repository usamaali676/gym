import 'dart:io' show Platform;
import 'dart:ui' as ui;

import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter_timezone/flutter_timezone.dart';
import 'package:uuid/uuid.dart';

import '../../app/config/environment.dart';
import '../storage/secure_store.dart';

/// Describes this installation for `device` in auth requests. The installation id is random, generated once and kept
/// in secure storage; the server uses it to give each phone its own token and to push to the right device.
class DeviceIdentity {
  DeviceIdentity({required this._store, required this._env});

  final SecureStore _store;
  final AppEnvironment _env;

  static const _key = 'ip.installation_id';

  Future<String> installationId() async {
    final existing = await _store.read(_key);
    if (existing != null && RegExp(r'^[A-Za-z0-9-]{16,64}$').hasMatch(existing)) return existing;
    final id = const Uuid().v4();
    await _store.write(_key, id);
    return id;
  }

  Future<Map<String, dynamic>> payload() async {
    final name = await _deviceName();
    return {
      'installation_id': await installationId(),
      'platform': _env.platform,
      'name': name,
      'app_version': _env.appVersion,
      'app_build': _env.appBuild,
      'os_version': await _osVersion(),
      'locale': ui.PlatformDispatcher.instance.locale.toLanguageTag(),
      'timezone': await _timezone(),
    };
  }

  /// IANA zone ("Europe/London"): the server needs it for quiet hours and day boundaries.
  Future<String> _timezone() async {
    try {
      return (await FlutterTimezone.getLocalTimezone()).identifier;
    } catch (_) {
      return 'UTC';
    }
  }

  Future<String> _deviceName() async {
    try {
      final plugin = DeviceInfoPlugin();
      if (Platform.isAndroid) {
        final a = await plugin.androidInfo;
        return '${a.manufacturer} ${a.model}'.trim();
      }
      if (Platform.isIOS) {
        final i = await plugin.iosInfo;
        return i.utsname.machine;
      }
    } catch (_) {}
    return 'Unknown device';
  }

  Future<String> _osVersion() async {
    try {
      final plugin = DeviceInfoPlugin();
      if (Platform.isAndroid) return (await plugin.androidInfo).version.release;
      if (Platform.isIOS) return (await plugin.iosInfo).systemVersion;
    } catch (_) {}
    return '';
  }
}
