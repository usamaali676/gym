import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/api/api_error.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/loaded_view.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/status_chip.dart';
import '../../membership/presentation/locked_view.dart';
import '../domain/programme.dart';
import 'exercise_tile.dart';
import 'plan_providers.dart';

class ProgrammeDetailScreen extends ConsumerStatefulWidget {
  const ProgrammeDetailScreen({super.key, required this.programmeId});
  final int programmeId;

  @override
  ConsumerState<ProgrammeDetailScreen> createState() => _ProgrammeDetailScreenState();
}

class _ProgrammeDetailScreenState extends ConsumerState<ProgrammeDetailScreen> {
  bool _busy = false;

  Future<void> _select(ProgrammeDetail d) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Switch programme?'),
        content: Text('You will follow "${d.programme.name}" from today. Workouts you already did keep their history.', style: IpText.body(14, color: IpColors.muted)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          NeonButton(label: 'Switch', size: NeonButtonSize.small, onPressed: () => Navigator.pop(context, true)),
        ],
      ),
    );
    if (ok != true || !mounted) return;

    setState(() => _busy = true);
    try {
      await ref.read(planRepositoryProvider).selectProgramme(d.programme.id);
      invalidatePlanViews(ref);
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Now following ${d.programme.name}.')));
    } on ApiError catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(programmeDetailProvider(widget.programmeId));
    final name = detail.asData?.value.data.programme.name ?? 'Programme';

    Future<void> refresh() async {
      ref.invalidate(programmeDetailProvider(widget.programmeId));
      try {
        await ref.read(programmeDetailProvider(widget.programmeId).future);
      } catch (_) {}
    }

    return Scaffold(
      appBar: AppBar(title: Text(name)),
      body: SafeArea(
        child: LoadedView<ProgrammeDetail>(
          value: detail,
          onRefresh: refresh,
          children: (context, d) {
            final p = d.programme;
            if (p.locked) return [LockedView(entitlementKey: p.requiredEntitlement, compact: true)];

            return [
              GlassCard(
                neon: p.isSelected,
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Expanded(child: Text(p.name, style: IpText.display(20, color: IpColors.ink))),
                    if (p.isSelected) const StatusChip('Current', tone: Tone.good),
                  ]),
                  if (p.description != null && p.description!.isNotEmpty) ...[const SizedBox(height: 8), Text(p.description!, style: IpText.body(14, color: IpColors.muted))],
                  const SizedBox(height: 12),
                  Wrap(spacing: 8, runSpacing: 6, children: [
                    if (p.trainingDays != null) StatusChip('${p.trainingDays} training days'),
                    if (d.phases.isNotEmpty) StatusChip('${d.phases.length} ${d.phases.length == 1 ? 'phase' : 'phases'}'),
                  ]),
                  if (!p.isSelected) ...[
                    const SizedBox(height: 14),
                    NeonButton(label: 'Use this programme', expand: true, loading: _busy, onPressed: () => _select(d)),
                  ],
                ]),
              ),
              if (d.phases.isNotEmpty) ...[
                const SizedBox(height: 18),
                Text('PHASES', style: IpText.eyebrow(color: IpColors.neon)),
                const SizedBox(height: 10),
                for (final phase in d.phases)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: GlassCard(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Expanded(child: Text(phase.name, style: IpText.display(15, color: IpColors.ink))),
                          StatusChip(phase.kind == 'sprint' ? 'Sprint' : 'Phase'),
                        ]),
                        if (phase.weeks.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text(phase.weeks, style: IpText.body(12.5, weight: FontWeight.w600, color: IpColors.neon))),
                        if (phase.summary != null && phase.summary!.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text(phase.summary!, style: IpText.body(13.5, color: IpColors.muted))),
                      ]),
                    ),
                  ),
              ],
              const SizedBox(height: 18),
              Text('WEEKLY TEMPLATE', style: IpText.eyebrow(color: IpColors.neon)),
              const SizedBox(height: 10),
              for (final day in d.days)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: GlassCard(
                    padding: EdgeInsets.zero,
                    child: Theme(
                      data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
                      child: ExpansionTile(
                        tilePadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                        childrenPadding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                        iconColor: IpColors.neon,
                        collapsedIconColor: IpColors.muted,
                        title: Text(day.header.title, style: IpText.display(14.5, color: IpColors.ink)),
                        subtitle: Text(day.header.isRest ? 'Rest' : '${day.exercises.length} exercises · about ${day.estimatedMinutes} min', style: IpText.body(12.5, color: IpColors.muted)),
                        children: [
                          if (day.header.isRest && day.header.recoveryNote != null) Padding(padding: const EdgeInsets.all(8), child: Text(day.header.recoveryNote!, style: IpText.body(13, color: IpColors.muted))),
                          for (final e in day.exercises) Padding(padding: const EdgeInsets.only(bottom: 8), child: ExerciseTile(exercise: e)),
                        ],
                      ),
                    ),
                  ),
                ),
            ];
          },
        ),
      ),
    );
  }
}
