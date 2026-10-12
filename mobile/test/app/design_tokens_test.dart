import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/app/theme/app_theme.dart';
import 'package:ironpulse/app/theme/tokens.g.dart';

/// Design QA, numerically: the app's colours are the website's colours. design/tokens.json is exported from
/// resources/css/app.css (the single source of truth) and a PHP/JS test keeps it fresh.
void main() {
  final tokens = jsonDecode(File('../design/tokens.json').readAsStringSync()) as Map<String, dynamic>;
  final colors = Map<String, String>.from(tokens['colors'] as Map);

  int hex(String h) => int.parse(h.substring(1), radix: 16) | 0xFF000000;

  test('every web colour token exists in the app with the same value', () {
    expect(IpColors.bg0.toARGB32(), hex(colors['bg0']!));
    expect(IpColors.bg1.toARGB32(), hex(colors['bg1']!));
    expect(IpColors.surface.toARGB32(), hex(colors['surface']!));
    expect(IpColors.surface2.toARGB32(), hex(colors['surface2']!));
    expect(IpColors.neon.toARGB32(), hex(colors['neon']!));
    expect(IpColors.neon2.toARGB32(), hex(colors['neon2']!));
    expect(IpColors.neonDim.toARGB32(), hex(colors['neon-dim']!));
    expect(IpColors.danger.toARGB32(), hex(colors['danger']!));
    expect(IpColors.warn.toARGB32(), hex(colors['warn']!));
    expect(IpColors.ink.toARGB32(), hex(colors['ink']!));
    expect(IpColors.muted.toARGB32(), hex(colors['muted']!));
  });

  test('the brand values from the brief are what the web actually uses', () {
    expect(colors['neon'], '#39ff14'); // neon green
    expect(colors['bg0'], '#07090a'); // near-black
    expect(colors['surface'], '#111815'); // graphite cards
    expect(colors['warn'], '#ffb020'); // amber (added for posture feedback)
  });

  test('the theme is built from those tokens', () {
    final theme = buildAppTheme();
    expect(theme.brightness, Brightness.dark);
    expect(theme.colorScheme.primary, IpColors.neon);
    expect(theme.colorScheme.error, IpColors.danger);
    expect(theme.colorScheme.tertiary, IpColors.warn);
    expect(theme.colorScheme.onSurface, IpColors.ink);
    expect(theme.colorScheme.surface, IpColors.surface);
  });

  test('the three web typefaces are the ones the app bundles', () {
    expect(IpFontStacks.display.first, 'Orbitron');
    expect(IpFontStacks.num.first, 'Rajdhani');
    expect(IpFontStacks.sans.first, 'Inter');
    final pubspec = File('pubspec.yaml').readAsStringSync();
    for (final family in ['Orbitron', 'Rajdhani', 'Inter']) {
      expect(pubspec, contains('family: $family'));
    }
  });

  test('every bundled font file exists and is not empty', () {
    final pubspec = File('pubspec.yaml').readAsStringSync();
    final assets = RegExp(r'asset: (assets/fonts/[^\s]+)').allMatches(pubspec).map((m) => m[1]!).toList();
    expect(assets, hasLength(9));
    for (final a in assets) {
      expect(File(a).lengthSync(), greaterThan(5000), reason: a);
    }
  });
}
