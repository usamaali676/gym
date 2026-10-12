import 'dart:convert';

import '../storage/secure_store.dart';
import 'session.dart';

/// Persists the [Session] in secure storage. A corrupt entry is treated as "signed out", never as a crash.
class TokenStore {
  TokenStore(this._store);

  final SecureStore _store;

  static const _sessionKey = 'ip.session';
  static const _userKey = 'ip.last_user';

  Future<Session?> read() async {
    final raw = await _store.read(_sessionKey);
    if (raw == null) return null;
    try {
      return Session.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      await _store.delete(_sessionKey);
      return null;
    }
  }

  Future<void> write(Session session) => _store.write(_sessionKey, jsonEncode(session.toJson()));

  /// The last known account JSON, so the app can open offline. Kept next to the token (it holds the member's email).
  Future<Map<String, dynamic>?> readUser() async {
    final raw = await _store.read(_userKey);
    if (raw == null) return null;
    try {
      return jsonDecode(raw) as Map<String, dynamic>;
    } catch (_) {
      return null;
    }
  }

  Future<void> writeUser(Map<String, dynamic> user) => _store.write(_userKey, jsonEncode(user));

  /// Signing out removes the credentials and the cached account (the installation id stays; it is not a secret).
  Future<void> clear() async {
    await _store.delete(_sessionKey);
    await _store.delete(_userKey);
  }
}
