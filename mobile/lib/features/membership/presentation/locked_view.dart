import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/policy/policy_controller.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/neon_button.dart';

/// "This is not part of your membership." Shown when the server answers `403 entitlement_required` (or the plan
/// says a teaser is locked). The wording of the feature comes from the server's catalogue.
class LockedView extends ConsumerWidget {
  const LockedView({super.key, this.entitlementKey, this.compact = false});

  final String? entitlementKey;

  /// A card instead of a full-screen message.
  final bool compact;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final catalog = ref.watch(policyControllerProvider).config?.entitlementCatalog ?? const [];
    final info = catalog.where((e) => e.key == entitlementKey).firstOrNull;
    final label = info?.label ?? 'This feature';

    final body = Column(mainAxisSize: MainAxisSize.min, children: [
      const Icon(Icons.lock_outline, size: 40, color: IpColors.warn),
      const SizedBox(height: 12),
      Text('Not in your membership', textAlign: TextAlign.center, style: IpText.display(16, color: IpColors.ink)),
      const SizedBox(height: 8),
      Text(
        info != null ? '$label is not included in your current plan.' : 'This is not included in your current plan.',
        textAlign: TextAlign.center,
        style: IpText.body(14, color: IpColors.muted),
      ),
      if (info != null && info.description.isNotEmpty) ...[
        const SizedBox(height: 4),
        Text(info.description, textAlign: TextAlign.center, style: IpText.body(12.5, color: IpColors.muted)),
      ],
      const SizedBox(height: 16),
      NeonButton(label: 'See membership', variant: NeonButtonVariant.ghost, onPressed: () => context.push(Routes.membership)),
    ]);

    if (compact) return GlassCard(child: body);
    return Center(child: Padding(padding: const EdgeInsets.all(24), child: body));
  }
}
