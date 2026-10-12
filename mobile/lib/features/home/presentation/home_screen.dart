import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/policy/app_config.dart';
import '../../../core/policy/policy_controller.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/state_views.dart';
import '../../authentication/presentation/auth_controller.dart';

/// Landing screen of the signed-in app. For now it shows what `/me` provides (level, XP, streak); the dashboard,
/// today's workout and nutrition arrive with the fitness features.
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    final policy = ref.watch(policyControllerProvider);
    if (auth is! SignedIn) return const SizedBox.shrink();
    final user = auth.user;

    Future<void> refresh() async {
      await ref.read(policyControllerProvider.notifier).refresh();
      await ref.read(authControllerProvider.notifier).refreshUser();
    }

    return SafeArea(
      bottom: false,
      child: Column(children: [
        if (auth.offline || policy.offline) const OfflineBanner(),
        Expanded(
          child: RefreshIndicator(
            color: IpColors.neon,
            backgroundColor: IpColors.surface2,
            onRefresh: refresh,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(20, 20, 20, 24),
              children: [
                Text('WELCOME BACK', style: IpText.eyebrow(color: IpColors.neon)),
                const SizedBox(height: 6),
                Text(user.name.isEmpty ? 'Athlete' : user.name, style: IpText.display(26, color: IpColors.ink)),
                const SizedBox(height: 20),
                if (policy.updateRecommended) ...[_UpdateCard(config: policy.config!), const SizedBox(height: 16)],
                GlassCard(
                  neon: true,
                  child: Row(children: [
                    Expanded(child: _Stat(label: 'Level', value: '${user.level}')),
                    Expanded(child: _Stat(label: 'XP', value: '${user.xpTotal}')),
                    Expanded(child: _Stat(label: 'Day streak', value: '${user.workoutStreak}')),
                  ]),
                ),
                const SizedBox(height: 16),
                GlassCard(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('Your programme is on its way', style: IpText.display(15, color: IpColors.ink)),
                    const SizedBox(height: 8),
                    Text(
                      'Workouts, nutrition and progress screens are being added to the app. They use the same account and data as the website.',
                      style: IpText.body(13.5, color: IpColors.muted),
                    ),
                  ]),
                ),
              ],
            ),
          ),
        ),
      ]),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      label: '$label $value',
      excludeSemantics: true,
      child: Column(children: [
        Text(label.toUpperCase(), style: IpText.body(11.5, weight: FontWeight.w600, color: IpColors.muted).copyWith(letterSpacing: 1)),
        const SizedBox(height: 4),
        FittedBox(fit: BoxFit.scaleDown, child: Text(value, style: IpText.number(34, color: IpColors.neon))),
      ]),
    );
  }
}

class _UpdateCard extends StatelessWidget {
  const _UpdateCard({required this.config});
  final AppConfig config;

  @override
  Widget build(BuildContext context) {
    final url = config.storeUrl;
    return GlassCard(
      child: Row(children: [
        const Icon(Icons.system_update_alt, color: IpColors.warn),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('Update available', style: IpText.display(14, color: IpColors.ink)),
            const SizedBox(height: 4),
            Text(config.releaseNotes ?? 'A newer version of IronPulse is out.', style: IpText.body(12.5, color: IpColors.muted)),
          ]),
        ),
        if (url != null) ...[
          const SizedBox(width: 8),
          NeonButton(label: 'Update', size: NeonButtonSize.small, onPressed: () => launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication)),
        ],
      ]),
    );
  }
}
