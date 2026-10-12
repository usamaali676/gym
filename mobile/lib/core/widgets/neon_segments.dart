import 'package:flutter/material.dart';

import '../../app/theme/tokens.g.dart';
import '../../app/theme/typography.dart';

/// A row of equal pills that switch between a few views of one screen (This week / Programmes / Exercises). Each pill is
/// at least 48 dp high so it is easy to hit, shrinks its label instead of overflowing at large text sizes, and tells a
/// screen reader its name, that it is selected or not, and that it can be pressed.
class NeonSegments extends StatelessWidget {
  const NeonSegments({super.key, required this.index, required this.labels, required this.onChanged});

  final int index;
  final List<String> labels;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    return Row(children: [
      for (var i = 0; i < labels.length; i++) ...[
        if (i > 0) const SizedBox(width: 8),
        Expanded(
          child: Semantics(
            button: true,
            selected: i == index,
            label: labels[i],
            onTap: () => onChanged(i), // the visible tap handler is excluded with its children, so a screen reader needs this one
            excludeSemantics: true,
            child: GestureDetector(
              behavior: HitTestBehavior.opaque,
              onTap: () => onChanged(i),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 160),
                constraints: const BoxConstraints(minHeight: 48),
                padding: const EdgeInsets.symmetric(horizontal: 4),
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(999),
                  color: i == index ? const Color.fromRGBO(57, 255, 20, 0.12) : Colors.transparent,
                  border: Border.all(color: i == index ? IpColors.neon : IpColors.line),
                ),
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(labels[i], maxLines: 1, style: IpText.body(13.5, weight: FontWeight.w600, color: i == index ? IpColors.neon : IpColors.muted)),
                ),
              ),
            ),
          ),
        ),
      ],
    ]);
  }
}
