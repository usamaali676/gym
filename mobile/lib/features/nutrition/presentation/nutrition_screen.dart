import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/api/api_error.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/spread_row.dart';
import '../../../core/widgets/loaded_view.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/neon_progress.dart';
import '../../../core/widgets/status_chip.dart';
import '../../workout_programs/domain/plan.dart' show dateParam;
import '../domain/nutrition.dart';
import 'nutrition_providers.dart';

/// Fuel tab: the day's calories and macros against the targets, the meals eaten, and a way to add food.
class NutritionScreen extends ConsumerWidget {
  const NutritionScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final day = ref.watch(nutritionDayProvider);

    Future<void> refresh() async {
      ref.invalidate(nutritionDayProvider);
      try {
        await ref.read(nutritionDayProvider.future);
      } catch (_) {}
    }

    return SafeArea(
      bottom: false,
      child: LoadedView<NutritionDay>(
        value: day,
        onRefresh: refresh,
        header: Padding(
          padding: const EdgeInsets.only(bottom: 14),
          child: Row(children: [
            Expanded(child: Text('FUEL', style: IpText.eyebrow(color: IpColors.neon))),
            TextButton.icon(
              onPressed: () => context.push('${Routes.foodSearch}?calc=1'),
              icon: const Icon(Icons.calculate_outlined, size: 18, color: IpColors.neon),
              label: Text('Calculator', style: IpText.body(13, weight: FontWeight.w600, color: IpColors.neon)),
            ),
          ]),
        ),
        children: (context, d) => [
          _DayNav(day: d),
          const SizedBox(height: 12),
          _Summary(day: d),
          const SizedBox(height: 14),
          for (final meal in d.meals) Padding(padding: const EdgeInsets.only(bottom: 12), child: _MealCard(day: d, meal: meal)),
          if (d.ideas.isNotEmpty) ...[
            const SizedBox(height: 4),
            _Ideas(day: d),
          ],
        ],
      ),
    );
  }
}

class _DayNav extends ConsumerWidget {
  const _DayNav({required this.day});
  final NutritionDay day;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final date = ref.read(nutritionDateProvider.notifier);
    final isToday = ref.watch(nutritionDateProvider) == null;
    return GlassCard(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
      child: Row(children: [
        IconButton(tooltip: 'Previous day', onPressed: () => date.show(day.date.subtract(const Duration(days: 1))), icon: const Icon(Icons.chevron_left)),
        Expanded(
          child: Column(children: [
            Text(isToday ? 'TODAY' : DateFormat.EEEE().format(day.date).toUpperCase(), style: IpText.eyebrow(color: IpColors.neon)),
            Text(DateFormat.yMMMd().format(day.date), style: IpText.body(13, color: IpColors.muted)),
          ]),
        ),
        IconButton(tooltip: 'Next day', onPressed: isToday ? null : () => date.show(day.date.add(const Duration(days: 1))), icon: const Icon(Icons.chevron_right)),
      ]),
    );
  }
}

class _Summary extends StatelessWidget {
  const _Summary({required this.day});
  final NutritionDay day;

  @override
  Widget build(BuildContext context) {
    final t = day.targets;
    final eaten = day.totals;
    if (t == null) {
      return GlassCard(
        neon: true,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${eaten.calories.round()} kcal eaten', style: IpText.display(20, color: IpColors.ink)),
          const SizedBox(height: 8),
          Text('Your daily targets could not be built yet. Complete your body profile in Account to get calorie and macro targets.', style: IpText.body(13.5, color: IpColors.muted)),
        ]),
      );
    }
    final over = eaten.calories > t.calories * 1.05;
    return GlassCard(
      neon: true,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SpreadRow(start: Text('CALORIES', style: IpText.eyebrow(color: IpColors.neon)), end: day.isTrainingDay ? const StatusChip('Training day') : const StatusChip('Rest day')),
        const SizedBox(height: 10),
        Wrap(crossAxisAlignment: WrapCrossAlignment.end, children: [
          Text('${eaten.calories.round()}', style: IpText.number(44, color: IpColors.ink)),
          Text(' / ${t.calories.round()} kcal', style: IpText.body(14, color: IpColors.muted)),
        ]),
        const SizedBox(height: 8),
        NeonProgress(value: t.calories == 0 ? 0 : eaten.calories / t.calories, color: over ? IpColors.warn : IpColors.neon, semanticLabel: 'Calories eaten'),
        const SizedBox(height: 6),
        Text(
          over ? '${(eaten.calories - t.calories).round()} kcal over your target' : '${(t.calories - eaten.calories).round()} kcal left',
          style: IpText.body(13, weight: FontWeight.w600, color: over ? IpColors.warn : IpColors.neon),
        ),
        const SizedBox(height: 16),
        _MacroRow(label: 'Protein', eaten: eaten.proteinG, target: t.proteinG),
        _MacroRow(label: 'Carbs', eaten: eaten.carbsG, target: t.carbsG),
        _MacroRow(label: 'Fat', eaten: eaten.fatG, target: t.fatG),
        _MacroRow(label: 'Fibre', eaten: eaten.fibreG, target: t.fibreG),
      ]),
    );
  }
}

