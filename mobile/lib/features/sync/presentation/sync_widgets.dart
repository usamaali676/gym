import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/sync/offline_queue.dart';
import '../../../core/sync/pending_op.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/status_chip.dart';

String _plural(int n, String one, String many) => n == 1 ? '1 $one' : '$n $many';

/// A thin strip that says whether the changes made on this phone have reached the server. Nothing is shown when all is
/// saved. Tapping it opens the sync screen.
class SyncStatusStrip extends ConsumerWidget {
  const SyncStatusStrip({super.key, this.compact = false});

  /// Smaller text for screens that are already busy (a workout in progress).
  final bool compact;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final q = ref.watch(offlineQueueProvider);
    if (q.isEmpty) return const SizedBox.shrink();

    final (text, tone, icon) = switch (q) {
      _ when q.needAttention > 0 => ('${_plural(q.needAttention, 'change needs', 'changes need')} your attention', Tone.warn, Icons.error_outline),
      _ when q.syncing => ('Sending your changes…', Tone.neutral, Icons.sync),
      _ => ('${_plural(q.waiting.length, 'change is', 'changes are')} saved on this phone and waiting to sync', Tone.warn, Icons.cloud_off_outlined),
    };

    return Semantics(
      liveRegion: true,
      button: true,
      label: text,
      child: InkWell(
        onTap: () => context.push(Routes.sync),
        child: Container(
          width: double.infinity,
          padding: EdgeInsets.symmetric(horizontal: 16, vertical: compact ? 5 : 8),
          color: tone.fill,
          child: Row(children: [
            Icon(icon, size: compact ? 14 : 16, color: tone.color),
            const SizedBox(width: 8),
            Expanded(child: Text(text, maxLines: 2, overflow: TextOverflow.ellipsis, style: IpText.body(compact ? 11.5 : 12.5, weight: FontWeight.w600, color: tone.color))),
            if (!compact && !q.syncing && q.waiting.isNotEmpty) TextButton(onPressed: () => ref.read(offlineQueueProvider.notifier).flush(), child: const Text('Sync now')),
          ]),
        ),
      ),
    );
  }
}

/// Everything that is waiting, refused or in conflict, with what the member can do about each.
class SyncScreen extends ConsumerWidget {
  const SyncScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final q = ref.watch(offlineQueueProvider);
    final queue = ref.read(offlineQueueProvider.notifier);
    final lastSynced = q.lastSyncedAt;

    return Scaffold(
      appBar: AppBar(title: const Text('Sync')),
      body: SafeArea(
        child: ListView(padding: const EdgeInsets.fromLTRB(20, 12, 20, 24), children: [
          GlassCard(
            neon: true,
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(q.isEmpty ? 'ALL SAVED' : 'ON THIS PHONE', style: IpText.eyebrow(color: IpColors.neon)),
              const SizedBox(height: 8),
              Text(
                q.isEmpty
                    ? 'Everything you did has reached the server.'
                    : 'Changes made without a connection are kept here and sent, in order, as soon as the phone can reach the server. Nothing is lost if you close the app.',
                style: IpText.body(13.5, color: IpColors.muted, height: 1.45),
              ),
              if (lastSynced != null) ...[
                const SizedBox(height: 8),
                Text('Last sent ${DateFormat.jm().format(lastSynced.toLocal())}', style: IpText.body(12.5, color: IpColors.muted)),
              ],
              if (q.problem != null) ...[
                const SizedBox(height: 8),
                Text(q.problem!, style: IpText.body(13, weight: FontWeight.w600, color: IpColors.warn)),
              ],
              if (q.waiting.isNotEmpty) ...[
                const SizedBox(height: 14),
                NeonButton(label: 'Sync now', icon: Icons.sync, expand: true, loading: q.syncing, onPressed: queue.flush),
              ],
            ]),
          ),
          const SizedBox(height: 14),
          for (final op in q.ops)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: _OpCard(op: op, queue: queue),
            ),
        ]),
      ),
    );
  }
}

class _OpCard extends StatelessWidget {
  const _OpCard({required this.op, required this.queue});

  final PendingOp op;
  final OfflineQueue queue;

  @override
  Widget build(BuildContext context) {
    final (label, tone) = switch (op.state) {
      OpState.queued => ('Waiting', Tone.neutral),
      OpState.conflict => ('Needs a decision', Tone.warn),
      OpState.rejected => ('Not accepted', Tone.bad),
    };
    return GlassCard(
      padding: const EdgeInsets.all(14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text(op.label, style: IpText.body(14.5, weight: FontWeight.w600, color: IpColors.ink))),
          const SizedBox(width: 8),
          StatusChip(label, tone: tone),
        ]),
        const SizedBox(height: 4),
        Text('Done ${DateFormat.MMMd().add_jm().format(op.createdAt.toLocal())}', style: IpText.body(12, color: IpColors.muted)),
        if (op.state == OpState.rejected) ...[
          const SizedBox(height: 8),
          Text(op.errorMessage ?? 'The server did not accept this change.', style: IpText.body(13, color: IpColors.muted, height: 1.4)),
          const SizedBox(height: 10),
          Align(alignment: Alignment.centerRight, child: NeonButton(label: 'Dismiss', variant: NeonButtonVariant.ghost, size: NeonButtonSize.small, onPressed: () => queue.discard(op.opId))),
        ],
        if (op.state == OpState.conflict) ...[
          const SizedBox(height: 8),
          Text(
            'This was changed somewhere else more recently. Choose which version to keep.${_serverSummary(op)}',
            style: IpText.body(13, color: IpColors.muted, height: 1.4),
          ),
          const SizedBox(height: 10),
          Wrap(alignment: WrapAlignment.end, spacing: 8, runSpacing: 8, children: [
            NeonButton(label: 'Keep server\'s', variant: NeonButtonVariant.ghost, size: NeonButtonSize.small, onPressed: () => queue.discard(op.opId)),
            NeonButton(label: 'Use mine', size: NeonButtonSize.small, onPressed: () => queue.keepMine(op.opId)),
          ]),
        ],
        if (op.state == OpState.queued && op.attempts > 0) ...[
          const SizedBox(height: 6),
          Text('Tried ${_plural(op.attempts, 'time', 'times')} so far', style: IpText.body(12, color: IpColors.muted)),
        ],
      ]),
    );
  }

  static String _serverSummary(PendingOp op) {
    final s = op.serverCopy;
    if (s == null || s.isEmpty) return '';
    final parts = <String>[
      if (s['weight_kg'] != null) '${s['weight_kg']} kg',
      if (s['reps'] != null) '${s['reps']} reps',
      if (s['body_fat_pct'] != null) '${s['body_fat_pct']} % body fat',
    ];
    return parts.isEmpty ? '' : ' The server has: ${parts.join(', ')}.';
  }
}
