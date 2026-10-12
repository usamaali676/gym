import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/policy/policy_controller.dart';
import '../core/widgets/app_background.dart';
import '../features/authentication/presentation/auth_controller.dart';
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
  }

  @override
  Widget build(BuildContext context) {
    final router = ref.watch(routerProvider);
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
