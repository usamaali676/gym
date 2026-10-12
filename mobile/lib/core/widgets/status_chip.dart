import 'package:flutter/material.dart';

import '../../app/theme/tokens.g.dart';
import '../../app/theme/typography.dart';

/// Meaning of a chip's colour. Posture feedback uses exactly these four: green correct, red incorrect,
/// amber uncertain, neutral not tracking.
enum Tone { neutral, good, bad, warn }

extension ToneColors on Tone {
  Color get color => switch (this) {
        Tone.neutral => IpColors.ink,
        Tone.good => IpColors.neon,
        Tone.bad => const Color(0xFFFF8A8A),
        Tone.warn => IpColors.warn,
      };

  Color get border => switch (this) {
        Tone.neutral => IpColors.line,
        Tone.good => IpColors.lineStrong,
        Tone.bad => const Color.fromRGBO(255, 59, 59, 0.45),
        Tone.warn => const Color.fromRGBO(255, 176, 32, 0.5),
      };

  Color get fill => switch (this) {
        Tone.neutral => const Color.fromRGBO(57, 255, 20, 0.08),
        Tone.good => const Color.fromRGBO(57, 255, 20, 0.08),
        Tone.bad => const Color.fromRGBO(255, 59, 59, 0.1),
        Tone.warn => const Color.fromRGBO(255, 176, 32, 0.1),
      };
}

/// The web's `.chip`. Always carries text (never colour alone) so it is readable without colour vision.
class StatusChip extends StatelessWidget {
  const StatusChip(this.label, {super.key, this.tone = Tone.neutral, this.icon});

  final String label;
  final Tone tone;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(color: tone.fill, border: Border.all(color: tone.border), borderRadius: BorderRadius.circular(999)),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 11.2, vertical: 4),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          if (icon != null) ...[Icon(icon, size: 14, color: tone.color), const SizedBox(width: 5.6)],
          Flexible(child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: IpText.body(12, weight: FontWeight.w600, color: tone.color).copyWith(letterSpacing: 0.36))),
        ]),
      ),
    );
  }
}
