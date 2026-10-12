import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/api/api_error.dart';
import '../../../core/policy/policy_controller.dart';
import '../../../core/widgets/choice_tiles.dart';
import '../../../core/widgets/error_banner.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/neon_text_field.dart';
import 'nutrition_providers.dart';

/// "Add your own food": the numbers printed on a label, per 100 g. The server checks them (the energy must match the macros) and
/// says which field is wrong; the app only checks that numbers are numbers.
class CustomFoodScreen extends ConsumerStatefulWidget {
  const CustomFoodScreen({super.key, this.meal, this.date, this.name});

  final String? meal;
  final String? date;

  /// What the member had typed in the search box.
  final String? name;

  @override
  ConsumerState<CustomFoodScreen> createState() => _CustomFoodScreenState();
}

class _CustomFoodScreenState extends ConsumerState<CustomFoodScreen> {
  late final _name = TextEditingController(text: widget.name ?? '');
  final _brand = TextEditingController();
  final _kcal = TextEditingController();
  final _protein = TextEditingController();
  final _carbs = TextEditingController();
  final _fat = TextEditingController();
  final _fibre = TextEditingController();
  final _servingLabel = TextEditingController();
  final _servingGrams = TextEditingController();
  String? _diet;
  bool _busy = false;
  String? _banner;
  Map<String, String> _server = const {};
  final Map<String, String> _local = {};

  @override
  void dispose() {
    for (final c in [_name, _brand, _kcal, _protein, _carbs, _fat, _fibre, _servingLabel, _servingGrams]) {
      c.dispose();
    }
    super.dispose();
  }

  double? _num(TextEditingController c) => double.tryParse(c.text.trim().replaceAll(',', '.'));

  String? _err(String key) => _local[key] ?? _server[key];

  Future<void> _save() async {
    final local = <String, String>{};
    if (_name.text.trim().length < 2) local['name'] = 'Enter the food\'s name.';
    if (_diet == null) local['diet_type'] = 'Choose a diet type.';
    final required = {'kcal_100g': _kcal, 'protein_100g': _protein, 'carbs_100g': _carbs, 'fat_100g': _fat};
    required.forEach((key, c) {
      if (c.text.trim().isEmpty) {
        local[key] = 'Enter a number (0 if none).';
      } else if (_num(c) == null) {
        local[key] = 'Use numbers only.';
      }
    });
    if (_fibre.text.trim().isNotEmpty && _num(_fibre) == null) local['fibre_100g'] = 'Use numbers only.';
    final hasLabel = _servingLabel.text.trim().isNotEmpty;
    final hasGrams = _servingGrams.text.trim().isNotEmpty;
    if (hasLabel != hasGrams) local[hasLabel ? 'servings.0.grams' : 'servings.0.label'] = 'Fill in both the serving name and its grams, or neither.';
    if (hasGrams && _num(_servingGrams) == null) local['servings.0.grams'] = 'Use numbers only.';
    setState(() {
      _local
        ..clear()
        ..addAll(local);
      _server = const {};
      _banner = null;
    });
    if (local.isNotEmpty) return;

    setState(() => _busy = true);
    try {
      final food = await ref.read(nutritionRepositoryProvider).createFood(
            name: _name.text,
            brand: _brand.text,
            dietType: _diet!,
            kcal: _num(_kcal)!,
            protein: _num(_protein)!,
            carbs: _num(_carbs)!,
            fat: _num(_fat)!,
            fibre: _fibre.text.trim().isEmpty ? null : _num(_fibre),
            servingLabel: hasLabel ? _servingLabel.text : null,
            servingGrams: hasLabel ? _num(_servingGrams) : null,
          );
      if (!mounted) return;
      final q = [if (widget.meal != null) 'meal=${widget.meal}', if (widget.date != null) 'date=${widget.date}'].join('&');
      context.pushReplacement('${Routes.foodAmount}/${food.id}${q.isEmpty ? '' : '?$q'}');
    } on ApiError catch (e) {
      if (!mounted) return;
      setState(() {
        _server = e.fieldErrors;
        _banner = e.fieldErrors.isEmpty ? (e.isOffline ? 'Adding a food needs a connection. Check it and try again.' : e.message) : null;
        _busy = false;
      });
    }
  }

  Widget _number(String label, TextEditingController c, String key, {String? helper}) => NeonTextField(
        label: label,
        controller: c,
        helper: helper,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        textInputAction: TextInputAction.next,
        errorText: _err(key),
      );

  @override
  Widget build(BuildContext context) {
    final config = ref.watch(policyControllerProvider).config;
    final List<(String, String)> diets = [for (final o in config?.options('diet_preference') ?? const []) (o.value.toString(), o.label)];
    return Scaffold(
      appBar: AppBar(title: const Text('Add your own food')),
      body: SafeArea(
        child: ListView(padding: const EdgeInsets.fromLTRB(20, 8, 20, 28), children: [
          Text('Copy the numbers from the label, per 100 g. Only you can see this food.', style: IpText.body(13.5, color: IpColors.muted)),
          const SizedBox(height: 14),
          if (_banner != null) ...[MessageBanner(_banner!), const SizedBox(height: 12)],
          NeonTextField(label: 'Name', controller: _name, textInputAction: TextInputAction.next, maxLength: 120, errorText: _err('name')),
          const SizedBox(height: 12),
          NeonTextField(label: 'Brand (optional)', controller: _brand, textInputAction: TextInputAction.next, maxLength: 80, errorText: _err('brand')),
          const SizedBox(height: 14),
          Text('DIET TYPE', style: IpText.eyebrow(color: IpColors.neon)),
          const SizedBox(height: 8),
          ChoiceTiles<String>(options: diets, selected: {?_diet}, errorText: _err('diet_type'), onChanged: (s) => setState(() => _diet = s.first)),
          const SizedBox(height: 14),
          _number('Calories per 100 g (kcal)', _kcal, 'kcal_100g'),
          const SizedBox(height: 12),
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(child: _number('Protein (g)', _protein, 'protein_100g')),
            const SizedBox(width: 12),
            Expanded(child: _number('Carbs (g)', _carbs, 'carbs_100g')),
          ]),
          const SizedBox(height: 12),
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(child: _number('Fat (g)', _fat, 'fat_100g')),
            const SizedBox(width: 12),
            Expanded(child: _number('Fibre (g, optional)', _fibre, 'fibre_100g')),
          ]),
          const SizedBox(height: 18),
          Text('ONE SERVING (OPTIONAL)', style: IpText.eyebrow(color: IpColors.neon)),
          const SizedBox(height: 8),
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(flex: 3, child: NeonTextField(label: 'Name', controller: _servingLabel, hint: '1 cup, 1 slice', textInputAction: TextInputAction.next, errorText: _err('servings.0.label'))),
            const SizedBox(width: 12),
            Expanded(flex: 2, child: _number('Grams', _servingGrams, 'servings.0.grams')),
          ]),
          const SizedBox(height: 22),
          NeonButton(label: 'Save and choose amount', size: NeonButtonSize.large, expand: true, loading: _busy, onPressed: _save),
        ]),
      ),
    );
  }
}
