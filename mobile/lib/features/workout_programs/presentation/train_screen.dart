import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/loaded_view.dart';
import '../../../core/widgets/neon_progress.dart';
import '../../../core/widgets/status_chip.dart';
import '../../exercise_library/presentation/library_screen.dart';
import '../domain/plan.dart';
import '../domain/programme.dart';
import 'plan_providers.dart';

/// Train tab: the week's plan and the programmes the gym offers.
class TrainScreen extends ConsumerStatefulWidget {
  const TrainScreen({super.key});

  @override
  ConsumerState<TrainScreen> createState() => _TrainScreenState();
}

class _TrainScreenState extends ConsumerState<TrainScreen> {
  int _tab = 0;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      bottom: false,
      child: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('TRAIN', style: IpText.eyebrow(color: IpColors.neon)),
            const SizedBox(height: 10),
            _Segments(index: _tab, labels: const ['This week', 'Programmes', 'Exercises'], onChanged: (i) => setState(() => _tab = i)),
          ]),
        ),
        Expanded(child: switch (_tab) { 0 => const _WeekTab(), 1 => const _ProgrammesTab(), _ => const LibraryScreen(embedded: true) }),
      ]),
    );
  }
}

class _Segments extends StatelessWidget {
  const _Segments({required this.index, required this.labels, required this.onChanged});
  final int index;
  final List<String> labels;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    return Row(children: [
      for (var i = 0; i < labels.length; i++) ...[
        if (i > 0) const SizedBox(width: 8),
        Expanded(
          child: Semantics(
            button: true,
            selected: i == index,
            label: labels[i],
            excludeSemantics: true,
            child: GestureDetector(
              onTap: () => onChanged(i),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 160),
                constraints: const BoxConstraints(minHeight: 40),
                padding: const EdgeInsets.symmetric(horizontal: 4),
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(999),
                  color: i == index ? const Color.fromRGBO(57, 255, 20, 0.12) : Colors.transparent,
                  border: Border.all(color: i == index ? IpColors.neon : IpColors.line),
                ),
                // Large text sizes shrink the label rather than overflow the pill.
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(labels[i], maxLines: 1, style: IpText.body(13.5, weight: FontWeight.w600, color: i == index ? IpColors.neon : IpColors.muted)),
                ),
              ),
            ),
          ),
        ),
      ],
    ]);
  }
}

// ---------------------------------------------------------------------------------------------------- week

class _WeekTab extends ConsumerWidget {
  const _WeekTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final week = ref.watch(weekPlanProvider);

    Future<void> refresh() async {
      ref.invalidate(weekPlanProvider);
      try {
        await ref.read(weekPlanProvider.future);
      } catch (_) {}
    }

    return LoadedView<WeekPlan>(
      value: week,
      onRefresh: refresh,
      padding: const EdgeInsets.fromLTRB(20, 4, 20, 24),
      children: (context, w) => [
        _WeekHeader(w: w),
        const SizedBox(height: 14),
        for (final entry in w.days) Padding(padding: const EdgeInsets.only(bottom: 10), child: _DayRow(entry: entry)),
      ],
    );
  }
}

class _WeekHeader extends ConsumerWidget {
  const _WeekHeader({required this.w});
  final WeekPlan w;

  static String _range(WeekPlan w) {
    final f = DateFormat.MMMd();
    return '${f.format(w.monday)} - ${f.format(w.sunday)}';
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final anchor = ref.read(weekAnchorProvider.notifier);
    return GlassCard(
      neon: w.isThisWeek,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          IconButton(tooltip: 'Previous week', onPressed: () => anchor.show(w.monday.subtract(const Duration(days: 7))), icon: const Icon(Icons.chevron_left)),
          Expanded(
            child: Column(children: [
              Text(w.isThisWeek ? 'THIS WEEK' : 'WEEK ${w.weekNumber}', style: IpText.eyebrow(color: IpColors.neon)),
              const SizedBox(height: 4),
              Text(_range(w), style: IpText.display(15, color: IpColors.ink)),
              if (w.programName != null) Text(w.programName!, style: IpText.body(12.5, color: IpColors.muted)),
            ]),
          ),
          IconButton(tooltip: 'Next week', onPressed: () => anchor.show(w.monday.add(const Duration(days: 7))), icon: const Icon(Icons.chevron_right)),
        ]),
        const SizedBox(height: 10),
        NeonProgress(value: w.target == 0 ? 0 : w.done / w.target, semanticLabel: 'Sessions done this week'),
        const SizedBox(height: 6),
        Text('${w.done} of ${w.target} sessions done', style: IpText.body(12.5, color: IpColors.muted)),
        if (!w.isThisWeek)
          Align(
            alignment: Alignment.centerRight,
            child: TextButton(onPressed: () => anchor.show(null), child: Text('Back to this week', style: IpText.body(13, weight: FontWeight.w600, color: IpColors.neon))),
          ),
      ]),
    );
  }
}

