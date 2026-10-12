import '../../../core/api/api_client.dart';
import '../../../core/api/api_error.dart';
import '../../../core/authentication/session.dart';
import '../../../core/authentication/session_manager.dart';
import '../../../core/authentication/token_store.dart';
import '../../../core/device/device_identity.dart';
import '../domain/auth_user.dart';

/// Talks to `/auth/*` and `/me`. Holds no state: the session lives in [SessionManager].
class AuthRepository {
  AuthRepository({
    required this._api,
    required this._sessions,
    required this._tokens,
    required this._device,
  });

  final ApiClient _api;
  final SessionManager _sessions;
  final TokenStore _tokens;
  final DeviceIdentity _device;

  Future<AuthUser> login({required String email, required String password}) async =>
      _authenticate('/auth/login', {'email': email.trim(), 'password': password});

  Future<AuthUser> register({required String name, required String email, required String password}) async =>
      _authenticate('/auth/register', {'name': name.trim(), 'email': email.trim(), 'password': password});

  Future<AuthUser> guest() async => _authenticate('/auth/guest', {});

  Future<AuthUser> _authenticate(String path, Map<String, dynamic> body) async {
    final response = await _api.post(path, auth: false, body: {...body, 'device': await _device.payload()});
    final data = response.map;
    await _sessions.start(Session.fromApi(Map<String, dynamic>.from(data['token'] as Map), deviceId: (data['device_id'] as num?)?.toInt()));
    final user = AuthUser.fromJson(Map<String, dynamic>.from(data['user'] as Map));
    await _tokens.writeUser(user.raw);
    return user;
  }

  Future<AuthUser> me() async {
    final user = AuthUser.fromJson((await _api.get('/me')).map);
    await _tokens.writeUser(user.raw);
    return user;
  }

  Future<AuthUser?> cachedUser() async {
    final raw = await _tokens.readUser();
    return raw == null ? null : AuthUser.fromJson(raw);
  }

  /// Revokes this install's token on the server (best effort: being offline must not trap anyone in the app) and
  /// then forgets it locally.
  Future<void> logout() async {
    try {
      await _api.post('/auth/logout');
    } on ApiError {
      // Offline or already revoked: the local sign-out below is what matters.
    }
    await _sessions.end(SessionEnd.signedOut);
  }

  /// Permanently deletes the account and everything it owns on the server (`DELETE /me`). A registered member proves
  /// who they are with their password; a guest types DELETE. Throws [ApiError]: nothing is deleted unless this returns.
  Future<void> deleteAccount({String? password, required bool guest}) async {
    await _api.delete('/me', body: guest ? {'confirm': 'DELETE'} : {'password': password});
  }

  /// Forget the session on this phone after the server has deleted the account (its token no longer exists).
  Future<void> forgetDeletedAccount() => _sessions.end(SessionEnd.accountDeleted);

  Future<void> forgotPassword(String email) async {
    await _api.post('/auth/forgot-password', auth: false, body: {'email': email.trim()});
  }

  /// First-run wizard. Same rules as the website; the server answers with the updated account.
  Future<AuthUser> completeOnboarding(Map<String, dynamic> body) async {
    final user = AuthUser.fromJson((await _api.post('/me/onboarding', body: body)).map);
    await _tokens.writeUser(user.raw);
    return user;
  }
}
