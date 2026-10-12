import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_error.dart';
import '../../../core/authentication/session_manager.dart';
import '../../../core/providers.dart';
import '../data/auth_repository.dart';
import '../domain/auth_user.dart';

sealed class AuthState {
  const AuthState();
}

/// Reading the stored session / checking it with the server.
class AuthInitializing extends AuthState {
  const AuthInitializing({this.error});

  /// Set when the check failed offline and there is nothing cached: the splash screen offers "Try again".
  final ApiError? error;
}

class SignedOut extends AuthState {
  const SignedOut({this.notice});

  /// Why the member landed here when it was not their doing ("Your session ended...").
  final String? notice;
}

class SignedIn extends AuthState {
  const SignedIn(this.user, {this.offline = false});
  final AuthUser user;

  /// The account came from the cache because the server could not be reached.
  final bool offline;
}

final authRepositoryProvider = Provider<AuthRepository>((ref) => AuthRepository(
      api: ref.watch(apiClientProvider),
      sessions: ref.watch(sessionManagerProvider),
      tokens: ref.watch(tokenStoreProvider),
      device: ref.watch(deviceIdentityProvider),
    ));

class AuthController extends Notifier<AuthState> {
  StreamSubscription<SessionEnd>? _ends;

  @override
  AuthState build() {
    _ends = ref.watch(sessionManagerProvider).ends.listen((reason) {
      if (reason == SessionEnd.revoked) {
        state = const SignedOut(notice: 'Your session ended. Please sign in again.');
      } else {
        state = const SignedOut();
      }
    });
    ref.onDispose(() => _ends?.cancel());

    Future.microtask(restore);
    return const AuthInitializing();
  }

  AuthRepository get _repo => ref.read(authRepositoryProvider);

  /// Launch: use the stored token if there is one, and confirm it with the server.
  Future<void> restore() async {
    state = const AuthInitializing();
    final session = await ref.read(sessionManagerProvider).restore();
    if (!ref.mounted) return;
    if (session == null) {
      state = const SignedOut();
      return;
    }
    try {
      final user = await _repo.me();
      if (ref.mounted) state = SignedIn(user);
    } on ApiError catch (e) {
      if (!ref.mounted) return;
      if (e.kind == ApiErrorKind.unauthenticated) return; // the session ended; the stream already moved us to SignedOut
      if (e.isOffline) {
        final cached = await _repo.cachedUser();
        if (!ref.mounted) return;
        state = cached != null ? SignedIn(cached, offline: true) : AuthInitializing(error: e);
        return;
      }
      state = AuthInitializing(error: e); // server trouble: keep the token, let the member retry
    }
  }

  Future<void> login(String email, String password) async => state = SignedIn(await _repo.login(email: email, password: password));

  Future<void> register(String name, String email, String password) async =>
      state = SignedIn(await _repo.register(name: name, email: email, password: password));

  Future<void> continueAsGuest() async => state = SignedIn(await _repo.guest());

  Future<void> signOut() => _repo.logout();

  /// Re-reads the account (after onboarding, profile edits, or coming back online).
  Future<void> refreshUser() async {
    if (state is! SignedIn) return;
    try {
      final user = await _repo.me();
      if (ref.mounted) state = SignedIn(user);
    } on ApiError catch (e) {
      if (ref.mounted && e.isOffline && state is SignedIn) state = SignedIn((state as SignedIn).user, offline: true);
    }
  }

  Future<void> completeOnboarding(Map<String, dynamic> body) async => state = SignedIn(await _repo.completeOnboarding(body));
}

final authControllerProvider = NotifierProvider<AuthController, AuthState>(AuthController.new);
