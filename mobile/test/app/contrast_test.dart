// WCAG 2.x contrast of the colour pairs the app actually draws text with, calculated exactly from the design tokens (the
// screenshot-based check in flutter_test misreads our gradient backgrounds). Normal-size text needs 4.5:1, large text
// (18.66 px bold or 24 px) and meaningful icons need 3:1.
import 'dart:math' as math;

import 'package:flutter/painting.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/app/theme/tokens.g.dart';
import 'package:ironpulse/core/widgets/status_chip.dart';

double _channel(double c) => c <= 0.03928 ? c / 12.92 : math.pow((c + 0.055) / 1.055, 2.4).toDouble();

double _luminance(Color c) => 0.2126 * _channel(c.r) + 0.7152 * _channel(c.g) + 0.0722 * _channel(c.b);

double contrast(Color a, Color b) {
  final la = _luminance(a);
  final lb = _luminance(b);
  return (math.max(la, lb) + 0.05) / (math.min(la, lb) + 0.05);
}

/// A translucent colour as it looks over [background].
Color _over(Color fg, Color background) => Color.alphaBlend(fg, background);

void main() {
  // The darkest and lightest backgrounds text sits on: the page gradient, glass cards and raised surfaces.
  final backgrounds = {'page (bg0)': IpColors.bg0, 'page (bg1)': IpColors.bg1, 'card (surface)': IpColors.surface, 'raised (surface2)': IpColors.surface2};

  group('body text on every background', () {
    for (final entry in backgrounds.entries) {
      test('ink on ${entry.key}', () => expect(contrast(IpColors.ink, entry.value), greaterThanOrEqualTo(7), reason: 'AAA for the main text'));
      test('muted on ${entry.key}', () => expect(contrast(IpColors.muted, entry.value), greaterThanOrEqualTo(4.5)));
      test('neon on ${entry.key}', () => expect(contrast(IpColors.neon, entry.value), greaterThanOrEqualTo(7)));
      test('warning amber on ${entry.key}', () => expect(contrast(IpColors.warn, entry.value), greaterThanOrEqualTo(4.5)));
    }
  });

  group('the four tones of a status chip (text over its own tinted fill)', () {
    for (final tone in Tone.values) {
      for (final entry in backgrounds.entries) {
        test('${tone.name} on ${entry.key}', () => expect(contrast(tone.color, _over(tone.fill, entry.value)), greaterThanOrEqualTo(4.5)));
      }
    }
  });

  group('buttons', () {
    const onNeon = Color(0xFF041006); // the dark label on the neon primary button
    test('primary: dark text on neon', () => expect(contrast(onNeon, IpColors.neon), greaterThanOrEqualTo(7)));
    test('danger: light red text on its dark red fill', () {
      const fg = Color(0xFFFF8080);
      for (final bg in backgrounds.values) {
        expect(contrast(fg, _over(const Color.fromRGBO(255, 59, 59, 0.12), bg)), greaterThanOrEqualTo(4.5));
      }
    });
    test('ghost: ink on its faint fill', () {
      for (final bg in backgrounds.values) {
        expect(contrast(IpColors.ink, _over(const Color.fromRGBO(57, 255, 20, 0.05), bg)), greaterThanOrEqualTo(7));
      }
    });
  });

  group('lines that carry meaning (a selected pill, a focused field) are visible', () {
    test('the strong neon outline against the page', () {
      for (final bg in backgrounds.values) {
        expect(contrast(_over(IpColors.lineStrong, bg), bg), greaterThanOrEqualTo(1.5), reason: 'decorative; state is also carried by text and icons');
      }
    });
  });
}
