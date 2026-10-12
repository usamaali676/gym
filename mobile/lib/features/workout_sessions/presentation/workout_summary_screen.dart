import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/state_views.dart';
import '../../../core/widgets/status_chip.dart';
import '../data/session_repository.dart';
import 'workout_controller.dart';

/// Results of a finished workout. XP, personal records, level and badges are all computed on the server.
class WorkoutSummaryScreen extends ConsumerWidget {
  const WorkoutSummaryScreen({super.key, required this.sessionId});
  final int sessionId;

  static String _duration(int seconds) {
    final m = seconds ~/ 60;
    final s = seconds % 60;
    return m >= 60 ? '${m ~/ 60} h ${m % 60} min' : '$m min ${s.toString().padLeft(2, '0')} s';
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final summary = ref.watch(workoutSummaryProvider(sessionId));

    return Scaffold(
      body: SafeArea(
        child: summary.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(workoutSummaryProvider(sessionId))),
          data: (s) => _content(context, s),
        ),
      ),
    );
  }

  Widget _content(BuildContext context, FinishedSummary s) {
    return ListView(padding: const EdgeInsets.fromLTRB(20, 24, 20, 24), children: [
      const Icon(Icons.emoji_events, size: 56, color: IpColors.neon),
      const SizedBox(height: 10),
      Center(child: Text('Workout complete', style: IpText.display(24, color: IpColors.ink))),
      const SizedBox(height: 4),
      Center(child: Text(s.title, style: IpText.body(14, color: IpColors.muted))),
      const SizedBox(height: 20),
      GlassCard(
        neon: true,
        child: Row(children: [
          Expanded(child: _Figure(label: 'Time', value: _duration(s.activeSeconds))),
          Expanded(child: _Figure(label: 'Sets', value: '${s.setsCompleted}')),
          Expanded(child: _Figure(label: 'XP', value: '+${s.xpEarned}')),
        ]),
      ),
      if (s.levelUp) ...[const SizedBox(height: 14), GlassCard(neon: true, child: Center(child: Text('LEVEL UP! You are level ${s.level}', style: IpText.display(16, color: IpColors.neon))))],
      const SizedBox(height: 14),
      GlassCard(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('SUMMARY', style: IpText.eyebrow(color: IpColors.neon)),
          const SizedBox(height: 10),
          _line('Exercises done', '${s.exercisesDone} of ${s.exercisesTotal}'),
          if (s.volumeKg != null) _line('Volume', '${s.volumeKg!.round()} kg'),
          _line('Streak', '${s.streak} ${s.streak == 1 ? 'day' : 'days'}'),
        ]),
      ),
      if (s.personalRecords.isNotEmpty) ...[
        const SizedBox(height: 14),
        GlassCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('PERSONAL RECORDS', style: IpText.eyebrow(color: IpColors.neon)),
            const SizedBox(height: 10),
            for (final pr in s.personalRecords) Padding(padding: const EdgeInsets.only(bottom: 6), child: Row(children: [const StatusChip('PR', tone: Tone.good), const SizedBox(width: 10), Expanded(child: Text(pr, style: IpText.body(14, color: IpColors.ink)))])),
          ]),
        ),
      ],
      if (s.xpBreakdown.isNotEmpty) ...[
        const SizedBox(height: 14),
        GlassCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('XP EARNED', style: IpText.eyebrow(color: IpColors.neon)),
            const SizedBox(height: 10),
            for (final (label, amount) in s.xpBreakdown) _line(label, '+$amount'),
          ]),
        ),
      ],
      if (s.badges.isNotEmpty) ...[
        const SizedBox(height: 14),
        GlassCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('NEW BADGES', style: IpText.eyebrow(color: IpColors.neon)),
            const SizedBox(height: 10),
            Wrap(spacing: 8, runSpacing: 8, children: [for (final b in s.badges) StatusChip(b, tone: Tone.good, icon: Icons.military_tech_outlined)]),
          ]),
        ),
      ],
      const SizedBox(height: 24),
      NeonButton(label: 'Done', size: NeonButtonSize.large, expand: true, onPressed: () => context.go(Routes.home)),
    ]);
  }

  Widget _line(String label, String value) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Row(children: [Text(label, style: IpText.body(14, color: IpColors.muted)), const Spacer(), Text(value, style: IpText.body(14, weight: FontWeight.w600, color: IpColors.ink))]),
      );
}

class _Figure extends StatelessWidget {
  const _Figure({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Column(children: [
        Text(label.toUpperCase(), style: IpText.body(11.5, weight: FontWeight.w600, color: IpColors.muted).copyWith(letterSpacing: 1)),
        const SizedBox(height: 4),
        FittedBox(fit: BoxFit.scaleDown, child: Text(value, style: IpText.number(26, color: IpColors.neon))),
      ]);
}
