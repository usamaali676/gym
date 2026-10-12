import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/api/api_error.dart';
import '../../../core/widgets/error_banner.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/loaded_view.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/neon_progress.dart';
import '../../../core/widgets/neon_text_field.dart';
import '../../../core/widgets/status_chip.dart';
import '../domain/progress.dart';
import 'charts.dart';
import 'progress_providers.dart';

/// Progress tab: trends and records (analytics), body measurements, and badges.
class ProgressScreen extends ConsumerStatefulWidget {
  const ProgressScreen({super.key});

  @override
  ConsumerState<ProgressScreen> createState() => _ProgressScreenState();
}

class _ProgressScreenState extends ConsumerState<ProgressScreen> {
  int _tab = 0;

  @override
  Widget build(BuildContext context) {
    const labels = ['Overview', 'Body', 'Badges'];
    return SafeArea(
      bottom: false,
      child: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('PROGRESS', style: IpText.eyebrow(color: IpColors.neon)),
            const SizedBox(height: 10),
            Row(children: [
              for (var i = 0; i < labels.length; i++)
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: Semantics(
                    button: true,
                    selected: i == _tab,
                    label: labels[i],
                    excludeSemantics: true,
                    child: GestureDetector(
                      onTap: () => setState(() => _tab = i),
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 160),
                        constraints: const BoxConstraints(minHeight: 40),
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(999),
                          color: i == _tab ? const Color.fromRGBO(57, 255, 20, 0.12) : Colors.transparent,
                          border: Border.all(color: i == _tab ? IpColors.neon : IpColors.line),
                        ),
                        child: Text(labels[i], style: IpText.body(13.5, weight: FontWeight.w600, color: i == _tab ? IpColors.neon : IpColors.muted)),
                      ),
                    ),
                  ),
                ),
            ]),
          ]),
        ),
        Expanded(child: switch (_tab) { 0 => const _Overview(), 1 => const _Body(), _ => const _Badges() }),
      ]),
    );
  }
}

String _kg(double v) => '${v.toStringAsFixed(1)} kg';

// -------------------------------------------------------------------------------------------------- overview

class _Overview extends ConsumerWidget {
  const _Overview();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final summary = ref.watch(progressSummaryProvider);

    Future<void> refresh() async {
      ref.invalidate(progressSummaryProvider);
      ref.invalidate(recordsProvider);
      try {
        await ref.read(progressSummaryProvider.future);
      } catch (_) {}
    }

    return LoadedView<ProgressSummary>(
      value: summary,
      onRefresh: refresh,
      padding: const EdgeInsets.fromLTRB(20, 4, 20, 24),
      children: (context, s) => [
        GlassCard(
          neon: true,
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('WEIGHT', style: IpText.eyebrow(color: IpColors.neon)),
            const SizedBox(height: 10),
            if (s.weightPoints.length >= 2)
              TrendChart(
                points: [for (final p in s.weightPoints) (p.date, p.kg)],
                unit: 'kg',
                semanticLabel: 'Weight trend: ${_kg(s.weightPoints.first.kg)} on ${DateFormat.MMMd().format(s.weightPoints.first.date)} to ${_kg(s.weightPoints.last.kg)} on ${DateFormat.MMMd().format(s.weightPoints.last.date)}',
              )
            else
              Text(s.weightPoints.isEmpty ? 'Log your weight in the Body tab to see a trend.' : 'One weigh-in so far. Log another to draw the trend.', style: IpText.body(13.5, color: IpColors.muted)),
          ]),
        ),
        const SizedBox(height: 14),
        GlassCard(
          child: Column(children: [
            _Stat(label: 'Latest weight', value: s.latest?.weightKg == null ? '—' : _kg(s.latest!.weightKg!)),
            _Stat(label: 'Last 30 days', value: s.change30dKg == null ? '—' : '${s.change30dKg! > 0 ? '+' : ''}${s.change30dKg!.toStringAsFixed(1)} kg'),
            _Stat(label: 'Trend per week', value: s.trendPerWeekKg == null ? '—' : '${s.trendPerWeekKg! > 0 ? '+' : ''}${s.trendPerWeekKg!.toStringAsFixed(2)} kg'),
            if (s.targetWeightKg != null) _Stat(label: 'Target weight', value: _kg(s.targetWeightKg!)),
            _Stat(label: 'BMI', value: s.bmi == null ? '—' : '${s.bmi!.toStringAsFixed(1)}${s.bmiCategory == null ? '' : ' · ${s.bmiCategory}'}', note: 'A rough screening number, not a diagnosis.'),
            if (s.bodyFatPct != null) _Stat(label: s.bodyFatSource == 'logged' ? 'Body fat' : 'Body fat (estimate)', value: '${s.bodyFatPct!.toStringAsFixed(1)} %'),
            if (s.leanKg != null) _Stat(label: 'Lean mass', value: _kg(s.leanKg!)),
          ]),
        ),
        const SizedBox(height: 18),
        const _Records(),
      ],
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value, this.note});
  final String label;
  final String value;
  final String? note;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Text(label, style: IpText.body(14, color: IpColors.muted)),
            const SizedBox(width: 12),
            Expanded(child: Text(value, textAlign: TextAlign.end, style: IpText.body(14.5, weight: FontWeight.w600, color: IpColors.ink))),
          ]),
          if (note != null) Text(note!, style: IpText.body(11.5, color: IpColors.muted)),
        ]),
      );
}

