import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/policy/policy_controller.dart';
import '../core/sync/offline_queue.dart';
import '../core/sync/pending_op.dart';
import '../core/widgets/app_background.dart';
import '../features/authentication/presentation/auth_controller.dart';
import '../features/dashboard/presentation/dashboard_providers.dart';
import '../features/progress/presentation/progress_providers.dart';
import '../features/workout_programs/presentation/plan_providers.dart';
import '../features/workout_sessions/presentation/workout_controller.dart';
import 'router.dart';
import 'theme/app_theme.dart';

class IronPulseApp extends ConsumerStatefulWidget {
  const IronPulseApp({super.key});

  @override
  ConsumerState<IronPulseApp> createState() => _IronPulseAppState();
}

class _IronPulseAppState extends ConsumerState<IronPulseApp> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    // Ask the server whether this build may run, before anything else is shown to a signed-out visitor.
    Future.microtask(() => ref.read(policyControllerProvider.notifier).refresh());
    // Changes left on the phone from the last run are sent as soon as the app is up.
    ref.read(offlineQueueProvider);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// Coming back to the foreground re-checks the version/maintenance policy and the account.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) return;
    ref.read(policyControllerProvider.notifier).refresh();
    final auth = ref.read(authControllerProvider);
    if (auth is SignedIn) ref.read(authControllerProvider.notifier).refreshUser();
    ref.read(offlineQueueProvider.notifier).nudge();
  }

  /// The server accepted queued changes: every screen that shows what they changed must ask again.
  void _refreshAfterSync(Set<String> types) {
    if (types.any((t) => t.startsWith('workout.'))) {
      ref.invalidate(dashboardProvider);
      ref.invalidate(weekPlanProvider);
      ref.invalidate(dayDetailProvider);
      ref.invalidate(workoutSummaryProvider);
    }
    if (types.contains(OpTypes.measurementSave) || types.contains(OpTypes.postureSave) || types.any((t) => t.startsWith('workout.'))) {
      invalidateProgress(ref);
      ref.invalidate(dashboardProvider);
    }
  }

  @override
  Widget build(BuildContext context) {
    final router = ref.watch(routerProvider);
    ref.listen(offlineQueueProvider.select((q) => q.appliedRevision), (_, _) => _refreshAfterSync(ref.read(offlineQueueProvider).lastApplied));
    return MaterialApp.router(
      title: 'IronPulse',
      debugShowCheckedModeBanner: false,
      theme: buildAppTheme(),
      themeMode: ThemeMode.dark,
      darkTheme: buildAppTheme(),
      routerConfig: router,
      builder: (context, child) => AppBackground(child: child ?? const SizedBox.shrink()),
    );
  }
}
