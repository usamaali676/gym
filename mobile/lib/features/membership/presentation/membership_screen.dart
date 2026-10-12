import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/api/loaded.dart';
import '../../../core/policy/app_config.dart';
import '../../../core/policy/policy_controller.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/loaded_view.dart';
import '../../../core/widgets/status_chip.dart';
import '../domain/membership.dart';
import 'membership_providers.dart';

/// The member's plan, what it unlocks, and the other plans the gym offers.
///
/// Buying: there is no purchase button on purpose. Store billing (Apple / Google) needs the store accounts and
/// server credentials described in docs/mobile/payments.md; until a store is enabled on the server
/// (`payments.available_providers`) the gym manages memberships and this screen only explains them.
class MembershipScreen extends ConsumerWidget {
  const MembershipScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final membership = ref.watch(membershipProvider);
    final plans = ref.watch(plansProvider);
    final catalog = ref.watch(policyControllerProvider).config?.entitlementCatalog ?? const <EntitlementInfo>[];
    final limitLabels = ref.watch(policyControllerProvider).config?.limitLabels ?? const <String, String>{};

    Future<void> refresh() async {
      ref.invalidate(membershipProvider);
      ref.invalidate(plansProvider);
      try {
        await ref.read(membershipProvider.future);
      } catch (_) {}
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Membership')),
      body: SafeArea(
        child: LoadedView<Membership>(
          value: membership,
          onRefresh: refresh,
          children: (context, m) => [
            _CurrentPlan(m: m),
            const SizedBox(height: 14),
            _Included(m: m, catalog: catalog, limitLabels: limitLabels),
            const SizedBox(height: 14),
            _Managed(providers: m.paymentProviders),
            const SizedBox(height: 14),
            _OtherPlans(plans: plans, current: m.plan, catalog: catalog),
          ],
        ),
      ),
    );
  }
}

class _CurrentPlan extends StatelessWidget {
  const _CurrentPlan({required this.m});
  final Membership m;

  static String _date(DateTime d) => DateFormat.yMMMd().format(d);

  @override
  Widget build(BuildContext context) {
    final sub = m.subscription;
    final (statusText, tone) = switch (sub?.status) {
      'trialing' => ('Trial', Tone.good),
      'active' => ('Active', Tone.good),
      'canceled' => ('Cancelled', Tone.warn),
      'expired' => ('Expired', Tone.bad),
      'revoked' => ('Revoked', Tone.bad),
      _ => ('Included', Tone.neutral),
    };
    final ends = sub?.periodEndsAt ?? sub?.endsAt;

    return GlassCard(
      neon: true,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Text('YOUR PLAN', style: IpText.eyebrow(color: IpColors.neon)),
          const Spacer(),
          StatusChip(statusText, tone: tone),
        ]),
        const SizedBox(height: 10),
        Text(m.plan?.name ?? 'Free', style: IpText.display(22, color: IpColors.ink)),
        if (m.plan?.description != null && m.plan!.description!.isNotEmpty) ...[
          const SizedBox(height: 6),
          Text(m.plan!.description!, style: IpText.body(13.5, color: IpColors.muted)),
        ],
        if (sub != null && ends != null) ...[
          const SizedBox(height: 10),
          Text(
            switch (sub.status) {
              'expired' || 'revoked' => 'Ended ${_date(ends)}',
              'canceled' => 'Access until ${_date(ends)}',
              _ => sub.autoRenew ? 'Renews ${_date(ends)}' : 'Valid until ${_date(ends)}',
            },
            style: IpText.body(13.5, color: IpColors.ink),
          ),
        ],
        if (sub != null && sub.source.isNotEmpty) ...[
          const SizedBox(height: 4),
          Text(_via(sub.source), style: IpText.body(12.5, color: IpColors.muted)),
        ],
      ]),
    );
  }

  static String _via(String source) => switch (source) {
        'apple' => 'Billed by Apple. Manage it in your Apple ID subscriptions.',
        'google' => 'Billed by Google Play. Manage it in Play Store subscriptions.',
        _ => 'Managed by your gym.',
      };
}