class _Records extends ConsumerWidget {
  const _Records();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final records = ref.watch(recordsProvider).asData?.value.data ?? const <PersonalRecord>[];
    if (records.isEmpty) {
      return GlassCard(child: Text('Personal records appear here after your first workout.', style: IpText.body(13.5, color: IpColors.muted)));
    }
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Padding(padding: const EdgeInsets.only(bottom: 10), child: Text('PERSONAL RECORDS', style: IpText.eyebrow(color: IpColors.neon))),
      for (final r in records.take(15))
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: GlassCard(
            padding: const EdgeInsets.all(14),
            onTap: () => context.push('${Routes.strength}/${r.slug}'),
            semanticLabel: 'Personal record, ${r.exerciseName}',
            child: Row(children: [
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(r.exerciseName, style: IpText.body(15, weight: FontWeight.w600, color: IpColors.ink)),
                  const SizedBox(height: 4),
                  Text(
                    [if (r.bestWeightKg != null) 'Best ${r.bestWeightKg!.toStringAsFixed(1)} kg', if (r.est1rm != null) 'Est. 1RM ${r.est1rm!.toStringAsFixed(1)} kg'].join(' · '),
                    style: IpText.body(12.5, color: IpColors.muted),
                  ),
                ]),
              ),
              const Icon(Icons.chevron_right, color: IpColors.muted),
            ]),
          ),
        ),
    ]);
  }
}

// ------------------------------------------------------------------------------------------------------ body

class _Body extends ConsumerWidget {
  const _Body();

  Future<void> _delete(BuildContext context, WidgetRef ref, Measurement m) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Delete this entry?'),
        content: Text('The measurement from ${DateFormat.yMMMd().format(m.date)} will be removed.', style: IpText.body(14, color: IpColors.muted)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          NeonButton(label: 'Delete', variant: NeonButtonVariant.danger, size: NeonButtonSize.small, onPressed: () => Navigator.pop(context, true)),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await ref.read(progressRepositoryProvider).deleteMeasurement(m.id);
      invalidateProgress(ref);
    } on ApiError catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final list = ref.watch(measurementsProvider);

    Future<void> refresh() async {
      ref.invalidate(measurementsProvider);
      try {
        await ref.read(measurementsProvider.future);
      } catch (_) {}
    }

    return LoadedView<List<Measurement>>(
      value: list,
      onRefresh: refresh,
      padding: const EdgeInsets.fromLTRB(20, 4, 20, 24),
      children: (context, items) => [
        NeonButton(label: 'Log measurement', icon: Icons.add, expand: true, onPressed: () => showMeasurementSheet(context)),
        const SizedBox(height: 14),
        if (items.isEmpty) GlassCard(child: Text('No measurements yet. Weigh in and log it to start your trend.', style: IpText.body(13.5, color: IpColors.muted))),
        for (final m in items)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: GlassCard(
              padding: const EdgeInsets.fromLTRB(16, 12, 6, 12),
              child: Row(children: [
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(DateFormat.yMMMd().format(m.date), style: IpText.body(13, weight: FontWeight.w600, color: IpColors.muted)),
                    const SizedBox(height: 4),
                    Text(
                      [if (m.weightKg != null) _kg(m.weightKg!), if (m.bodyFatPct != null) '${m.bodyFatPct!.toStringAsFixed(1)} % fat', if (m.waistCm != null) 'waist ${m.waistCm!.toStringAsFixed(0)} cm']
                          .map((part) => part.replaceAll(' ', '\u00A0')) // a long line breaks between the figures, never inside one
                          .join(' · '),
                      style: IpText.body(15, weight: FontWeight.w600, color: IpColors.ink),
                    ),
                    if (m.notes != null && m.notes!.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text(m.notes!, style: IpText.body(12.5, color: IpColors.muted))),
                  ]),
                ),
                IconButton(tooltip: 'Delete entry from ${DateFormat.yMMMd().format(m.date)}', onPressed: () => _delete(context, ref, m), icon: const Icon(Icons.delete_outline, size: 20, color: IpColors.muted)),
              ]),
            ),
          ),
      ],
    );
  }
}

