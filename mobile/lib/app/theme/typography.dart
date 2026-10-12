import 'package:flutter/material.dart';

/// The three web typefaces. Orbitron for headings and buttons, Rajdhani for numbers, Inter for text.
abstract final class IpText {
  static const String displayFamily = 'Orbitron';
  static const String numFamily = 'Rajdhani';
  static const String bodyFamily = 'Inter';

  static TextStyle display(double size, {FontWeight weight = FontWeight.w700, Color? color, double? height}) => TextStyle(
        fontFamily: displayFamily,
        fontSize: size,
        fontWeight: weight,
        letterSpacing: size * 0.02,
        height: height ?? 1.15,
        color: color,
      );

  /// Big readable figures (timers, reps, calories): tabular digits so they do not jitter while counting.
  static TextStyle number(double size, {FontWeight weight = FontWeight.w700, Color? color}) => TextStyle(
        fontFamily: numFamily,
        fontSize: size,
        fontWeight: weight,
        letterSpacing: size * 0.01,
        fontFeatures: const [FontFeature.tabularFigures()],
        color: color,
      );

  static TextStyle body(double size, {FontWeight weight = FontWeight.w400, Color? color, double? height}) => TextStyle(
        fontFamily: bodyFamily,
        fontSize: size,
        fontWeight: weight,
        height: height ?? 1.45,
        color: color,
      );

  /// Small uppercase section label (the web's `.eyebrow`).
  static TextStyle eyebrow({Color? color}) => TextStyle(
        fontFamily: displayFamily,
        fontSize: 11.2,
        fontWeight: FontWeight.w700,
        letterSpacing: 11.2 * 0.22,
        color: color,
      );
}