class _Included extends StatelessWidget {
  const _Included({required this.m, required this.catalog, required this.limitLabels});
  final Membership m;
  final List<EntitlementInfo> catalog;
  final Map<String, String> limitLabels;

  @override
  Widget build(BuildContext context) {
    final rows = catalog.isNotEmpty ? catalog : [for (final k in m.entitlements.map.keys) EntitlementInfo(k, k, '')];
    return GlassCard(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text('WHAT YOU GET', style: IpText.eyebrow(color: IpColors.neon)),
        const SizedBox(height: 12),
        for (final e in rows)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: _FeatureRow(label: e.label, detail: e.description, included: m.entitlements.map[e.key] ?? false),
          ),
        for (final entry in m.limits.entries)
          if (entry.value != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Text('${limitLabels[entry.key] ?? entry.key}: up to ${entry.value}', style: IpText.body(12.5, color: IpColors.muted)),
            ),
      ]),
    );
  }
}

class _FeatureRow extends StatelessWidget {
  const _FeatureRow({required this.label, required this.detail, required this.included});
  final String label;
  final String detail;
  final bool included;

  @override
  Widget build(BuildContext context) => Semantics(
        label: '$label: ${included ? 'included' : 'not included'}',
        excludeSemantics: true,
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(included ? Icons.check_circle : Icons.lock_outline, size: 20, color: included ? IpColors.neon : IpColors.muted),
          const SizedBox(width: 10),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(label, style: IpText.body(14.5, weight: FontWeight.w600, color: included ? IpColors.ink : IpColors.muted)),
              if (detail.isNotEmpty) Text(detail, style: IpText.body(12, color: IpColors.muted)),
            ]),
          ),
        ]),
      );
}

class _Managed extends StatelessWidget {
  const _Managed({required this.providers});
  final List<String> providers;

  @override
  Widget build(BuildContext context) {
    // Store billing is not wired into the app yet, whatever the server offers; say so honestly.
    return GlassCard(
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Icon(Icons.storefront_outlined, color: IpColors.muted),
        const SizedBox(width: 12),
        Expanded(
          child: Text(
            providers.isEmpty
                ? 'Memberships are managed by your gym. To change your plan, ask them to update it; it appears here straight away.'
                : 'In-app purchase is not available in this version of the app. Ask your gym to change your plan.',
            style: IpText.body(13.5, color: IpColors.muted),
          ),
        ),
      ]),
    );
  }
}

class _OtherPlans extends StatelessWidget {
  const _OtherPlans({required this.plans, required this.current, required this.catalog});
  final AsyncValue<Loaded<List<PlanInfo>>> plans;
  final PlanInfo? current;
  final List<EntitlementInfo> catalog;

  @override
  Widget build(BuildContext context) {
    final list = plans.asData?.value.data;
    if (list == null || list.isEmpty) return const SizedBox.shrink();

    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Padding(padding: const EdgeInsets.only(bottom: 10), child: Text('ALL PLANS', style: IpText.eyebrow(color: IpColors.neon))),
      for (final plan in list)
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: GlassCard(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text(plan.name, style: IpText.display(16, color: IpColors.ink))),
                if (plan.slug == current?.slug) const StatusChip('Current', tone: Tone.good),
              ]),
              if (plan.priceDisplay != null) ...[
                const SizedBox(height: 4),
                // Informational only (admin-entered text); a store purchase would show the store's own price.
                Text('Listed price: ${plan.priceDisplay}', style: IpText.body(12.5, color: IpColors.muted)),
              ],
              const SizedBox(height: 10),
              for (final f in plan.features) Padding(padding: const EdgeInsets.only(bottom: 4), child: Text('• $f', style: IpText.body(13, color: IpColors.ink))),
              for (final e in catalog)
                if (plan.entitlements.map[e.key] == true && plan.features.isEmpty)
                  Padding(padding: const EdgeInsets.only(bottom: 4), child: Text('• ${e.label}', style: IpText.body(13, color: IpColors.ink))),
            ]),
          ),
        ),
    ]);
  }
}
