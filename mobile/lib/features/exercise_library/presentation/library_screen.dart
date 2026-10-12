import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/policy/app_config.dart';
import '../../../core/policy/policy_controller.dart';
import '../../../core/widgets/choice_tiles.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/state_views.dart';
import '../../../core/widgets/status_chip.dart';
import '../domain/exercise.dart';
import 'library_controller.dart';

/// Library tab: search and filter the exercise catalogue.
class LibraryScreen extends ConsumerStatefulWidget {
  const LibraryScreen({super.key, this.embedded = false});

  /// Inside another screen (the Train tab): no safe area and no title of its own.
  final bool embedded;

  @override
  ConsumerState<LibraryScreen> createState() => _LibraryScreenState();
}

class _LibraryScreenState extends ConsumerState<LibraryScreen> {
  final _scroll = ScrollController();
  final _search = TextEditingController();

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_scroll.hasClients && _scroll.position.extentAfter < 500) ref.read(libraryControllerProvider.notifier).loadMore();
    });
  }

  @override
  void dispose() {
    _scroll.dispose();
    _search.dispose();
    super.dispose();
  }

  Future<void> _pick(BuildContext context, String title, List<ProfileOption> options, String? current, ValueChanged<String?> onPick) async {
    final choice = await showModalBottomSheet<(String?,)>(
      context: context,
      isScrollControlled: true,
      builder: (context) => SafeArea(
        child: ConstrainedBox(
          constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.75),
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 20, 20, 24),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
              Text(title, style: IpText.display(17, color: IpColors.ink)),
              const SizedBox(height: 14),
              ChoiceTiles<String>(
                options: [('', 'Any'), for (final o in options) (o.value.toString(), o.label)],
                selected: {current ?? ''},
                onChanged: (s) => Navigator.pop(context, (s.first.isEmpty ? null : s.first,)),
              ),
            ]),
          ),
        ),
      ),
    );
    if (choice != null) onPick(choice.$1);
  }

  String _label(List<ProfileOption> options, String? value, String fallback) {
    if (value == null) return fallback;
    return options.where((o) => o.value == value).firstOrNull?.label ?? value;
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(libraryControllerProvider);
    final controller = ref.read(libraryControllerProvider.notifier);
    final config = ref.watch(policyControllerProvider).config;
    final muscles = config?.libraryChoices('muscle') ?? const <ProfileOption>[];
    final equipment = config?.libraryChoices('equipment') ?? const <ProfileOption>[];
    final levels = config?.libraryChoices('difficulty') ?? const <ProfileOption>[];
    final f = state.filters;

    final content = Column(children: [
        Padding(
          padding: EdgeInsets.fromLTRB(20, widget.embedded ? 4 : 20, 20, 8),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (!widget.embedded) ...[Text('LIBRARY', style: IpText.eyebrow(color: IpColors.neon)), const SizedBox(height: 10)],
            TextField(
              controller: _search,
              onChanged: controller.search,
              textInputAction: TextInputAction.search,
              style: IpText.body(15.5, color: IpColors.ink),
              decoration: InputDecoration(
                hintText: 'Search exercises',
                prefixIcon: const Icon(Icons.search, color: IpColors.muted),
                suffixIcon: _search.text.isEmpty
                    ? null
                    : IconButton(
                        tooltip: 'Clear search',
                        icon: const Icon(Icons.close, color: IpColors.muted),
                        onPressed: () {
                          _search.clear();
                          controller.search('');
                          setState(() {});
                        },
                      ),
              ),
            ),
            const SizedBox(height: 10),
            SizedBox(
              height: 40,
              child: ListView(scrollDirection: Axis.horizontal, children: [
                if (f.muscle != null || f.equipment != null || f.difficulty != null) _FilterChip(label: 'Clear filters', active: false, icon: Icons.close, onTap: controller.clearFilters),
                _FilterChip(label: _label(muscles, f.muscle, 'Muscle'), active: f.muscle != null, onTap: () => _pick(context, 'Muscle group', muscles, f.muscle, controller.setMuscle)),
                _FilterChip(label: _label(equipment, f.equipment, 'Equipment'), active: f.equipment != null, onTap: () => _pick(context, 'Equipment', equipment, f.equipment, controller.setEquipment)),
                _FilterChip(label: _label(levels, f.difficulty, 'Level'), active: f.difficulty != null, onTap: () => _pick(context, 'Level', levels, f.difficulty, controller.setDifficulty)),
              ]),
            ),
          ]),
        ),
        if (state.fromCache) const OfflineBanner(),
        Expanded(child: _body(context, state, controller)),
      ]);
    return widget.embedded ? content : SafeArea(bottom: false, child: content);
  }

  Widget _body(BuildContext context, LibraryState state, LibraryController controller) {
    if (state.loading && state.items.isEmpty) {
      return ListView(padding: const EdgeInsets.fromLTRB(20, 8, 20, 24), children: const [SkeletonList(count: 5)]);
    }
    if (state.error != null && state.items.isEmpty) {
      return ErrorState(error: state.error!, onRetry: controller.refresh);
    }
    if (state.items.isEmpty) {
      return EmptyState(
        title: 'No exercises match',
        message: state.filters.isActive ? 'Try a different search or clear the filters.' : 'The library is empty for now.',
        icon: Icons.search_off,
        actionLabel: state.filters.isActive ? 'Clear filters' : null,
        onAction: state.filters.isActive
            ? () {
                _search.clear();
                controller.search('');
                controller.clearFilters();
                setState(() {});
              }
            : null,
      );
    }

    return RefreshIndicator(
      color: IpColors.neon,
      backgroundColor: IpColors.surface2,
      onRefresh: controller.refresh,
      child: ListView.builder(
        controller: _scroll,
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 24),
        itemCount: state.items.length + 1,
        itemBuilder: (context, i) {
          if (i == 0) {
            return Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Text('${state.total} ${state.total == 1 ? 'exercise' : 'exercises'}', style: IpText.body(12.5, weight: FontWeight.w600, color: IpColors.muted)),
            );
          }
          if (i - 1 < state.items.length) return Padding(padding: const EdgeInsets.only(bottom: 10), child: ExerciseRow(exercise: state.items[i - 1]));
          return const SizedBox.shrink();
        },
      ),
    ).withFooter(state: state, controller: controller);
  }
}

