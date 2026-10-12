import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/api/api_error.dart';
import '../../../core/widgets/choice_tiles.dart';
import '../../../core/widgets/error_banner.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/number_stepper.dart';
import '../../../core/widgets/state_views.dart';
import '../domain/nutrition.dart';
import 'nutrition_providers.dart';

final foodProvider = FutureProvider.family<Food, int>((ref, id) => ref.watch(nutritionRepositoryProvider).food(id));
final favouriteIdsProvider = FutureProvider<Set<int>>((ref) async => {for (final f in await ref.watch(nutritionRepositoryProvider).favorites()) f.id});

/// How much of a food. The numbers shown are the server's: every change asks `POST /nutrition/calculate`, and the
/// breakdown line ("150 g × 20 g per 100 g = 30 g") is built from its answer. Logs the food when a [meal] is given,
/// edits an existing entry when [logId] is given, otherwise it is just a calculator.
class FoodAmountScreen extends ConsumerStatefulWidget {
  const FoodAmountScreen({super.key, required this.foodId, this.meal, this.date, this.logId, this.quantity, this.unit});

  final int foodId;
  final String? meal;
  final String? date;
  final int? logId;
  final double? quantity;
  final String? unit;

  @override
  ConsumerState<FoodAmountScreen> createState() => _FoodAmountScreenState();
}

class _FoodAmountScreenState extends ConsumerState<FoodAmountScreen> {
  String _unit = 'grams';
  double _quantity = 100;
  int? _servingId;
  String? _meal;

  Calculation? _calc;
  bool _calculating = false;
  ApiError? _error;
  bool _saving = false;
  int _generation = 0;
  Timer? _debounce;
  bool _initialised = false;

  @override
  void initState() {
    super.initState();
    _meal = widget.meal;
    if (widget.quantity != null) _quantity = widget.quantity!;
    if (widget.unit != null) _unit = widget.unit!;
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  void _init(Food food) {
    if (_initialised) return;
    _initialised = true;
    if (_unit == 'serving' || (widget.quantity == null && food.servings.isNotEmpty)) {
      final s = food.defaultServing;
      if (widget.unit == null && s != null) {
        _unit = 'serving';
        _quantity = 1;
      }
      _servingId = s?.id;
    }
    if (_unit == 'serving') _servingId ??= food.defaultServing?.id;
    WidgetsBinding.instance.addPostFrameCallback((_) => _recalculate(immediately: true));
  }

  void _changed() {
    setState(() {});
    _recalculate();
  }

  /// Asks the server what this amount is worth. A late answer for an earlier amount is discarded.
  void _recalculate({bool immediately = false}) {
    _debounce?.cancel();
    _debounce = Timer(immediately ? Duration.zero : const Duration(milliseconds: 300), () async {
      final generation = ++_generation;
      if (_quantity <= 0) return;
      setState(() {
        _calculating = true;
        _error = null;
      });
      try {
        final result = await ref.read(nutritionRepositoryProvider).calculate(foodId: widget.foodId, quantity: _quantity, unit: _unit, servingId: _unit == 'serving' ? _servingId : null);
        if (!mounted || generation != _generation) return;
        setState(() {
          _calc = result;
          _calculating = false;
        });
      } on ApiError catch (e) {
        if (!mounted || generation != _generation) return;
        setState(() {
          _error = e;
          _calculating = false;
        });
      }
    });
  }

  Future<void> _save(Food food) async {
    setState(() {
      _saving = true;
      _error = null;
    });
    final repo = ref.read(nutritionRepositoryProvider);
    try {
      if (widget.logId != null) {
        await repo.updateLog(widget.logId!, quantity: _quantity, unit: _unit, servingId: _unit == 'serving' ? _servingId : null, meal: _meal);
      } else {
        await repo.log(foodId: food.id, meal: _meal!, date: widget.date == null ? DateTime.now() : DateTime.parse(widget.date!), quantity: _quantity, unit: _unit, servingId: _unit == 'serving' ? _servingId : null);
      }
      invalidateNutrition(ref);
      if (!mounted) return;
      // Editing returns to the day; adding returns to the day too, not to the search the member came through.
      widget.logId != null ? context.pop() : context.go(Routes.fuel);
    } on ApiError catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _toggleFavourite(Food food, bool current) async {
    try {
      await ref.read(nutritionRepositoryProvider).setFavorite(food.id, !current);
      ref.invalidate(favouriteIdsProvider);
    } on ApiError catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final food = ref.watch(foodProvider(widget.foodId));
    final favourites = ref.watch(favouriteIdsProvider).asData?.value ?? const <int>{};
    final day = ref.watch(nutritionDayProvider).asData?.value.data;
    final mealOptions = [for (final m in (day?.meals ?? const <MealGroup>[])) (m.meal, m.label)];

    return Scaffold(
      appBar: AppBar(
        title: Text(widget.logId != null ? 'Edit entry' : 'How much?'),
        actions: [
          food.maybeWhen(
            data: (f) => IconButton(
              tooltip: favourites.contains(f.id) ? 'Remove from favourites' : 'Add to favourites',
              onPressed: () => _toggleFavourite(f, favourites.contains(f.id)),
              icon: Icon(favourites.contains(f.id) ? Icons.star : Icons.star_border, color: IpColors.neon),
            ),
            orElse: () => const SizedBox.shrink(),
          ),
        ],
      ),
      body: SafeArea(
        child: food.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(foodProvider(widget.foodId))),
          data: (f) {
            _init(f);
            return _body(context, f, mealOptions);
          },
        ),
      ),
    );
  }

