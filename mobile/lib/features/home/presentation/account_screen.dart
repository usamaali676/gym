import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/router.dart';
import '../../membership/presentation/membership_providers.dart';

import '../../../app/config/environment.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/sync/offline_queue.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/status_chip.dart';
import '../../authentication/presentation/auth_controller.dart';

class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  Future<void> _confirmSignOut(BuildContext context, WidgetRef ref, {required bool guest}) async {
    final unsent = ref.read(offlineQueueProvider).ops.length;
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Sign out?'),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (unsent > 0) ...[
            Text(
              '$unsent ${unsent == 1 ? 'change has' : 'changes have'} not reached the server yet. They stay on this phone and are sent the next time you sign in to this account. Open Sync first if you want to send them now.',
              style: IpText.body(14, weight: FontWeight.w600, color: IpColors.warn),
            ),
            const SizedBox(height: 10),
          ],
          Text(
            guest
                ? 'You are using a guest account. Signing out removes your access to it, and your data cannot be recovered on another device.'
                : 'You will need your email and password to sign in again.',
            style: IpText.body(14, color: IpColors.muted),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          NeonButton(label: 'Sign out', variant: NeonButtonVariant.danger, size: NeonButtonSize.small, onPressed: () => Navigator.pop(context, true)),
        ],
      ),
    );
    if (ok == true) await ref.read(authControllerProvider.notifier).signOut();
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    if (auth is! SignedIn) return const SizedBox.shrink();
    final user = auth.user;
    final env = ref.watch(appEnvironmentProvider);
    final planName = ref.watch(membershipProvider).asData?.value.data.plan?.name;

    return SafeArea(
      bottom: false,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 24),
        children: [
          Text('ACCOUNT', style: IpText.eyebrow(color: IpColors.neon)),
          const SizedBox(height: 6),
          Text(user.name.isEmpty ? 'Athlete' : user.name, style: IpText.display(24, color: IpColors.ink)),
          const SizedBox(height: 16),
          GlassCard(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              if (user.email != null) Text(user.email!, style: IpText.body(14.5, color: IpColors.ink)),
              const SizedBox(height: 10),
              Wrap(spacing: 8, runSpacing: 8, children: [
                StatusChip(user.isGuest ? 'Guest account' : 'Member', tone: user.isGuest ? Tone.warn : Tone.good),
                if (user.isAdmin) const StatusChip('Admin'),
              ]),
            ]),
          ),
          const SizedBox(height: 16),
          GlassCard(
            onTap: () => context.push(Routes.membership),
            semanticLabel: 'Membership',
            child: Row(children: [
              const Icon(Icons.card_membership_outlined, color: IpColors.neon),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('Membership', style: IpText.display(15, color: IpColors.ink)),
                  const SizedBox(height: 2),
                  Text(planName ?? 'See your plan and what it includes', style: IpText.body(13, color: IpColors.muted)),
                ]),
              ),
              const Icon(Icons.chevron_right, color: IpColors.muted),
            ]),
          ),
          const SizedBox(height: 12),
          _SyncTile(),
          const SizedBox(height: 12),
          GlassCard(
            onTap: () => context.push(Routes.posture),
            semanticLabel: 'Camera form coach',
            child: Row(children: [
              const Icon(Icons.videocam_outlined, color: IpColors.neon),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('Camera form coach', style: IpText.display(15, color: IpColors.ink)),
                  const SizedBox(height: 2),
                  Text('Count reps and catch form mistakes with your phone', style: IpText.body(13, color: IpColors.muted)),
                ]),
              ),
              const Icon(Icons.chevron_right, color: IpColors.muted),
            ]),
          ),
          const SizedBox(height: 16),
          GlassCard(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('ABOUT THIS APP', style: IpText.body(12, weight: FontWeight.w600, color: IpColors.muted).copyWith(letterSpacing: 0.8)),
              const SizedBox(height: 10),
              _Row('Version', '${env.appVersion} (${env.appBuild})'),
              _Row('Server', Uri.tryParse(env.apiBaseUrl)?.host ?? env.apiBaseUrl),
            ]),
          ),
          const SizedBox(height: 24),
          NeonButton(
            label: 'Sign out',
            icon: Icons.logout,
            variant: NeonButtonVariant.danger,
            expand: true,
            onPressed: () => _confirmSignOut(context, ref, guest: user.isGuest),
          ),
        ],
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row(this.label, this.value);
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text(label, style: IpText.body(14, color: IpColors.muted)),
          Flexible(child: Text(value, textAlign: TextAlign.end, style: IpText.body(14, color: IpColors.ink))),
        ]),
      );
}

class _SyncTile extends ConsumerWidget {
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final q = ref.watch(offlineQueueProvider);
    final subtitle = q.isEmpty
        ? 'Everything is saved to the server'
        : q.needAttention > 0
            ? '${q.needAttention} ${q.needAttention == 1 ? 'change needs' : 'changes need'} your attention'
            : '${q.waiting.length} ${q.waiting.length == 1 ? 'change is' : 'changes are'} waiting to be sent';
    return GlassCard(
      onTap: () => context.push(Routes.sync),
      semanticLabel: 'Sync. $subtitle',
      child: Row(children: [
        Icon(q.isEmpty ? Icons.cloud_done_outlined : Icons.cloud_off_outlined, color: q.isEmpty ? IpColors.neon : IpColors.warn),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('Sync', style: IpText.display(15, color: IpColors.ink)),
            const SizedBox(height: 2),
            Text(subtitle, style: IpText.body(13, color: IpColors.muted)),
          ]),
        ),
        const Icon(Icons.chevron_right, color: IpColors.muted),
      ]),
    );
  }
}
