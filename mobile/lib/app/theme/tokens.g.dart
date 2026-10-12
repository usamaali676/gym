// GENERATED FILE. Do not edit by hand.
// Source of truth: resources/css/app.css (@theme). Regenerate with:
//   node scripts/export-design-tokens.mjs
// tests/js/design-tokens.test.mjs fails when this file is out of date.

import 'package:flutter/painting.dart';

/// Brand colours shared with the web app.
abstract final class IpColors {
  static const Color bg0 = Color(0xFF07090A);
  static const Color bg1 = Color(0xFF0D1210);
  static const Color surface = Color(0xFF111815);
  static const Color surface2 = Color(0xFF16201B);
  static const Color neon = Color(0xFF39FF14);
  static const Color neon2 = Color(0xFF00E676);
  static const Color neonDim = Color(0xFF1FA80D);
  static const Color danger = Color(0xFFFF3B3B);
  static const Color warn = Color(0xFFFFB020);
  static const Color ink = Color(0xFFE8F5E9);
  static const Color muted = Color(0xFF8FA39A);
  static const Color line = Color.fromRGBO(57, 255, 20, 0.15);
  static const Color lineStrong = Color.fromRGBO(57, 255, 20, 0.4);
}

/// CSS font stacks from the web theme. The first entry is the bundled family.
abstract final class IpFontStacks {
  static const List<String> display = ['Orbitron', 'Rajdhani', 'ui-sans-serif', 'system-ui', 'sans-serif'];
  static const List<String> num = ['Rajdhani', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'];
  static const List<String> sans = ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'];
}

/// Glow shadows from the web theme.
abstract final class IpShadows {
  static const List<BoxShadow> neon = [
    BoxShadow(color: Color.fromRGBO(57, 255, 20, 0.45), offset: Offset(0, 0), blurRadius: 0, spreadRadius: 1),
    BoxShadow(color: Color.fromRGBO(57, 255, 20, 0.35), offset: Offset(0, 0), blurRadius: 22, spreadRadius: 0),
  ];
  static const List<BoxShadow> neonSm = [
    BoxShadow(color: Color.fromRGBO(57, 255, 20, 0.35), offset: Offset(0, 0), blurRadius: 12, spreadRadius: 0),
  ];
}
