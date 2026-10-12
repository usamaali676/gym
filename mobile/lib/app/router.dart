import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/policy/policy_controller.dart';
import '../features/app_gate/presentation/gate_screens.dart';
import '../features/authentication/presentation/auth_controller.dart';
import '../features/dashboard/presentation/dashboard_screen.dart';
import '../features/authentication/presentation/forgot_password_screen.dart';
import '../features/authentication/presentation/login_screen.dart';
import '../features/authentication/presentation/register_screen.dart';
import '../features/authentication/presentation/welcome_screen.dart';
import '../features/home/presentation/account_screen.dart';
import '../features/home/presentation/app_shell.dart';
import '../features/membership/presentation/membership_screen.dart';
import '../features/onboarding/presentation/onboarding_screen.dart';

abstract final class Routes {
  static const splash = '/splash';
  static const welcome = '/welcome';
  static const login = '/login';
  static const register = '/register';
  static const forgotPassword = '/forgot-password';
  static const onboarding = '/onboarding';
  static const maintenance = '/maintenance';
  static const updateRequired = '/update-required';
  static const home = '/home';
  static const account = '/account';
  static const membership = '/membership';

  /// Pages a signed-out visitor may see.
  static const signedOutPages = {welcome, login, register, forgotPassword};

  /// The signed-in app. Feature routes added later live under one of these prefixes.
  static const signedInAreas = [home, account, membership];

  static bool inSignedInArea(String location) => signedInAreas.any(location.startsWith);
}

/// Decides where the member belongs right now. Pure so it can be tested without a widget tree.
/// Order matters: a build the server no longer supports, or maintenance, overrides everything else.
String? appRedirect({required AuthState auth, required PolicyState policy, required String location}) {
  if (policy.maintenance != null) return location == Routes.maintenance ? null : Routes.maintenance;
  if (policy.updateRequired) return location == Routes.updateRequired ? null : Routes.updateRequired;

  return switch (auth) {
    AuthInitializing() => location == Routes.splash ? null : Routes.splash,
    SignedOut() => Routes.signedOutPages.contains(location) ? null : Routes.welcome,
    SignedIn(:final user) when !user.onboarded => location == Routes.onboarding ? null : Routes.onboarding,
    SignedIn() => Routes.inSignedInArea(location) ? null : Routes.home,
  };
}

/// Re-runs the redirect whenever sign-in or policy state changes.
class _RouterRefresh extends ChangeNotifier {
  _RouterRefresh(Ref ref) {
    ref.listen(authControllerProvider, (_, _) => notifyListeners());
    ref.listen(policyControllerProvider, (_, _) => notifyListeners());
  }
}

final routerProvider = Provider<GoRouter>((ref) {
  final refresh = _RouterRefresh(ref);
  ref.onDispose(refresh.dispose);

  return GoRouter(
    initialLocation: Routes.splash,
    refreshListenable: refresh,
    redirect: (context, state) => appRedirect(
      auth: ref.read(authControllerProvider),
      policy: ref.read(policyControllerProvider),
      location: state.uri.path,
    ),
    routes: [
      GoRoute(path: Routes.splash, builder: (_, _) => const SplashScreen()),
      GoRoute(path: Routes.maintenance, builder: (_, _) => const MaintenanceScreen()),
      GoRoute(path: Routes.updateRequired, builder: (_, _) => const UpdateRequiredScreen()),
      GoRoute(path: Routes.welcome, builder: (_, _) => const WelcomeScreen()),
      GoRoute(path: Routes.login, builder: (_, _) => const LoginScreen()),
      GoRoute(path: Routes.register, builder: (_, _) => const RegisterScreen()),
      GoRoute(path: Routes.forgotPassword, builder: (_, _) => const ForgotPasswordScreen()),
      GoRoute(path: Routes.onboarding, builder: (_, _) => const OnboardingScreen()),
      GoRoute(path: Routes.membership, builder: (_, _) => const MembershipScreen()),
      StatefulShellRoute.indexedStack(
        builder: (_, _, shell) => AppShell(shell: shell),
        branches: [
          StatefulShellBranch(routes: [GoRoute(path: Routes.home, builder: (_, _) => const DashboardScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: Routes.account, builder: (_, _) => const AccountScreen())]),
        ],
      ),
    ],
  );
});
