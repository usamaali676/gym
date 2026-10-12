import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/api/api_error.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/state_views.dart';
import '../domain/nutrition.dart';
import 'nutrition_providers.dart';

enum _Source { search, recent, favourites }

/// Pick a food to log (with [meal]) or just to calculate (without one: the calculator).
class FoodSearchScreen extends ConsumerStatefulWidget {
  const FoodSearchScreen({super.key, this.meal, this.date});

  final String? meal;
  final String? date;

  @override
  ConsumerState<FoodSearchScreen> createState() => _FoodSearchScreenState();
}

class _FoodSearchScreenState extends ConsumerState<FoodSearchScreen> {
  final _controller = TextEditingController();
  Timer? _debounce;
  _Source _source = _Source.recent;
  int _generation = 0;
  List<Food>? _foods;
  Object? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _controller.dispose();
    super.dispose();
  }

  void _onQuery(String text) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () {
      setState(() => _source = text.trim().isEmpty ? _Source.recent : _Source.search);
      _load();
    });
  }

  Future<void> _load() async {
    final generation = ++_generation;
    setState(() {
      _loading = true;
      _error = null;
    });
    final repo = ref.read(nutritionRepositoryProvider);
    try {
      final foods = switch (_source) {
        _Source.search => await repo.search(_controller.text),
        _Source.recent => await repo.recent(),
        _Source.favourites => await repo.favorites(),
      };
      if (!mounted || generation != _generation) return; // the member searched for something else meanwhile
      setState(() {
        _foods = foods;
        _loading = false;
      });
    } on ApiError catch (e) {
      if (!mounted || generation != _generation) return;
      setState(() {
        _error = e;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final calc = widget.meal == null;
    final query = [if (widget.meal != null) 'meal=${widget.meal}', if (widget.date != null) 'date=${widget.date}'].join('&');

    return Scaffold(
      appBar: AppBar(title: Text(calc ? 'Food calculator' : 'Add to ${widget.meal}')),
      body: SafeArea(
        child: Column(children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 8),
            child: TextField(
              controller: _controller,
              onChanged: _onQuery,
              autofocus: false,
              textInputAction: TextInputAction.search,
              style: IpText.body(15.5, color: IpColors.ink),
              decoration: const InputDecoration(hintText: 'Search foods (chicken, rice, dal...)', prefixIcon: Icon(Icons.search, color: IpColors.muted)),
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: Wrap(spacing: 8, runSpacing: 4, children: [
              for (final s in [(_Source.recent, 'Recent'), (_Source.favourites, 'Favourites')])
                ChoiceChip(
                    label: Text(s.$2),
                    selected: _source == s.$1,
                    onSelected: (_) {
                      _controller.clear();
                      setState(() => _source = s.$1);
                      _load();
                    },
                  ),
              if (_source == _Source.search) const ChoiceChip(label: Text('Results'), selected: true, onSelected: null),
            ]),
          ),
          Expanded(
            child: _loading && _foods == null
                ? ListView(padding: const EdgeInsets.all(20), children: const [SkeletonList(count: 4)])
                : _error != null
                    ? ErrorState(error: _error!, onRetry: _load)
                    : (_foods ?? const []).isEmpty
                        ? EmptyState(
                            title: switch (_source) {
                              _Source.search => 'No foods found',
                              _Source.recent => 'Nothing logged recently',
                              _Source.favourites => 'No favourites yet',
                            },
                            message: switch (_source) {
                              _Source.search => 'Try another spelling or a simpler word.',
                              _Source.recent => 'Search for a food above. Foods you log often show up here.',
                              _Source.favourites => 'Open a food and tap the star to keep it here.',
                            },
                            icon: Icons.restaurant_outlined,
                          )
                        : ListView.builder(
                            padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
                            itemCount: _foods!.length,
                            itemBuilder: (context, i) {
                              final f = _foods![i];
                              return Padding(
                                padding: const EdgeInsets.only(bottom: 10),
                                child: GlassCard(
                                  padding: const EdgeInsets.all(14),
                                  onTap: () => context.push('${Routes.foodAmount}/${f.id}${query.isEmpty ? '' : '?$query'}'),
                                  semanticLabel: f.name,
                                  child: Row(children: [
                                    Expanded(
                                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                        Text(f.name, style: IpText.body(15, weight: FontWeight.w600, color: IpColors.ink)),
                                        const SizedBox(height: 2),
                                        Text('${f.per100g.calories.round()} kcal · ${f.per100g.proteinG.round()} g protein per 100 g${f.custom ? ' · yours' : ''}', style: IpText.body(12.5, color: IpColors.muted)),
                                      ]),
                                    ),
                                    const Icon(Icons.chevron_right, color: IpColors.muted),
                                  ]),
                                ),
                              );
                            },
                          ),
          ),
          // Always reachable, including when nothing was found: the food may simply not be in the catalogue.
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 12),
            child: NeonButton(
              label: 'Add your own food',
              icon: Icons.add,
              variant: NeonButtonVariant.ghost,
              expand: true,
              onPressed: () {
                final name = _controller.text.trim();
                final q = [if (widget.meal != null) 'meal=${widget.meal}', if (widget.date != null) 'date=${widget.date}', if (name.isNotEmpty) 'name=${Uri.encodeQueryComponent(name)}'].join('&');
                context.push('${Routes.foodNew}${q.isEmpty ? '' : '?$q'}');
              },
            ),
          ),
        ]),
      ),
    );
  }
}