class _MacroRow extends StatelessWidget {
  const _MacroRow({required this.label, required this.eaten, required this.target});
  final String label;
  final double eaten;
  final double target;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Column(children: [
          Row(children: [
            Text(label, style: IpText.body(13.5, color: IpColors.muted)),
            const Spacer(),
            Text('${eaten.round()} / ${target.round()} g', style: IpText.body(13.5, weight: FontWeight.w600, color: IpColors.ink)),
          ]),
          const SizedBox(height: 4),
          NeonProgress(value: target == 0 ? 0 : eaten / target, height: 5, semanticLabel: '$label eaten'),
        ]),
      );
}

class _MealCard extends ConsumerWidget {
  const _MealCard({required this.day, required this.meal});
  final NutritionDay day;
  final MealGroup meal;

  Future<void> _delete(BuildContext context, WidgetRef ref, FoodLogEntry e) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Remove this food?'),
        content: Text('${e.name} (${e.amountLabel}) will be removed from ${meal.label.toLowerCase()}.', style: IpText.body(14, color: IpColors.muted)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          NeonButton(label: 'Remove', variant: NeonButtonVariant.danger, size: NeonButtonSize.small, onPressed: () => Navigator.pop(context, true)),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await ref.read(nutritionRepositoryProvider).deleteLog(e.id);
      invalidateNutrition(ref);
    } on ApiError catch (err) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(err.message)));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final dateQuery = 'date=${dateParam(day.date)}';
    return GlassCard(
      padding: const EdgeInsets.fromLTRB(16, 14, 8, 8),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text(meal.label.toUpperCase(), style: IpText.eyebrow(color: IpColors.neon))),
          Text('${meal.kcal.round()} kcal · ${meal.proteinG.round()} g protein', style: IpText.body(12.5, color: IpColors.muted)),
          const SizedBox(width: 8),
        ]),
        const SizedBox(height: 6),
        for (final e in meal.entries)
          InkWell(
            borderRadius: BorderRadius.circular(8),
            onTap: () => context.push('${Routes.foodAmount}/${e.foodId}?meal=${meal.meal}&$dateQuery&log=${e.id}&q=${e.quantity}&unit=${e.unit}'),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: Row(children: [
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(e.name, style: IpText.body(14.5, weight: FontWeight.w600, color: IpColors.ink)),
                    Text('${e.amountLabel} · ${e.macros.proteinG.round()} g protein', style: IpText.body(12.5, color: IpColors.muted)),
                  ]),
                ),
                Text('${e.macros.calories.round()}', style: IpText.number(18, color: IpColors.ink)),
                IconButton(tooltip: 'Remove ${e.name}', onPressed: () => _delete(context, ref, e), icon: const Icon(Icons.delete_outline, size: 20, color: IpColors.muted)),
              ]),
            ),
          ),
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            onPressed: () => context.push('${Routes.foodSearch}?meal=${meal.meal}&$dateQuery'),
            icon: const Icon(Icons.add, size: 18, color: IpColors.neon),
            label: Text('Add food to ${meal.label.toLowerCase()}', style: IpText.body(13.5, weight: FontWeight.w600, color: IpColors.neon)),
          ),
        ),
      ]),
    );
  }
}

class _Ideas extends StatelessWidget {
  const _Ideas({required this.day});
  final NutritionDay day;

  @override
  Widget build(BuildContext context) => GlassCard(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('IDEAS TO HIT YOUR PROTEIN', style: IpText.eyebrow(color: IpColors.neon)),
          const SizedBox(height: 10),
          for (final i in day.ideas)
            InkWell(
              borderRadius: BorderRadius.circular(8),
              onTap: () => context.push('${Routes.foodAmount}/${i.foodId}?meal=snack&date=${dateParam(day.date)}'),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: Row(children: [
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(i.name, style: IpText.body(14, weight: FontWeight.w600, color: IpColors.ink)),
                      if (i.serving != null) Text(i.serving!, style: IpText.body(12.5, color: IpColors.muted)),
                    ]),
                  ),
                  Text('${i.proteinG.round()} g · ${i.kcal.round()} kcal', style: IpText.body(12.5, color: IpColors.muted)),
                ]),
              ),
            ),
        ]),
      );
}