/// A form for one body measurement. Validation is the server's; the app only checks that a number is a number.
Future<void> showMeasurementSheet(BuildContext context) => showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (context) => const _MeasurementForm(),
    );

class _MeasurementForm extends ConsumerStatefulWidget {
  const _MeasurementForm();

  @override
  ConsumerState<_MeasurementForm> createState() => _MeasurementFormState();
}

class _MeasurementFormState extends ConsumerState<_MeasurementForm> {
  final _weight = TextEditingController();
  final _fat = TextEditingController();
  final _waist = TextEditingController();
  final _notes = TextEditingController();
  bool _busy = false;
  String? _banner;
  Map<String, String> _server = const {};
  String? _local;

  @override
  void dispose() {
    for (final c in [_weight, _fat, _waist, _notes]) {
      c.dispose();
    }
    super.dispose();
  }

  double? _num(TextEditingController c) => double.tryParse(c.text.trim().replaceAll(',', '.'));

  Future<void> _save() async {
    final weight = _num(_weight);
    final fat = _num(_fat);
    final waist = _num(_waist);
    setState(() {
      _banner = null;
      _server = const {};
      _local = null;
    });
    if ((_weight.text.trim().isNotEmpty && weight == null) || (_fat.text.trim().isNotEmpty && fat == null) || (_waist.text.trim().isNotEmpty && waist == null)) {
      setState(() => _local = 'Use numbers only.');
      return;
    }
    if (weight == null && fat == null && waist == null) {
      setState(() => _local = 'Enter at least a weight, body fat or waist.');
      return;
    }
    setState(() => _busy = true);
    try {
      await ref.read(progressRepositoryProvider).saveMeasurement(date: DateTime.now(), weightKg: weight, bodyFatPct: fat, waistCm: waist, notes: _notes.text.trim().isEmpty ? null : _notes.text.trim());
      invalidateProgress(ref);
      if (mounted) Navigator.pop(context);
    } on ApiError catch (e) {
      if (mounted) {
        setState(() {
          _server = e.fieldErrors;
          _banner = e.fieldErrors.isEmpty ? e.message : null;
        });
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text('Log measurement', style: IpText.display(18, color: IpColors.ink)),
          const SizedBox(height: 4),
          Text('Today, ${DateFormat.yMMMd().format(DateTime.now())}. Saving again today replaces this entry.', style: IpText.body(12.5, color: IpColors.muted)),
          const SizedBox(height: 16),
          if (_banner != null || _local != null) ...[MessageBanner(_banner ?? _local!), const SizedBox(height: 12)],
          NeonTextField(label: 'Weight (kg)', controller: _weight, keyboardType: const TextInputType.numberWithOptions(decimal: true), textInputAction: TextInputAction.next, errorText: _server['weight_kg']),
          const SizedBox(height: 12),
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(child: NeonTextField(label: 'Body fat (%)', controller: _fat, keyboardType: const TextInputType.numberWithOptions(decimal: true), textInputAction: TextInputAction.next, errorText: _server['body_fat_pct'])),
            const SizedBox(width: 12),
            Expanded(child: NeonTextField(label: 'Waist (cm)', controller: _waist, keyboardType: const TextInputType.numberWithOptions(decimal: true), textInputAction: TextInputAction.next, errorText: _server['waist_cm'])),
          ]),
          const SizedBox(height: 12),
          NeonTextField(label: 'Notes', controller: _notes, textInputAction: TextInputAction.done, maxLength: 500, errorText: _server['notes']),
          const SizedBox(height: 16),
          NeonButton(label: 'Save', size: NeonButtonSize.large, expand: true, loading: _busy, onPressed: _save),
        ]),
      ),
    );
  }
}

