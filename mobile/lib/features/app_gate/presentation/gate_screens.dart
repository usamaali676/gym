import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/policy/policy_controller.dart';
import '../../../core/providers.dart';
import '../../../core/sync/offline_queue.dart';
import '../../../core/widgets/brand.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/state_views.dart';
import '../../authentication/presentation/auth_controller.dart';
import '../../workout_sessions/data/local_workout.dart';

/// Shown while the stored session is read and confirmed. Offline with nothing cached, it offers a retry.
class SplashScreen extends ConsumerWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    final error = auth is AuthInitializing ? auth.error : null;

    return Scaffold(
      body: SafeArea(
        child: error != null
            ? ErrorState(error: error, onRetry: () => ref.read(authControllerProvider.notifier).restore())
            : const Center(
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  BrandMark(size: 34),
                  SizedBox(height: 28),
                  SizedBox(width: 26, height: 26, child: CircularProgressIndicator(strokeWidth: 2.5)),
                ]),
              ),
      ),
    );
  }
}

/// `503 maintenance` or maintenance announced by `/app/config`. Retrying re-reads the config.
class MaintenanceScreen extends ConsumerWidget {
  const MaintenanceScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final policy = ref.watch(policyControllerProvider);
    final m = policy.maintenance;
    final ends = m?.endsAt;

    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Center(
            child: GlassCard(
              neon: true,
              padding: const EdgeInsets.all(24),
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                const BrandMark(size: 24),
                const SizedBox(height: 22),
                const Icon(Icons.build_circle_outlined, size: 48, color: IpColors.warn),
                const SizedBox(height: 14),
                Text('Back soon', style: IpText.display(20, color: IpColors.ink)),
                const SizedBox(height: 10),
                Text(m?.message ?? 'IronPulse is down for maintenance.', textAlign: TextAlign.center, style: IpText.body(14.5, color: IpColors.muted)),
                if (ends != null) ...[
                  const SizedBox(height: 8),
                  Text('Expected back around ${_clock(ends.toLocal())}.', textAlign: TextAlign.center, style: IpText.body(13, color: IpColors.muted)),
                ],
                const SizedBox(height: 22),
                NeonButton(
                  label: 'Check again',
                  icon: Icons.refresh,
                  loading: policy.loading,
                  onPressed: () => ref.read(policyControllerProvider.notifier).refresh(),
                ),
                const _ContinueWorkout(),
              ]),
            ),
          ),
        ),
      ),
    );
  }

  static String _clock(DateTime t) => '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';
}

/// `426 upgrade_required`: this version is below the minimum the server accepts.
class UpdateRequiredScreen extends ConsumerWidget {
  const UpdateRequiredScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = ref.watch(policyControllerProvider).config;
    final url = config?.storeUrl;
    final notes = config?.releaseNotes;

    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Center(
            child: GlassCard(
              neon: true,
              padding: const EdgeInsets.all(24),
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                const BrandMark(size: 24),
                const SizedBox(height: 22),
                const Icon(Icons.system_update_alt, size: 48, color: IpColors.neon),
                const SizedBox(height: 14),
                Text('Update required', style: IpText.display(20, color: IpColors.ink)),
                const SizedBox(height: 10),
                Text('This version of IronPulse is no longer supported. Update the app to continue.', textAlign: TextAlign.center, style: IpText.body(14.5, color: IpColors.muted)),
                if (notes != null) ...[
                  const SizedBox(height: 12),
                  Text(notes, textAlign: TextAlign.center, style: IpText.body(13, color: IpColors.muted)),
                ],
                const SizedBox(height: 22),
                if (url != null)
                  NeonButton(
                    label: 'Open the store',
                    icon: Icons.open_in_new,
                    onPressed: () => launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication),
                  )
                else
                  Text('The store link has not been set by the gym yet. Look for IronPulse in your app store.', textAlign: TextAlign.center, style: IpText.body(13, color: IpColors.muted)),
                const SizedBox(height: 10),
                NeonButton(
                  label: 'Check again',
                  variant: NeonButtonVariant.ghost,
                  loading: ref.watch(policyControllerProvider).loading,
                  onPressed: () => ref.read(policyControllerProvider.notifier).refresh(),
                ),
                const _ContinueWorkout(),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

/// "Continue my workout": a workout in progress on this phone is not stranded by maintenance or a required update. Its
/// sets and its finish are kept in the offline queue and sent when the server is back (and, for an update, after it).
class _ContinueWorkout extends ConsumerWidget {
  const _ContinueWorkout();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    final id = auth is SignedIn ? localWorkoutInProgress(ref.read(sharedPreferencesProvider), ref.read(queueScopeProvider)) : null;
    if (id == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: NeonButton(
        label: 'Continue my workout',
        icon: Icons.fitness_center,
        variant: NeonButtonVariant.ghost,
        onPressed: () => context.go('${Routes.workout}/$id'),
      ),
    );
  }
}
