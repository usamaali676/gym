import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/api/api_error.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/loaded_view.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/status_chip.dart';
import '../../workout_sessions/presentation/workout_actions.dart';
import '../domain/plan.dart';
import 'exercise_tile.dart';
import 'plan_providers.dart';

/// One day of the plan: what to do, with last results and the next target, and skip / un-skip.
class DayDetailScreen extends ConsumerStatefulWidget {
  const DayDetailScreen({super.key, required this.weekday, this.date});

  final int weekday;

  /// `yyyy-MM-dd`; without it the next occurrence of the weekday this week.
  final String? date;

  @override
  ConsumerState<DayDetailScreen> createState() => _DayDetailScreenState();
}

class _DayDetailScreenState extends ConsumerState<DayDetailScreen> {
  bool _busy = false;

  DayKey get _key => (widget.weekday, widget.date);

  Future<void> _run(Future<void> Function() action) async {
    setState(() => _busy = true);
    try {
      await action();
      invalidatePlanViews(ref);
    } on ApiError catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(dayDetailProvider(_key));
    final title = detail.asData?.value.data.header.title ?? 'Workout';

    Future<void> refresh() async {
      ref.invalidate(dayDetailProvider(_key));
      try {
        await ref.read(dayDetailProvider(_key).future);
      } catch (_) {}
    }

    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: SafeArea(
        child: LoadedView<DayDetail>(
          value: detail,
          onRefresh: refresh,
          children: (context, d) => _content(context, d),
        ),
      ),
    );
  }

  List<Widget> _content(BuildContext context, DayDetail d) {
    final repo = ref.read(planRepositoryProvider);
    final (chipText, tone) = switch (d.status) {
      'completed' => ('Done', Tone.good),
      'in_progress' => ('In progress', Tone.warn),
      'skipped' => ('Skipped', Tone.neutral),
      'missed' => ('Missed', Tone.bad),
      'rest' => ('Rest day', Tone.neutral),
      'today' => ('Today', Tone.good),
      _ => ('Upcoming', Tone.neutral),
    };

    return [
      GlassCard(
        neon: true,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(DateFormat.yMMMMEEEEd().format(d.date), style: IpText.body(13, weight: FontWeight.w600, color: IpColors.muted))),
            StatusChip(chipText, tone: tone),
          ]),
          const SizedBox(height: 8),
          Text(d.header.title, style: IpText.display(22, color: IpColors.ink)),
          if (d.header.focus != null && d.header.focus!.isNotEmpty) ...[const SizedBox(height: 4), Text(d.header.focus!, style: IpText.body(14, color: IpColors.muted))],
          if (!d.header.isRest) ...[
            const SizedBox(height: 10),
            Row(children: [const Icon(Icons.schedule, size: 16, color: IpColors.muted), const SizedBox(width: 6), Text('About ${d.estimatedMinutes} min · ${d.exercises.length} exercises', style: IpText.body(13.5, color: IpColors.muted))]),
          ],
          if (d.header.description != null && d.header.description!.isNotEmpty) ...[const SizedBox(height: 10), Text(d.header.description!, style: IpText.body(13.5, color: IpColors.ink))],
          if (d.header.isRest && d.header.recoveryNote != null) ...[const SizedBox(height: 10), Text(d.header.recoveryNote!, style: IpText.body(13.5, color: IpColors.ink))],
        ]),
      ),
      const SizedBox(height: 14),
      if (d.canAct && !d.header.isRest && d.status != 'completed' && d.status != 'skipped')
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: NeonButton(
            label: d.status == 'in_progress' || d.session?.status == 'in_progress' || d.session?.status == 'paused' ? 'Resume workout' : 'Start workout',
            size: NeonButtonSize.large,
            expand: true,
            icon: Icons.play_arrow,
            onPressed: () => startOrResumeWorkout(context, ref, weekday: d.weekday, date: d.date, openSessionId: (d.session?.status == 'in_progress' || d.session?.status == 'paused') ? d.session!.id : null),
          ),
        ),
      if (d.status == 'skipped' && d.canAct)
        NeonButton(label: 'Undo skip', variant: NeonButtonVariant.ghost, expand: true, loading: _busy, onPressed: () => _run(() => repo.unskipDay(d.weekday, d.date)))
      else if (d.canAct && d.status != 'completed' && !d.header.isRest)
        NeonButton(label: 'Skip this day', variant: NeonButtonVariant.ghost, expand: true, loading: _busy, onPressed: () => _run(() => repo.skipDay(d.weekday, d.date))),
      if (d.isFuture && !d.header.isRest) ...[
        const SizedBox(height: 10),
        Text('This day is still ahead. You can start it on the day.', textAlign: TextAlign.center, style: IpText.body(12.5, color: IpColors.muted)),
      ],
      const SizedBox(height: 18),
      for (final e in d.exercises) Padding(padding: const EdgeInsets.only(bottom: 10), child: ExerciseTile(exercise: e)),
    ];
  }
}
