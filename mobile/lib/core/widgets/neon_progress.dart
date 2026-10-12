import 'package:flutter/material.dart';

import '../../app/theme/tokens.g.dart';

/// Thin neon bar (the web's progress bars). [value] is 0..1. Over-target values stay full; [tone] colours it.
class NeonProgress extends StatelessWidget {
  const NeonProgress({super.key, required this.value, this.height = 8, this.color = IpColors.neon, this.semanticLabel});

  final double value;
  final double height;
  final Color color;
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) {
    final v = value.isNaN ? 0.0 : value.clamp(0.0, 1.0);
    return Semantics(
      label: semanticLabel,
      value: '${(v * 100).round()} percent',
      child: ClipRRect(
        borderRadius: BorderRadius.circular(height),
        child: SizedBox(
          height: height,
          child: Stack(children: [
            const Positioned.fill(child: ColoredBox(color: Color(0xFF1C2A22))),
            FractionallySizedBox(
              widthFactor: v,
              heightFactor: 1, // without this the fill collapses to zero height inside the Stack
              alignment: Alignment.centerLeft,
              child: DecoratedBox(
                decoration: BoxDecoration(
                  color: color,
                  boxShadow: [BoxShadow(color: color.withValues(alpha: 0.5), blurRadius: 8)],
                ),
              ),
            ),
          ]),
        ),
      ),
    );
  }
}
