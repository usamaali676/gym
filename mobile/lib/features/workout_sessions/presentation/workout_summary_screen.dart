import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/storage/cache_provider.dart';
import '../../../core/sync/offline_queue.dart';
import '../../../core/sync/pending_op.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/state_views.dart';
import '../../../core/widgets/status_chip.dart';
import '../data/session_repository.dart';
import '../../sync/presentation/sync_widgets.dart';
import '../domain/workout_session.dart';
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
    // A workout finished without a connection has no results yet: the server works out XP, records and the streak.
    final queued = ref.watch(offlineQueueProvider).forSession(sessionId);
    final finish = queued.where((o) => o.type == OpTypes.workoutFinish).firstOrNull;
    if (finish != null) return _PendingSummary(sessionId: sessionId, finish: finish, queued: queued);

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

/// Shown after finishing a workout without a connection. Only what the phone itself knows is shown, and it says so.
class _PendingSummary extends ConsumerWidget {
  const _PendingSummary({required this.sessionId, required this.finish, required this.queued});

  final int sessionId;
  final PendingOp finish;
  final List<PendingOp> queued;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cached = ref.read(jsonCacheProvider).read('session.$sessionId.data')?.data;
    WorkoutSession? session;
    try {
      session = cached == null ? null : WorkoutSession.fromJson(cached);
    } catch (_) {}
    final queuedSets = queued.where((o) => o.type == OpTypes.setLog).length;
    final sets = withQueuedChanges(session ?? _empty, queued).setsLogged;
    final seconds = (finish.payload['active_seconds'] as num?)?.toInt() ?? 0;
    final attention = queued.any((o) => o.state != OpState.queued);

    return Scaffold(
      body: SafeArea(
        child: Column(children: [
          const SyncStatusStrip(),
          Expanded(
            child: ListView(padding: const EdgeInsets.fromLTRB(20, 24, 20, 24), children: [
              const Icon(Icons.cloud_off_outlined, size: 56, color: IpColors.warn),
              const SizedBox(height: 10),
              Center(child: Text('Workout finished', style: IpText.display(24, color: IpColors.ink))),
              const SizedBox(height: 4),
              Center(child: Text(session?.title ?? 'Workout', style: IpText.body(14, color: IpColors.muted))),
              const SizedBox(height: 20),
              GlassCard(
                neon: true,
                child: Row(children: [
                  Expanded(child: _Figure(label: 'Time', value: WorkoutSummaryScreen._duration(seconds))),
                  Expanded(child: _Figure(label: 'Sets', value: '$sets')),
                ]),
              ),
              const SizedBox(height: 14),
              GlassCard(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(attention ? 'NEEDS YOUR ATTENTION' : 'SAVED ON THIS PHONE', style: IpText.eyebrow(color: IpColors.warn)),
                  const SizedBox(height: 8),
                  Text(
                    attention
                        ? 'Some of this workout was not accepted by the server. Open Sync to see what and decide what to do.'
                        : 'Your workout is safe here${queuedSets > 0 ? ' ($queuedSets ${queuedSets == 1 ? 'set' : 'sets'} waiting)' : ''}. It is sent as soon as the phone reaches the server. XP, personal records and your streak are worked out there, so they appear in this summary once it has synced.',
                    style: IpText.body(13.5, color: IpColors.muted, height: 1.45),
                  ),
                  const SizedBox(height: 14),
                  NeonButton(label: 'Sync now', icon: Icons.sync, variant: NeonButtonVariant.ghost, expand: true, onPressed: ref.read(offlineQueueProvider.notifier).flush),
                ]),
              ),
              const SizedBox(height: 24),
              NeonButton(label: 'Done', size: NeonButtonSize.large, expand: true, onPressed: () => context.go(Routes.home)),
            ]),
          ),
        ]),
      ),
    );
  }

  static final _empty = WorkoutSession(id: 0, status: 'completed', title: 'Workout', startedAt: DateTime.fromMillisecondsSinceEpoch(0), serverTime: DateTime.fromMillisecondsSinceEpoch(0), exercises: const [], clientState: null, soundOn: false);
}
