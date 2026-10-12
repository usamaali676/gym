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
import '../features/exercise_library/presentation/exercise_detail_screen.dart';
import '../features/exercise_library/presentation/library_screen.dart';
import '../features/home/presentation/account_screen.dart';
import '../features/home/presentation/app_shell.dart';
import '../features/membership/presentation/membership_screen.dart';
import '../features/nutrition/presentation/food_amount_screen.dart';
import '../features/nutrition/presentation/food_search_screen.dart';
import '../features/nutrition/presentation/nutrition_screen.dart';
import '../features/onboarding/presentation/onboarding_screen.dart';
import '../features/workout_programs/presentation/day_detail_screen.dart';
import '../features/workout_sessions/presentation/workout_screen.dart';
import '../features/workout_sessions/presentation/workout_summary_screen.dart';
import '../features/workout_programs/presentation/programme_detail_screen.dart';
import '../features/workout_programs/presentation/train_screen.dart';

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
  static const train = '/train';
  static const trainDay = '/train/day';
  static const programme = '/train/programme';
  static const library = '/library';
  static const workout = '/workout';
  static const fuel = '/fuel';
  static const foodSearch = '/fuel/search';
  static const foodAmount = '/fuel/food';
  static const exercise = '/library/exercise';

  /// Pages a signed-out visitor may see.
  static const signedOutPages = {welcome, login, register, forgotPassword};

  /// The signed-in app. Feature routes added later live under one of these prefixes.
  static const signedInAreas = [home, train, fuel, library, account, membership, workout];

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
      GoRoute(
        path: '${Routes.trainDay}/:weekday',
        builder: (_, state) => DayDetailScreen(weekday: int.tryParse(state.pathParameters['weekday'] ?? '') ?? 1, date: state.uri.queryParameters['date']),
      ),
      GoRoute(
        path: Routes.foodSearch,
        builder: (_, state) => FoodSearchScreen(meal: state.uri.queryParameters['meal'], date: state.uri.queryParameters['date']),
      ),
      GoRoute(
        path: '${Routes.foodAmount}/:id',
        builder: (_, state) {
          final q = state.uri.queryParameters;
          return FoodAmountScreen(
            foodId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
            meal: q['meal'],
            date: q['date'],
            logId: int.tryParse(q['log'] ?? ''),
            quantity: double.tryParse(q['q'] ?? ''),
            unit: q['unit'],
          );
        },
      ),
      GoRoute(path: '${Routes.workout}/:id', builder: (_, state) => WorkoutScreen(sessionId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0)),
      GoRoute(path: '${Routes.workout}/:id/summary', builder: (_, state) => WorkoutSummaryScreen(sessionId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0)),
      GoRoute(path: '${Routes.exercise}/:slug', builder: (_, state) => ExerciseDetailScreen(slug: state.pathParameters['slug'] ?? '')),
      GoRoute(path: '${Routes.programme}/:id', builder: (_, state) => ProgrammeDetailScreen(programmeId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0)),
      StatefulShellRoute.indexedStack(
        builder: (_, _, shell) => AppShell(shell: shell),
        branches: [
          StatefulShellBranch(routes: [GoRoute(path: Routes.home, builder: (_, _) => const DashboardScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: Routes.train, builder: (_, _) => const TrainScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: Routes.fuel, builder: (_, _) => const NutritionScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: Routes.library, builder: (_, _) => const LibraryScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: Routes.account, builder: (_, _) => const AccountScreen())]),
        ],
      ),
    ],
  );
});
