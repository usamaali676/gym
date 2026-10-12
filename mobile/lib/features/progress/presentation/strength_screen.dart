import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/loaded_view.dart';
import '../domain/progress.dart';
import 'charts.dart';
import 'progress_providers.dart';

/// Estimated one-rep max over time for one exercise (computed by the server from the sets that were logged).
class StrengthScreen extends ConsumerWidget {
  const StrengthScreen({super.key, required this.slug});
  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(strengthProvider(slug));

    Future<void> refresh() async {
      ref.invalidate(strengthProvider(slug));
      try {
        await ref.read(strengthProvider(slug).future);
      } catch (_) {}
    }

    return Scaffold(
      appBar: AppBar(title: Text(value.asData?.value.data.name ?? 'Strength')),
      body: SafeArea(
        child: LoadedView<StrengthSeries>(
          value: value,
          onRefresh: refresh,
          children: (context, s) {
            final pts = s.points.where((p) => p.e1rm != null).toList();
            return [
              GlassCard(
                neon: true,
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('ESTIMATED 1-REP MAX', style: IpText.eyebrow(color: IpColors.neon)),
                  const SizedBox(height: 10),
                  if (pts.length >= 2)
                    TrendChart(points: [for (final p in pts) (p.date, p.e1rm!)], unit: 'kg', semanticLabel: 'Estimated one-rep max from ${pts.first.e1rm!.toStringAsFixed(1)} to ${pts.last.e1rm!.toStringAsFixed(1)} kilograms')
                  else
                    Text(pts.isEmpty ? 'No sets logged for this exercise yet.' : 'One session so far. A second one draws the curve.', style: IpText.body(13.5, color: IpColors.muted)),
                  const SizedBox(height: 8),
                  Text('An estimate from your heaviest sets, not a tested max.', style: IpText.body(12, color: IpColors.muted)),
                ]),
              ),
              const SizedBox(height: 14),
              for (final p in s.points.reversed)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: GlassCard(
                    padding: const EdgeInsets.all(14),
                    child: Row(children: [
                      Text(DateFormat.yMMMd().format(p.date), style: IpText.body(13.5, color: IpColors.muted)),
                      const Spacer(),
                      Text(
                        [if (p.topWeight != null) 'top ${p.topWeight!.toStringAsFixed(1)} kg', if (p.e1rm != null) 'e1RM ${p.e1rm!.toStringAsFixed(1)}'].join(' · '),
                        style: IpText.body(14, weight: FontWeight.w600, color: IpColors.ink),
                      ),
                    ]),
                  ),
                ),
            ];
          },
        ),
      ),
    );
  }
}