// ---------------------------------------------------------------------------------------------------- badges

class _Badges extends ConsumerWidget {
  const _Badges();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(achievementsProvider);

    Future<void> refresh() async {
      ref.invalidate(achievementsProvider);
      try {
        await ref.read(achievementsProvider.future);
      } catch (_) {}
    }

    return LoadedView<Achievements>(
      value: value,
      onRefresh: refresh,
      padding: const EdgeInsets.fromLTRB(20, 4, 20, 24),
      children: (context, a) {
        final earned = a.badges.where((b) => b.earned).toList();
        final open = a.badges.where((b) => !b.earned).toList()..sort((x, y) => (y.progress?.pct ?? 0).compareTo(x.progress?.pct ?? 0));
        return [
          GlassCard(
            neon: true,
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: _Figure(label: 'Level', value: '${a.level}')),
                Expanded(child: _Figure(label: 'Streak', value: '${a.streak}')),
                Expanded(child: _Figure(label: 'Badges', value: '${a.earnedCount}')),
              ]),
              const SizedBox(height: 14),
              NeonProgress(value: a.xpProgress, semanticLabel: 'Progress to level ${a.level + 1}'),
              const SizedBox(height: 6),
              Text('${a.xpInto} / ${a.xpNeeded} XP to level ${a.level + 1} · best streak ${a.longestStreak}', style: IpText.body(12, color: IpColors.muted)),
            ]),
          ),
          if (earned.isNotEmpty) ...[
            const SizedBox(height: 18),
            Text('EARNED', style: IpText.eyebrow(color: IpColors.neon)),
            const SizedBox(height: 10),
            for (final b in earned) Padding(padding: const EdgeInsets.only(bottom: 10), child: _BadgeCard(badge: b)),
          ],
          const SizedBox(height: 18),
          Text('IN PROGRESS', style: IpText.eyebrow(color: IpColors.neon)),
          const SizedBox(height: 10),
          for (final b in open) Padding(padding: const EdgeInsets.only(bottom: 10), child: _BadgeCard(badge: b)),
        ];
      },
    );
  }
}

class _Figure extends StatelessWidget {
  const _Figure({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Column(children: [
        Text(label.toUpperCase(), style: IpText.body(11.5, weight: FontWeight.w600, color: IpColors.muted).copyWith(letterSpacing: 1)),
        const SizedBox(height: 4),
        FittedBox(fit: BoxFit.scaleDown, child: Text(value, style: IpText.number(32, color: IpColors.neon))),
      ]);
}

class _BadgeCard extends StatelessWidget {
  const _BadgeCard({required this.badge});
  final BadgeInfo badge;

  @override
  Widget build(BuildContext context) {
    final p = badge.progress;
    return Semantics(
      container: true,
      label: '${badge.name}, ${badge.earned ? 'earned' : 'not yet earned'}. ${badge.description}',
      excludeSemantics: true,
      child: GlassCard(
        neon: badge.earned,
        padding: const EdgeInsets.all(14),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(badge.earned ? Icons.military_tech : Icons.military_tech_outlined, size: 30, color: badge.earned ? IpColors.neon : IpColors.muted),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text(badge.name, style: IpText.body(15, weight: FontWeight.w600, color: badge.earned ? IpColors.ink : IpColors.muted))),
                StatusChip(badge.tier[0].toUpperCase() + badge.tier.substring(1)),
              ]),
              const SizedBox(height: 2),
              Text(badge.description, style: IpText.body(12.5, color: IpColors.muted)),
              if (!badge.earned && p != null) ...[
                const SizedBox(height: 8),
                NeonProgress(value: p.pct, height: 5, semanticLabel: 'Progress'),
                const SizedBox(height: 4),
                Text('${p.value.round()} / ${p.target.round()}', style: IpText.body(11.5, color: IpColors.muted)),
              ],
              if (badge.earned && badge.awardedAt != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text('Earned ${DateFormat.yMMMd().format(badge.awardedAt!)} · +${badge.xpReward} XP', style: IpText.body(11.5, color: IpColors.neon))),
            ]),
          ),
        ]),
      ),
    );
  }
}
