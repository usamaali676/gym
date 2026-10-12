import 'package:flutter/material.dart';

import '../../app/theme/tokens.g.dart';
import '../../app/theme/typography.dart';
import '../api/api_error.dart';
import 'glass_card.dart';
import 'neon_button.dart';

/// Nothing to show yet. Say why and, when there is something to do about it, offer it.
class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.title, this.message, this.icon = Icons.inbox_outlined, this.actionLabel, this.onAction});

  final String title;
  final String? message;
  final IconData icon;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) => _Centered(
        icon: icon,
        iconColor: IpColors.neonDim,
        title: title,
        message: message,
        action: actionLabel != null && onAction != null ? NeonButton(label: actionLabel!, onPressed: onAction, variant: NeonButtonVariant.ghost) : null,
      );
}

/// A load failed. Offline gets its own wording; anything else shows the server's message and the request id so a
/// member can quote it to support.
class ErrorState extends StatelessWidget {
  const ErrorState({super.key, required this.error, this.onRetry});

  final Object error;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final e = ApiError.from(error);
    return _Centered(
      icon: e.isOffline ? Icons.cloud_off_outlined : Icons.error_outline,
      iconColor: e.isOffline ? IpColors.warn : IpColors.danger,
      title: e.isOffline ? 'You are offline' : 'Something went wrong',
      message: e.message,
      footnote: e.requestId == null || e.isOffline ? null : 'Reference ${e.requestId}',
      action: onRetry == null ? null : NeonButton(label: 'Try again', onPressed: onRetry, icon: Icons.refresh),
    );
  }
}

class _Centered extends StatelessWidget {
  const _Centered({required this.icon, required this.iconColor, required this.title, this.message, this.footnote, this.action});

  final IconData icon;
  final Color iconColor;
  final String title;
  final String? message;
  final String? footnote;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 44, color: iconColor),
          const SizedBox(height: 14),
          Text(title, textAlign: TextAlign.center, style: IpText.display(17, color: IpColors.ink)),
          if (message != null) ...[
            const SizedBox(height: 8),
            Text(message!, textAlign: TextAlign.center, style: IpText.body(14, color: IpColors.muted)),
          ],
          if (footnote != null) ...[
            const SizedBox(height: 8),
            SelectableText(footnote!, textAlign: TextAlign.center, style: IpText.body(11.5, color: IpColors.muted)),
          ],
          if (action != null) ...[const SizedBox(height: 18), action!],
        ]),
      ),
    );
  }
}

/// Grey-green shimmer block for content that is loading.
class SkeletonBox extends StatefulWidget {
  const SkeletonBox({super.key, this.width, this.height = 16, this.radius = 8});
  final double? width;
  final double height;
  final double radius;

  @override
  State<SkeletonBox> createState() => _SkeletonBoxState();
}

class _SkeletonBoxState extends State<SkeletonBox> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400))..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Respect "reduce motion": a static block instead of the sweep.
    final animate = !MediaQuery.of(context).disableAnimations;
    return AnimatedBuilder(
      animation: _c,
      builder: (context, _) {
        final t = animate ? _c.value : 0.5;
        return Container(
          width: widget.width,
          height: widget.height,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(widget.radius),
            gradient: LinearGradient(
              begin: Alignment(-1 + 2 * t - 0.6, 0),
              end: Alignment(-1 + 2 * t + 0.6, 0),
              colors: const [Color(0xFF14201A), Color(0xFF1C2A22), Color(0xFF14201A)],
            ),
          ),
        );
      },
    );
  }
}

/// A card-shaped placeholder list for first loads.
class SkeletonList extends StatelessWidget {
  const SkeletonList({super.key, this.count = 4});
  final int count;

  @override
  Widget build(BuildContext context) => Column(children: [
        for (var i = 0; i < count; i++)
          const Padding(
            padding: EdgeInsets.only(bottom: 12),
            child: GlassCard(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                SkeletonBox(width: 140, height: 14),
                SizedBox(height: 10),
                SkeletonBox(height: 12),
                SizedBox(height: 6),
                SkeletonBox(width: 220, height: 12),
              ]),
            ),
          ),
      ]);
}

/// Thin amber strip shown while the device is offline.
class OfflineBanner extends StatelessWidget {
  const OfflineBanner({super.key, this.message = 'Offline. Showing saved data.'});
  final String message;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      liveRegion: true,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 7),
        color: const Color.fromRGBO(255, 176, 32, 0.14),
        child: Row(children: [
          const Icon(Icons.cloud_off_outlined, size: 16, color: IpColors.warn),
          const SizedBox(width: 8),
          Expanded(child: Text(message, style: IpText.body(12.5, weight: FontWeight.w600, color: IpColors.warn))),
        ]),
      ),
    );
  }
}
