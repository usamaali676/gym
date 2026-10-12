import 'package:flutter/material.dart';

import '../../app/theme/app_theme.dart';
import '../../app/theme/tokens.g.dart';

/// The web's `.glass` card: dark graphite gradient, hairline green border, soft drop shadow. [neon] gives the
/// stronger border and glow of `.glass-neon`. Pass [onTap] to make the whole card a button.
class GlassCard extends StatelessWidget {
  const GlassCard({super.key, required this.child, this.padding = const EdgeInsets.all(18), this.neon = false, this.onTap, this.semanticLabel});

  final Widget child;
  final EdgeInsetsGeometry padding;
  final bool neon;
  final VoidCallback? onTap;
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(IpRadius.card);
    final card = DecoratedBox(
      decoration: BoxDecoration(
        borderRadius: radius,
        gradient: const LinearGradient(
          begin: Alignment(-0.8, -1),
          end: Alignment(0.8, 1),
          colors: [Color.fromRGBO(24, 36, 29, 0.78), Color.fromRGBO(13, 20, 16, 0.78)],
        ),
        border: Border.all(color: neon ? IpColors.lineStrong : IpColors.line),
        boxShadow: [
          if (neon) const BoxShadow(color: Color.fromRGBO(57, 255, 20, 0.14), blurRadius: 30),
          const BoxShadow(color: Color.fromRGBO(0, 0, 0, 0.4), blurRadius: 34, offset: Offset(0, 14)),
        ],
      ),
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          borderRadius: radius,
          onTap: onTap,
          child: Padding(padding: padding, child: child),
        ),
      ),
    );
    return semanticLabel == null ? card : Semantics(container: true, label: semanticLabel, child: card);
  }
}
