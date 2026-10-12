import 'dart:async';

import 'package:dio/dio.dart';

import '../api/api_client.dart';
import 'session.dart';
import 'token_store.dart';

enum SessionEnd {
  /// The server said the token is invalid (revoked from another device, expired, account removed).
  revoked,

  /// The member signed out on purpose.
  signedOut,

  /// The member deleted their account (in the app, which both app stores require).
  accountDeleted,
}

/// Owns the current [Session]: restores it at launch, hands the token to [ApiClient], rotates it before it runs out
/// (one refresh at a time, however many requests ask) and ends it when the server revokes it.
class SessionManager implements AuthTokenSource {
  SessionManager({required this._store, required Dio refreshDio, DateTime Function()? clock})
      : _dio = refreshDio,
        _now = clock ?? DateTime.now;

  final TokenStore _store;
  final Dio _dio;
  final DateTime Function() _now;

  Session? _session;
  Future<Session?>? _refreshing;
  DateTime? _lastRefreshFailure;

  final _ends = StreamController<SessionEnd>.broadcast();
  Stream<SessionEnd> get ends => _ends.stream;

  Session? get current => _session;
  bool get hasSession => _session != null;

  /// Loads the stored session. An expired one is discarded.
  Future<Session?> restore() async {
    final stored = await _store.read();
    if (stored != null && stored.isExpired(_now().toUtc())) {
      await _store.clear();
      _session = null;
      return null;
    }
    return _session = stored;
  }

  Future<void> start(Session session) async {
    _session = session;
    await _store.write(session);
  }

  /// Forget the credentials locally. The server call to revoke the token is the caller's job (and best effort).
  Future<void> end(SessionEnd reason) async {
    final had = _session != null;
    _session = null;
    await _store.clear();
    if (had) _ends.add(reason);
  }

  @override
  Future<String?> accessToken() async {
    final session = _session;
    if (session == null) return null;

    final now = _now().toUtc();
    if (session.isExpired(now)) {
      await end(SessionEnd.revoked);
      return null;
    }
    if (session.needsRefresh(now) && _canTryRefresh(now)) {
      final refreshed = await (_refreshing ??= _refresh(session).whenComplete(() => _refreshing = null));
      return (refreshed ?? _session)?.accessToken;
    }
    return session.accessToken;
  }

  /// After a failed refresh (offline) wait a few minutes before trying again instead of on every request.
  bool _canTryRefresh(DateTime now) {
    final failed = _lastRefreshFailure;
    return failed == null || now.difference(failed) > const Duration(minutes: 5);
  }

  Future<Session?> _refresh(Session old) async {
    try {
      final response = await _dio.post<dynamic>(
        '/auth/refresh',
        options: Options(headers: {'Authorization': 'Bearer ${old.accessToken}'}),
      );
      final status = response.statusCode ?? 0;
      if (status == 401) {
        await end(SessionEnd.revoked);
        return null;
      }
      if (status < 200 || status >= 300) {
        _lastRefreshFailure = _now().toUtc(); // keep using the still-valid token
        return null;
      }
      final data = (response.data as Map)['data'] as Map;
      final fresh = Session.fromApi(Map<String, dynamic>.from(data['token'] as Map), deviceId: (data['device_id'] as int?) ?? old.deviceId);
      await start(fresh);
      _lastRefreshFailure = null;
      return fresh;
    } on DioException {
      // Offline or timed out: the old token still works until it expires. Try again later.
      _lastRefreshFailure = _now().toUtc();
      return null;
    }
  }

  @override
  Future<String?> onUnauthorized(String usedToken) async {
    final session = _session;
    if (session == null) return null;
    if (session.accessToken != usedToken) return session.accessToken; // already rotated by someone else
    await end(SessionEnd.revoked);
    return null;
  }

  void dispose() => _ends.close();
}
