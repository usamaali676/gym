import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/loaded_view.dart';
import '../../../core/widgets/status_chip.dart';
import '../domain/posture_models.dart';
import 'posture_providers.dart';

/// The exercises the camera coach can judge. The list comes from the server, so new exercises appear without an app update.
class PostureListScreen extends ConsumerWidget {
  const PostureListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(postureExercisesProvider);

    Future<void> refresh() async {
      ref.invalidate(postureExercisesProvider);
      try {
        await ref.read(postureExercisesProvider.future);
      } catch (_) {}
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Camera form coach')),
      body: SafeArea(
        child: LoadedView<List<PostureExercise>>(
          value: value,
          onRefresh: refresh,
          children: (context, items) => [
            Text(
              'Prop your phone up, pick an exercise and the camera counts your reps and flags form mistakes. Pictures are analysed on your phone and are never stored or sent.',
              style: IpText.body(13.5, color: IpColors.muted, height: 1.45),
            ),
            const SizedBox(height: 14),
            if (items.isEmpty)
              Text('No exercises are set up for the camera coach yet.', style: IpText.body(14, color: IpColors.muted))
            else
              for (final e in items)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: GlassCard(
                    padding: const EdgeInsets.all(16),
                    onTap: () => context.push('${Routes.posture}/${e.slug}'),
                    semanticLabel: '${e.name}. ${e.viewLabel}. ${e.tierLabel}.',
                    child: Row(children: [
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(e.name, style: IpText.display(15, color: IpColors.ink)),
                          const SizedBox(height: 4),
                          Text(e.muscleGroup.replaceAll('_', ' '), style: IpText.body(12.5, color: IpColors.muted)),
                          const SizedBox(height: 8),
                          Wrap(spacing: 6, runSpacing: 6, children: [
                            StatusChip(e.viewLabel, icon: Icons.videocam_outlined),
                            StatusChip(e.tierLabel, tone: e.tier == 'full' ? Tone.good : Tone.neutral),
                          ]),
                        ]),
                      ),
                      const Icon(Icons.chevron_right, color: IpColors.muted),
                    ]),
                  ),
                ),
          ],
        ),
      ),
    );
  }
}
