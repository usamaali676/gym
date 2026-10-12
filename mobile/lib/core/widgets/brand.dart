import 'package:flutter/material.dart';

import '../../app/theme/tokens.g.dart';
import '../../app/theme/typography.dart';

/// The IRONPULSE wordmark: Orbitron Black, "PULSE" in neon with the web's glow.
class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.size = 30});
  final double size;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      header: true,
      label: 'IronPulse',
      excludeSemantics: true,
      child: Text.rich(
        TextSpan(children: [
          TextSpan(text: 'IRON', style: IpText.display(size, weight: FontWeight.w900, color: IpColors.ink)),
          TextSpan(
            text: 'PULSE',
            style: IpText.display(size, weight: FontWeight.w900, color: IpColors.neon).copyWith(
              shadows: const [Shadow(color: Color.fromRGBO(57, 255, 20, 0.55), blurRadius: 18)],
            ),
          ),
        ]),
      ),
    );
  }
}