extension on Widget {
  /// A "loading more" spinner or a retry under the list.
  Widget withFooter({required LibraryState state, required LibraryController controller}) {
    final footer = state.loadingMore
        ? const Padding(padding: EdgeInsets.all(12), child: SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5)))
        : (state.error != null && state.items.isNotEmpty)
            ? Padding(
                padding: const EdgeInsets.all(8),
                child: NeonButton(label: 'Could not load more. Try again', size: NeonButtonSize.small, variant: NeonButtonVariant.ghost, onPressed: controller.loadMore),
              )
            : null;
    return Column(children: [Expanded(child: this), ?footer]);
  }
}

class _FilterChip extends StatelessWidget {
  const _FilterChip({required this.label, required this.active, required this.onTap, this.icon});
  final String label;
  final bool active;
  final VoidCallback onTap;
  final IconData? icon;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(right: 8),
        child: Semantics(
          button: true,
          selected: active,
          label: label,
          excludeSemantics: true,
          child: GestureDetector(
            onTap: onTap,
            child: Container(
              constraints: const BoxConstraints(minHeight: 40),
              padding: const EdgeInsets.symmetric(horizontal: 14),
              alignment: Alignment.center,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(999),
                color: active ? const Color.fromRGBO(57, 255, 20, 0.12) : Colors.transparent,
                border: Border.all(color: active ? IpColors.neon : IpColors.line),
              ),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                if (icon != null) ...[Icon(icon, size: 14, color: IpColors.muted), const SizedBox(width: 4)],
                Text(label, style: IpText.body(13, weight: FontWeight.w600, color: active ? IpColors.neon : IpColors.ink)),
                if (icon == null) ...[const SizedBox(width: 4), Icon(Icons.expand_more, size: 16, color: active ? IpColors.neon : IpColors.muted)],
              ]),
            ),
          ),
        ),
      );
}

class ExerciseRow extends StatelessWidget {
  const ExerciseRow({super.key, required this.exercise});
  final Exercise exercise;

  @override
  Widget build(BuildContext context) {
    final e = exercise;
    return GlassCard(
      padding: const EdgeInsets.all(14),
      onTap: () => context.push('${Routes.exercise}/${e.slug}'),
      semanticLabel: '${e.name}, ${e.muscleLabel}${e.locked ? ', locked' : ''}',
      child: Row(children: [
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(e.name, style: IpText.body(15.5, weight: FontWeight.w600, color: e.locked ? IpColors.muted : IpColors.ink)),
            const SizedBox(height: 6),
            Wrap(spacing: 6, runSpacing: 6, children: [
              StatusChip(e.muscleLabel),
              if (e.difficulty != null) StatusChip(e.difficulty![0].toUpperCase() + e.difficulty!.substring(1)),
              if (e.locked) const StatusChip('Premium', tone: Tone.warn, icon: Icons.lock_outline),
            ]),
          ]),
        ),
        const Icon(Icons.chevron_right, color: IpColors.muted),
      ]),
    );
  }
}