  Widget _body(BuildContext context, Food f, List<(String, String)> mealOptions) {
    final canLog = widget.logId != null || widget.meal != null;
    final unitOptions = [('grams', 'Grams'), if (f.servings.isNotEmpty) ('serving', 'Servings')];

    return ListView(padding: const EdgeInsets.fromLTRB(20, 8, 20, 24), children: [
      Text(f.name, style: IpText.display(21, color: IpColors.ink)),
      const SizedBox(height: 4),
      Text('Per 100 g: ${f.per100g.calories.round()} kcal · ${_g(f.per100g.proteinG)} g protein · ${_g(f.per100g.carbsG)} g carbs · ${_g(f.per100g.fatG)} g fat', style: IpText.body(12.5, color: IpColors.muted)),
      const SizedBox(height: 18),
      if (unitOptions.length > 1)
        ChoiceTiles<String>(
          compact: true,
          options: unitOptions,
          selected: {_unit},
          onChanged: (s) {
            setState(() {
              _unit = s.first;
              if (_unit == 'serving') {
                _servingId ??= f.defaultServing?.id;
                _quantity = 1;
              } else {
                _quantity = _calc?.grams.roundToDouble() ?? 100;
              }
            });
            _recalculate();
          },
        ),
      const SizedBox(height: 16),
      NumberStepper(
        key: ValueKey('qty-$_unit'),
        label: _unit == 'grams' ? 'Amount' : 'Number of servings',
        unit: _unit == 'grams' ? 'grams' : null,
        value: _quantity,
        step: _unit == 'grams' ? 10 : 0.5,
        decimals: _unit == 'grams' ? 0 : 1,
        min: 0.1,
        max: 5000,
        onChanged: (v) {
          _quantity = v;
          _changed();
        },
      ),
      if (_unit == 'serving' && f.servings.isNotEmpty) ...[
        const SizedBox(height: 14),
        ChoiceTiles<int>(
          options: [for (final s in f.servings) (s.id, s.label)],
          selected: {_servingId ?? f.defaultServing!.id},
          onChanged: (s) {
            _servingId = s.first;
            _changed();
          },
        ),
      ],
      const SizedBox(height: 18),
      _Result(calc: _calc, calculating: _calculating, food: f),
      if (_error != null) ...[const SizedBox(height: 12), MessageBanner(_error!.isOffline ? 'You are offline. Foods can be logged once you are back online.' : (_error!.fieldErrors.values.firstOrNull ?? _error!.message))],
      if (canLog) ...[
        const SizedBox(height: 18),
        if (mealOptions.length > 1 && _meal != null)
          ChoiceTiles<String>(compact: true, options: mealOptions, selected: {_meal!}, onChanged: (s) => setState(() => _meal = s.first)),
        const SizedBox(height: 14),
        NeonButton(
          label: widget.logId != null ? 'Save changes' : 'Log ${mealOptions.where((m) => m.$1 == _meal).firstOrNull?.$2 ?? _meal}',
          size: NeonButtonSize.large,
          expand: true,
          loading: _saving,
          onPressed: _calc == null || _calculating ? null : () => _save(f),
        ),
      ],
    ]);
  }

  static String _g(double v) => v == v.roundToDouble() ? v.round().toString() : v.toStringAsFixed(1);
}

class _Result extends StatelessWidget {
  const _Result({required this.calc, required this.calculating, required this.food});
  final Calculation? calc;
  final bool calculating;
  final Food food;

  static String _g(double v) => v == v.roundToDouble() ? v.round().toString() : v.toStringAsFixed(1);

  @override
  Widget build(BuildContext context) {
    final c = calc;
    if (c == null) {
      return const GlassCard(child: Center(child: Padding(padding: EdgeInsets.all(12), child: SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5)))));
    }
    return Opacity(
      opacity: calculating ? 0.55 : 1,
      child: GlassCard(
        neon: true,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Text('THIS AMOUNT', style: IpText.eyebrow(color: IpColors.neon)),
            const Spacer(),
            Text('${_g(c.grams)} g', style: IpText.body(13, weight: FontWeight.w600, color: IpColors.muted)),
          ]),
          const SizedBox(height: 10),
          Row(crossAxisAlignment: CrossAxisAlignment.baseline, textBaseline: TextBaseline.alphabetic, children: [
            Text(_g(c.macros.calories), style: IpText.number(44, color: IpColors.ink)),
            Text(' kcal', style: IpText.body(14, color: IpColors.muted)),
          ]),
          const SizedBox(height: 10),
          Row(children: [
            _Macro(label: 'Protein', value: c.macros.proteinG),
            _Macro(label: 'Carbs', value: c.macros.carbsG),
            _Macro(label: 'Fat', value: c.macros.fatG),
            _Macro(label: 'Fibre', value: c.macros.fibreG),
          ]),
          const SizedBox(height: 12),
          // The explanation of the number above, built from the server's own figures.
          Text(
            '${_g(c.grams)} g × ${_g(c.food.per100g.proteinG)} g protein per 100 g = ${_g(c.macros.proteinG)} g protein',
            style: IpText.body(12.5, color: IpColors.muted),
          ),
        ]),
      ),
    );
  }
}

class _Macro extends StatelessWidget {
  const _Macro({required this.label, required this.value});
  final String label;
  final double value;

  @override
  Widget build(BuildContext context) => Expanded(
        child: Column(children: [
          Text(label.toUpperCase(), style: IpText.body(10.5, weight: FontWeight.w600, color: IpColors.muted).copyWith(letterSpacing: 0.8)),
          const SizedBox(height: 2),
          Text('${value == value.roundToDouble() ? value.round() : value.toStringAsFixed(1)} g', style: IpText.number(20, color: IpColors.neon)),
        ]),
      );
}
