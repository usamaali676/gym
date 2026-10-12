import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/policy/app_config.dart';
import '../../../core/policy/policy_controller.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/spread_row.dart';
import '../../../core/widgets/loaded_view.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/neon_progress.dart';
import '../../../core/widgets/status_chip.dart';
import '../../authentication/presentation/auth_controller.dart';
import '../../membership/presentation/locked_view.dart';
import '../../membership/presentation/membership_providers.dart';
import '../../workout_sessions/presentation/workout_actions.dart';
import '../domain/dashboard.dart';
import 'dashboard_providers.dart';

/// Home tab: today's workout, the week, streak and XP, and a nutrition glance when the plan includes it.
class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  static String _greeting(String period) => switch (period) {
        'late_night' => 'STILL UP?',
        'afternoon' => 'GOOD AFTERNOON',
        'evening' => 'GOOD EVENING',
        _ => 'GOOD MORNING',
      };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    if (auth is! SignedIn) return const SizedBox.shrink();
    final dashboard = ref.watch(dashboardProvider);
    final policy = ref.watch(policyControllerProvider);

    Future<void> refresh() async {
      ref.invalidate(membershipProvider);
      ref.invalidate(dashboardProvider);
      await ref.read(policyControllerProvider.notifier).refresh();
      try {
        await ref.read(dashboardProvider.future);
      } catch (_) {
        // The error is shown by the view itself.
      }
    }

    return SafeArea(
      bottom: false,
      child: LoadedView<Dashboard>(
        value: dashboard,
        onRefresh: refresh,
        header: Padding(
          padding: const EdgeInsets.only(bottom: 18),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(_greeting(dashboard.asData?.value.data.greetingPeriod ?? 'morning'), style: IpText.eyebrow(color: IpColors.neon)),
            const SizedBox(height: 6),
            Text(auth.user.name.isEmpty ? 'Athlete' : auth.user.name, style: IpText.display(26, color: IpColors.ink)),
          ]),
        ),
        children: (context, d) => [
          if (policy.updateRecommended) ...[_UpdateCard(config: policy.config!), const SizedBox(height: 14)],
          _TodayCard(d: d),
          const SizedBox(height: 14),
          if (d.entitlements.allows('workouts')) ...[_WeekCard(d: d), const SizedBox(height: 14)],
          _ProgressCard(d: d),
          if (d.nutrition != null) ...[const SizedBox(height: 14), _NutritionCard(n: d.nutrition!)],
          if (d.quote != null) ...[
            const SizedBox(height: 18),
            Text('“${d.quote}”', textAlign: TextAlign.center, style: IpText.body(13, color: IpColors.muted).copyWith(fontStyle: FontStyle.italic)),
          ],
        ],
      ),
    );
  }
}

class _TodayCard extends ConsumerWidget {
  const _TodayCard({required this.d});
  final Dashboard d;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final w = d.workout;

    if (!d.entitlements.allows('workouts')) return const LockedView(entitlementKey: 'workouts', compact: true);

    final (chipText, tone) = switch (w.status) {
      'completed' => ('Done', Tone.good),
      'in_progress' => ('In progress', Tone.warn),
      'skipped' => ('Skipped', Tone.neutral),
      'missed' => ('Missed', Tone.bad),
      'rest' => ('Rest day', Tone.neutral),
      _ => ('Today', Tone.good),
    };

    return GlassCard(
      neon: true,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SpreadRow(start: Text("TODAY'S WORKOUT", style: IpText.eyebrow(color: IpColors.neon)), end: StatusChip(chipText, tone: tone)),
        const SizedBox(height: 12),
        if (!w.hasDay)
          Text('No programme selected yet.', style: IpText.body(15, color: IpColors.muted))
        else ...[
          Text(w.title!, style: IpText.display(21, color: IpColors.ink)),
          if (w.focus != null && w.focus!.isNotEmpty) ...[const SizedBox(height: 4), Text(w.focus!, style: IpText.body(13.5, color: IpColors.muted))],
          const SizedBox(height: 10),
          if (w.isRest)
            Text('Recovery is part of the plan. Walk, stretch, sleep well.', style: IpText.body(13.5, color: IpColors.muted))
          else
            Row(children: [
              const Icon(Icons.schedule, size: 16, color: IpColors.muted),
              const SizedBox(width: 6),
              Text('About ${w.estimatedMinutes} min', style: IpText.body(13.5, color: IpColors.muted)),
            ]),
          if (!w.isRest && !w.isCompleted && w.status != 'skipped') ...[
            const SizedBox(height: 14),
            NeonButton(
              label: w.openSessionId != null ? 'Resume workout' : 'Start workout',
              size: NeonButtonSize.large,
              expand: true,
              icon: Icons.play_arrow,
              onPressed: () => startOrResumeWorkout(context, ref, openSessionId: w.openSessionId), // no weekday: the server decides what "today" is
            ),
          ],
        ],
      ]),
    );
  }
}

class _WeekCard extends StatelessWidget {
  const _WeekCard({required this.d});
  final Dashboard d;

  static const _letters = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SpreadRow(
          start: Text('THIS WEEK', style: IpText.eyebrow(color: IpColors.neon)),
          end: Text('${d.weekDone} of ${d.weekTarget} sessions', style: IpText.body(13, weight: FontWeight.w600, color: IpColors.muted)),
        ),
        const SizedBox(height: 14),
        Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [for (final day in d.weekDays) _DayDot(day: day, letter: _letters[(day.weekday - 1) % 7])]),
      ]),
    );
  }
}

