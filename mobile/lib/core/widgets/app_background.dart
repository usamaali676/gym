import 'package:flutter/material.dart';

import '../../app/theme/tokens.g.dart';

/// The web's body background: two soft green glows over a near-black vertical gradient, plus the faint grid.
class AppBackground extends StatelessWidget {
  const AppBackground({super.key, required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Stack(fit: StackFit.expand, children: [
      const DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [IpColors.bg0, IpColors.bg1]),
        ),
      ),
      const DecoratedBox(
        decoration: BoxDecoration(
          gradient: RadialGradient(
            center: Alignment(-0.76, -1.1),
            radius: 1.0,
            colors: [Color.fromRGBO(57, 255, 20, 0.08), Color(0x0039FF14)],
          ),
        ),
      ),
      const DecoratedBox(
        decoration: BoxDecoration(
          gradient: RadialGradient(
            center: Alignment(1.0, 1.1),
            radius: 0.9,
            colors: [Color.fromRGBO(0, 230, 118, 0.07), Color(0x0000E676)],
          ),
        ),
      ),
      const IgnorePointer(child: CustomPaint(painter: _GridPainter())),
      child,
    ]);
  }
}

class _GridPainter extends CustomPainter {
  const _GridPainter();

  @override
  void paint(Canvas canvas, Size size) {
    // Same 48 px grid, fading out downwards like the web's radial mask.
    final paint = Paint()
      ..color = const Color.fromRGBO(57, 255, 20, 0.05)
      ..strokeWidth = 1;
    final fade = Paint()
      ..shader = const RadialGradient(
        center: Alignment(0, -1),
        radius: 0.9,
        colors: [Color(0x00000000), Color(0xFF07090A)],
        stops: [0.15, 0.72],
      ).createShader(Offset.zero & size);

    canvas.saveLayer(Offset.zero & size, Paint());
    for (double x = 0; x < size.width; x += 48) {
      canvas.drawLine(Offset(x, 0), Offset(x, size.height), paint);
    }
    for (double y = 0; y < size.height; y += 48) {
      canvas.drawLine(Offset(0, y), Offset(size.width, y), paint);
    }
    canvas.drawRect(Offset.zero & size, fade..blendMode = BlendMode.dstOut);
    canvas.restore();
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
