import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

class CacheEntry {
  const CacheEntry(this.data, this.savedAt);
  final Object? data;
  final DateTime savedAt;
}

/// Small on-device copy of server answers so screens still open offline. Everything here is the member's private
/// data: keys are scoped to the signed-in account, and [clearAll] runs on every sign-out.
class JsonCache {
  JsonCache(this._prefs, {required this.scope}) : _epoch = _prefs.getInt(_epochKey) ?? 0;

  static const _prefix = 'ip.cache.';
  static const _epochKey = 'ip.cache_epoch';
  static const anonymous = 'anon';
  final SharedPreferences _prefs;

  /// Bumped by [clearAll]. A write from an instance created before the purge (a request that was still in flight
  /// when the member signed out) sees a newer epoch and is dropped, so nothing private reappears after sign-out.
  final int _epoch;

  /// The account id (or `anon`). A different account never reads this account's entries.
  final String scope;

  String _key(String key) => '$_prefix$scope.$key';

  CacheEntry? read(String key) {
    final raw = _prefs.getString(_key(key));
    if (raw == null) return null;
    try {
      final json = jsonDecode(raw) as Map<String, dynamic>;
      return CacheEntry(json['data'], DateTime.parse(json['saved_at'] as String));
    } catch (_) {
      return null;
    }
  }

  Future<void> write(String key, Object? data, {DateTime? now}) async {
    // Nothing is stored while signed out, and nothing after a purge by an older instance.
    if (scope == anonymous || (_prefs.getInt(_epochKey) ?? 0) != _epoch) return;
    await _prefs.setString(_key(key), jsonEncode({'saved_at': (now ?? DateTime.now()).toUtc().toIso8601String(), 'data': data}));
  }

  Future<void> remove(String key) => _prefs.remove(_key(key));

  /// Removes every cached answer of every account on this phone.
  static Future<void> clearAll(SharedPreferences prefs) async {
    await prefs.setInt(_epochKey, (prefs.getInt(_epochKey) ?? 0) + 1);
    for (final key in prefs.getKeys().where((k) => k.startsWith(_prefix)).toList()) {
      await prefs.remove(key);
    }
  }
}
