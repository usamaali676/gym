import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme/tokens.g.dart';
import '../../app/theme/typography.dart';
import '../utilities/store_link.dart';
import 'glass_card.dart';

/// Who designed and built the app, and where to find them. The website shows the same credit on every page.
const creditName = 'Usama Ali';
const creditUrl = 'https://www.linkedin.com/in/muhammad-usama-ali-887796214?utm_source=share_via&utm_content=profile&utm_medium=member_ios';

class CreditBadge extends ConsumerWidget {
  const CreditBadge({super.key});

  Future<void> _open(BuildContext context, WidgetRef ref) async {
    var opened = false;
    try {
      opened = await ref.read(urlOpenerProvider)(Uri.parse(creditUrl));
    } catch (_) {
      opened = false;
    }
    if (!opened && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('The link could not be opened.')));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return GlassCard(
      neon: true,
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
      onTap: () => _open(context, ref),
      semanticLabel: 'Designed and developed by $creditName. Opens LinkedIn.',
      child: SizedBox(
        width: double.infinity,
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Text('DESIGNED & DEVELOPED BY', textAlign: TextAlign.center, style: IpText.body(11, weight: FontWeight.w700, color: IpColors.muted).copyWith(letterSpacing: 1.8)),
          const SizedBox(height: 6),
          Text(creditName, textAlign: TextAlign.center, style: IpText.display(22, color: IpColors.neon)),
          const SizedBox(height: 6),
          Row(mainAxisSize: MainAxisSize.min, children: [
            Text('View on LinkedIn', style: IpText.body(13, weight: FontWeight.w600, color: IpColors.ink).copyWith(decoration: TextDecoration.underline, decorationColor: IpColors.neon)),
            const SizedBox(width: 4),
            const Icon(Icons.open_in_new, size: 14, color: IpColors.neon),
          ]),
        ]),
      ),
    );
  }
}
