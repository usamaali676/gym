import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../app/theme/app_theme.dart';
import '../../app/theme/tokens.g.dart';
import '../../app/theme/typography.dart';

enum NeonButtonVariant { primary, ghost, danger }

enum NeonButtonSize { small, medium, large }

/// The web's `.btn`: uppercase Orbitron label, neon fill for the main action, outlined ghost, red danger.
/// Minimum touch height 44 (36 small, 56 large). A `null` [onPressed] or [loading] dims it and ignores taps.
class NeonButton extends StatefulWidget {
  const NeonButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.variant = NeonButtonVariant.primary,
    this.size = NeonButtonSize.medium,
    this.icon,
    this.loading = false,
    this.expand = false,
  });

  final String label;
  final VoidCallback? onPressed;
  final NeonButtonVariant variant;
  final NeonButtonSize size;
  final IconData? icon;
  final bool loading;
  final bool expand;

  @override
  State<NeonButton> createState() => _NeonButtonState();
}

class _NeonButtonState extends State<NeonButton> {
  bool _down = false;

  bool get _enabled => widget.onPressed != null && !widget.loading;

  @override
  Widget build(BuildContext context) {
    final (bg, fg, border, glow) = switch (widget.variant) {
      NeonButtonVariant.primary => (IpColors.neon, const Color(0xFF041006), Colors.transparent, IpShadows.neonSm),
      NeonButtonVariant.ghost => (const Color.fromRGBO(57, 255, 20, 0.05), IpColors.ink, IpColors.lineStrong, const <BoxShadow>[]),
      NeonButtonVariant.danger => (const Color.fromRGBO(255, 59, 59, 0.12), const Color(0xFFFF8080), const Color.fromRGBO(255, 59, 59, 0.5), const <BoxShadow>[]),
    };
    final (height, hPad, fontSize, radius) = switch (widget.size) {
      NeonButtonSize.small => (36.0, 13.6, 11.2, IpRadius.control),
      NeonButtonSize.medium => (44.0, 20.0, 12.8, IpRadius.control),
      NeonButtonSize.large => (56.0, 28.0, 15.2, IpRadius.controlLg),
    };

    final content = Row(
      mainAxisSize: widget.expand ? MainAxisSize.max : MainAxisSize.min,
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        if (widget.loading)
          SizedBox(width: fontSize + 2, height: fontSize + 2, child: CircularProgressIndicator(strokeWidth: 2, color: fg))
        else if (widget.icon != null)
          Icon(widget.icon, size: fontSize + 4, color: fg),
        if (widget.loading || widget.icon != null) const SizedBox(width: 8),
        Flexible(
          child: Text(
            widget.label.toUpperCase(),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: IpText.display(fontSize, color: fg).copyWith(letterSpacing: fontSize * 0.08),
          ),
        ),
      ],
    );

    return Semantics(
      button: true,
      enabled: _enabled,
      label: widget.label,
      excludeSemantics: true,
      child: Opacity(
        opacity: widget.onPressed == null ? 0.45 : 1,
        child: AnimatedScale(
          scale: _down && _enabled ? 0.97 : 1,
          duration: const Duration(milliseconds: 120),
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: bg,
              borderRadius: BorderRadius.circular(radius),
              border: Border.all(color: border),
              boxShadow: _enabled ? glow : const [],
            ),
            child: Material(
              type: MaterialType.transparency,
              child: InkWell(
                borderRadius: BorderRadius.circular(radius),
                onHighlightChanged: (v) => setState(() => _down = v),
                onTap: _enabled
                    ? () {
                        HapticFeedback.selectionClick();
                        widget.onPressed!();
                      }
                    : null,
                child: ConstrainedBox(
                  constraints: BoxConstraints(minHeight: height, minWidth: height),
                  child: Padding(padding: EdgeInsets.symmetric(horizontal: hPad, vertical: 8), child: Center(widthFactor: widget.expand ? null : 1, child: content)),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