class _DayRow extends StatelessWidget {
  const _DayRow({required this.entry});
  final WeekDayEntry entry;

  static (String, Tone) _chip(String status) => switch (status) {
        'completed' => ('Done', Tone.good),
        'in_progress' => ('In progress', Tone.warn),
        'skipped' => ('Skipped', Tone.neutral),
        'missed' => ('Missed', Tone.bad),
        'rest' => ('Rest', Tone.neutral),
        'today' => ('Today', Tone.good),
        _ => ('Upcoming', Tone.neutral),
      };

  @override
  Widget build(BuildContext context) {
    final day = entry.day;
    final (chipText, tone) = _chip(entry.status);
    final weekdayName = DateFormat.EEEE().format(entry.date);

    return GlassCard(
      neon: entry.isToday,
      onTap: day == null ? null : () => context.push('${Routes.trainDay}/${entry.weekday}?date=${dateParam(entry.date)}'),
      semanticLabel: '$weekdayName, ${day?.title ?? 'no plan'}, $chipText',
      child: Row(children: [
        SizedBox(
          width: 44,
          child: Column(children: [
            Text(DateFormat.E().format(entry.date).toUpperCase(), style: IpText.body(11.5, weight: FontWeight.w700, color: entry.isToday ? IpColors.neon : IpColors.muted)),
            Text('${entry.date.day}', style: IpText.number(24, color: IpColors.ink)),
          ]),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(day?.title ?? 'No plan for this day', style: IpText.display(14.5, color: IpColors.ink)),
            if (day?.focus != null && day!.focus!.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 2), child: Text(day.focus!, maxLines: 1, overflow: TextOverflow.ellipsis, style: IpText.body(12.5, color: IpColors.muted))),
          ]),
        ),
        const SizedBox(width: 8),
        StatusChip(chipText, tone: tone),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------------------------- programmes

class _ProgrammesTab extends ConsumerWidget {
  const _ProgrammesTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final list = ref.watch(programmesProvider);

    Future<void> refresh() async {
      ref.invalidate(programmesProvider);
      try {
        await ref.read(programmesProvider.future);
      } catch (_) {}
    }

    return LoadedView<List<Programme>>(
      value: list,
      onRefresh: refresh,
      padding: const EdgeInsets.fromLTRB(20, 4, 20, 24),
      children: (context, programmes) => [
        if (programmes.isEmpty) const GlassCard(child: Text('The gym has not published any programmes yet.')),
        for (final p in programmes) Padding(padding: const EdgeInsets.only(bottom: 12), child: _ProgrammeCard(p: p)),
      ],
    );
  }
}

class _ProgrammeCard extends StatelessWidget {
  const _ProgrammeCard({required this.p});
  final Programme p;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      neon: p.isSelected,
      onTap: () => context.push('${Routes.programme}/${p.id}'),
      semanticLabel: '${p.name}${p.isSelected ? ', your current programme' : ''}${p.locked ? ', locked' : ''}',
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text(p.name, style: IpText.display(16, color: IpColors.ink))),
          if (p.isSelected) const StatusChip('Current', tone: Tone.good) else if (p.locked) const StatusChip('Locked', tone: Tone.warn, icon: Icons.lock_outline),
        ]),
        if (p.description != null && p.description!.isNotEmpty) ...[
          const SizedBox(height: 6),
          Text(p.description!, maxLines: 3, overflow: TextOverflow.ellipsis, style: IpText.body(13.5, color: IpColors.muted)),
        ],
        const SizedBox(height: 10),
        Wrap(spacing: 8, runSpacing: 6, children: [
          if (p.trainingDays != null) StatusChip('${p.trainingDays} training days'),
          if ((p.phasesCount ?? 0) > 0) StatusChip('${p.phasesCount} phases'),
        ]),
      ]),
    );
  }
}
