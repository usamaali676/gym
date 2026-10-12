import 'package:flutter/material.dart';

import '../../app/theme/app_theme.dart';
import '../../app/theme/tokens.g.dart';
import '../../app/theme/typography.dart';

/// Selectable tiles like the web's radio / checkbox cards. Single choice when [multi] is false.
class ChoiceTiles<T> extends StatelessWidget {
  const ChoiceTiles({
    super.key,
    required this.options,
    required this.selected,
    required this.onChanged,
    this.multi = false,
    this.compact = false,
    this.errorText,
  });

  /// (value, label) pairs in display order.
  final List<(T, String)> options;
  final Set<T> selected;
  final ValueChanged<Set<T>> onChanged;
  final bool multi;

  /// Small pill tiles that sit side by side (weekdays) instead of full-width rows.
  final bool compact;
  final String? errorText;

  void _toggle(T value) {
    if (multi) {
      onChanged(selected.contains(value) ? ({...selected}..remove(value)) : {...selected, value});
    } else {
      onChanged({value});
    }
  }

  @override
  Widget build(BuildContext context) {
    final tiles = [
      for (final (value, label) in options)
        _Tile(label: label, selected: selected.contains(value), multi: multi, compact: compact, onTap: () => _toggle(value)),
    ];

    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      compact
          ? Wrap(spacing: 8, runSpacing: 8, children: tiles)
          : Column(children: [for (final t in tiles) Padding(padding: const EdgeInsets.only(bottom: 8), child: t)]),
      if (errorText != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(errorText!, style: IpText.body(13, color: const Color(0xFFFF8A8A)))),
    ]);
  }
}

class _Tile extends StatelessWidget {
  const _Tile({required this.label, required this.selected, required this.multi, required this.compact, required this.onTap});

  final String label;
  final bool selected;
  final bool multi;
  final bool compact;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(IpRadius.tile);
    return Semantics(
      inMutuallyExclusiveGroup: !multi,
      checked: multi ? selected : null,
      selected: selected,
      button: true,
      label: label,
      excludeSemantics: true,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 160),
        constraints: const BoxConstraints(minHeight: 44),
        decoration: BoxDecoration(
          borderRadius: radius,
          color: selected ? const Color.fromRGBO(57, 255, 20, 0.08) : const Color.fromRGBO(0, 0, 0, 0.22),
          border: Border.all(color: selected ? IpColors.neon : IpColors.line),
          boxShadow: selected ? IpShadows.neonSm : const [],
        ),
        child: Material(
          type: MaterialType.transparency,
          child: InkWell(
            borderRadius: radius,
            onTap: onTap,
            child: Padding(
              padding: EdgeInsets.symmetric(horizontal: compact ? 14 : 16, vertical: compact ? 10 : 12),
              child: Row(mainAxisSize: compact ? MainAxisSize.min : MainAxisSize.max, children: [
                Expanded(
                  flex: compact ? 0 : 1,
                  child: Text(label, style: IpText.body(14.5, weight: selected ? FontWeight.w600 : FontWeight.w500, color: IpColors.ink)),
                ),
                if (!compact) Icon(selected ? Icons.check_circle : (multi ? Icons.check_box_outline_blank : Icons.circle_outlined), size: 20, color: selected ? IpColors.neon : IpColors.muted),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}
