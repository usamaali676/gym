import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/api/loaded.dart';
import '../../../core/policy/policy_controller.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/loaded_view.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/status_chip.dart';
import '../../membership/presentation/locked_view.dart';
import '../domain/exercise.dart';
import 'demo_clip.dart';
import 'library_controller.dart';

final exerciseDetailProvider = FutureProvider.family<Loaded<Exercise>, String>((ref, slug) => ref.watch(exerciseRepositoryProvider).exercise(slug));

class ExerciseDetailScreen extends ConsumerWidget {
  const ExerciseDetailScreen({super.key, required this.slug});
  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(exerciseDetailProvider(slug));
    final labels = {for (final o in ref.watch(policyControllerProvider).config?.libraryChoices('equipment') ?? const []) o.value.toString(): o.label};

    Future<void> refresh() async {
      ref.invalidate(exerciseDetailProvider(slug));
      try {
        await ref.read(exerciseDetailProvider(slug).future);
      } catch (_) {}
    }

    return Scaffold(
      appBar: AppBar(title: Text(value.asData?.value.data.name ?? 'Exercise')),
      body: SafeArea(
        child: LoadedView<Exercise>(
          value: value,
          onRefresh: refresh,
          children: (context, e) {
            if (e.locked) {
              return [
                Text(e.name, style: IpText.display(22, color: IpColors.ink)),
                const SizedBox(height: 10),
                Wrap(spacing: 6, runSpacing: 6, children: [StatusChip(e.muscleLabel), const StatusChip('Premium', tone: Tone.warn, icon: Icons.lock_outline)]),
                const SizedBox(height: 18),
                LockedView(entitlementKey: e.requiredEntitlement ?? 'library.premium', compact: true),
              ];
            }
            return [
              DemoClips(media: e.media, exerciseName: e.name),
              if (DemoClips.playable(e.media).isNotEmpty) const SizedBox(height: 16),
              Text(e.name, style: IpText.display(22, color: IpColors.ink)),
              const SizedBox(height: 10),
              Wrap(spacing: 6, runSpacing: 6, children: [
                StatusChip(e.muscleLabel, tone: Tone.good),
                if (e.difficulty != null) StatusChip(e.difficulty![0].toUpperCase() + e.difficulty!.substring(1)),
                for (final q in e.equipment) StatusChip(labels[q] ?? q),
                if (e.isTimed) const StatusChip('Timed hold'),
                if (e.perSide) const StatusChip('Each side'),
              ]),
              if (e.description != null && e.description!.isNotEmpty) ...[const SizedBox(height: 16), Text(e.description!, style: IpText.body(14.5, color: IpColors.ink))],
              if (e.formCues.isNotEmpty) ...[const SizedBox(height: 16), _ListCard(title: 'FORM CUES', icon: Icons.check_circle_outline, color: IpColors.neon, items: e.formCues)],
              if (e.commonMistakes.isNotEmpty) ...[const SizedBox(height: 14), _ListCard(title: 'COMMON MISTAKES', icon: Icons.error_outline, color: IpColors.warn, items: e.commonMistakes)],
              if (e.breathing != null && e.breathing!.isNotEmpty) ...[
                const SizedBox(height: 14),
                GlassCard(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('BREATHING', style: IpText.eyebrow(color: IpColors.neon)), const SizedBox(height: 8), Text(e.breathing!, style: IpText.body(14, color: IpColors.ink))])),
              ],
              if (e.defaultRestSeconds != null || e.defaultHoldSeconds != null) ...[
                const SizedBox(height: 14),
                Text(
                  [if (e.defaultRestSeconds != null) 'Typical rest: ${e.defaultRestSeconds} s', if (e.defaultHoldSeconds != null && e.isTimed) 'Typical hold: ${e.defaultHoldSeconds} s'].join(' · '),
                  style: IpText.body(13, color: IpColors.muted),
                ),
              ],
              if (e.postureSupported) ...[
                const SizedBox(height: 14),
                const Align(alignment: Alignment.centerLeft, child: StatusChip('Camera form coach supported', tone: Tone.good, icon: Icons.videocam_outlined)),
                const SizedBox(height: 10),
                NeonButton(label: 'Check my form', icon: Icons.videocam_outlined, variant: NeonButtonVariant.ghost, expand: true, onPressed: () => context.push('${Routes.posture}/${e.slug}')),
              ],
            ];
          },
        ),
      ),
    );
  }
}

class _ListCard extends StatelessWidget {
  const _ListCard({required this.title, required this.icon, required this.color, required this.items});
  final String title;
  final IconData icon;
  final Color color;
  final List<String> items;

  @override
  Widget build(BuildContext context) => GlassCard(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(title, style: IpText.eyebrow(color: IpColors.neon)),
          const SizedBox(height: 10),
          for (final item in items)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Icon(icon, size: 18, color: color),
                const SizedBox(width: 10),
                Expanded(child: Text(item, style: IpText.body(14, color: IpColors.ink))),
              ]),
            ),
        ]),
      );
}