class _DayDot extends StatelessWidget {
  const _DayDot({required this.day, required this.letter});
  final WeekDay day;
  final String letter;

  @override
  Widget build(BuildContext context) {
    final (fill, border, icon, label) = switch (day.status) {
      'completed' => (IpColors.neon, IpColors.neon, Icons.check, 'done'),
      'in_progress' => (const Color.fromRGBO(255, 176, 32, 0.18), IpColors.warn, Icons.more_horiz, 'in progress'),
      'missed' => (const Color.fromRGBO(255, 59, 59, 0.12), const Color.fromRGBO(255, 59, 59, 0.5), Icons.close, 'missed'),
      'skipped' => (Colors.transparent, IpColors.line, Icons.remove, 'skipped'),
      'rest' => (Colors.transparent, IpColors.line, null, 'rest day'),
      _ => (day.isToday ? const Color.fromRGBO(57, 255, 20, 0.12) : Colors.transparent, day.isToday ? IpColors.neon : IpColors.line, null, day.isToday ? 'today' : 'upcoming'),
    };
    final fg = day.status == 'completed' ? const Color(0xFF041006) : IpColors.ink;

    return Semantics(
      label: '${const ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][(day.weekday - 1) % 7]}: $label',
      excludeSemantics: true,
      child: Column(children: [
        Text(letter, style: IpText.body(11.5, weight: FontWeight.w600, color: day.isToday ? IpColors.neon : IpColors.muted)),
        const SizedBox(height: 6),
        Container(
          width: 36,
          height: 36,
          decoration: BoxDecoration(color: fill, shape: BoxShape.circle, border: Border.all(color: border, width: day.isToday ? 1.8 : 1)),
          child: icon == null ? null : Icon(icon, size: 18, color: fg),
        ),
      ]),
    );
  }
}

class _ProgressCard extends StatelessWidget {
  const _ProgressCard({required this.d});
  final Dashboard d;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: _Stat(label: 'Level', value: '${d.level}')),
          Expanded(child: _Stat(label: 'XP', value: '${d.xpTotal}')),
          Expanded(child: _Stat(label: 'Day streak', value: '${d.streak}')),
        ]),
        const SizedBox(height: 14),
        NeonProgress(value: d.xpProgress, semanticLabel: 'Progress to level ${d.level + 1}'),
        const SizedBox(height: 6),
        Text('${d.xpInto} / ${d.xpNeeded} XP to level ${d.level + 1}', style: IpText.body(12, color: IpColors.muted)),
      ]),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Semantics(
        container: true,
        label: '$label $value',
        excludeSemantics: true,
        child: Column(children: [
          Text(label.toUpperCase(), style: IpText.body(11.5, weight: FontWeight.w600, color: IpColors.muted).copyWith(letterSpacing: 1)),
          const SizedBox(height: 4),
          FittedBox(fit: BoxFit.scaleDown, child: Text(value, style: IpText.number(34, color: IpColors.neon))),
        ]),
      );
}

class _NutritionCard extends StatelessWidget {
  const _NutritionCard({required this.n});
  final NutritionGlance n;

  @override
  Widget build(BuildContext context) {
    final pct = n.caloriesTarget <= 0 ? 0.0 : n.caloriesEaten / n.caloriesTarget;
    final over = pct > 1.05;
    return GlassCard(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Text('TODAY’S NUTRITION', style: IpText.eyebrow(color: IpColors.neon)),
          const Spacer(),
          if (over) const StatusChip('Over target', tone: Tone.warn),
        ]),
        const SizedBox(height: 12),
        Row(crossAxisAlignment: CrossAxisAlignment.baseline, textBaseline: TextBaseline.alphabetic, children: [
          Text('${n.caloriesEaten.round()}', style: IpText.number(30, color: IpColors.ink)),
          Text(' / ${n.caloriesTarget.round()} kcal', style: IpText.body(13, color: IpColors.muted)),
        ]),
        const SizedBox(height: 8),
        NeonProgress(value: pct, color: over ? IpColors.warn : IpColors.neon, semanticLabel: 'Calories eaten'),
        const SizedBox(height: 8),
        Text('Protein ${n.proteinEaten.round()} / ${n.proteinTarget.round()} g', style: IpText.body(12.5, color: IpColors.muted)),
      ]),
    );
  }
}

class _UpdateCard extends StatelessWidget {
  const _UpdateCard({required this.config});
  final AppConfig config;

  @override
  Widget build(BuildContext context) {
    final url = config.storeUrl;
    return GlassCard(
      child: Row(children: [
        const Icon(Icons.system_update_alt, color: IpColors.warn),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('Update available', style: IpText.display(14, color: IpColors.ink)),
            const SizedBox(height: 4),
            Text(config.releaseNotes ?? 'A newer version of IronPulse is out.', style: IpText.body(12.5, color: IpColors.muted)),
          ]),
        ),
        if (url != null) ...[
          const SizedBox(width: 8),
          NeonButton(label: 'Update', size: NeonButtonSize.small, onPressed: () => launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication)),
        ],
      ]),
    );
  }
}
