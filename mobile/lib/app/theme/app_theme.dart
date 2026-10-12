import 'package:flutter/material.dart';

import 'tokens.g.dart';
import 'typography.dart';

/// Radii from the web (rem = 16 px).
abstract final class IpRadius {
  static const double card = 17.6; // 1.1rem
  static const double control = 12.8; // 0.8rem
  static const double controlLg = 16; // 1rem
  static const double tile = 14.4; // 0.9rem
}

ThemeData buildAppTheme() {
  const scheme = ColorScheme.dark(
    primary: IpColors.neon,
    onPrimary: Color(0xFF041006),
    secondary: IpColors.neon2,
    onSecondary: Color(0xFF041006),
    tertiary: IpColors.warn,
    error: IpColors.danger,
    onError: Colors.white,
    surface: IpColors.surface,
    onSurface: IpColors.ink,
    onSurfaceVariant: IpColors.muted,
    outline: IpColors.line,
    outlineVariant: IpColors.line,
    surfaceContainerLowest: IpColors.bg0,
    surfaceContainerLow: IpColors.bg1,
    surfaceContainer: IpColors.surface,
    surfaceContainerHigh: IpColors.surface2,
    surfaceContainerHighest: IpColors.surface2,
  );

  final textTheme = TextTheme(
    displayLarge: IpText.display(40, weight: FontWeight.w900, color: IpColors.ink),
    displayMedium: IpText.display(32, weight: FontWeight.w900, color: IpColors.ink),
    displaySmall: IpText.display(26, color: IpColors.ink),
    headlineLarge: IpText.display(24, color: IpColors.ink),
    headlineMedium: IpText.display(21, color: IpColors.ink),
    headlineSmall: IpText.display(18, color: IpColors.ink),
    titleLarge: IpText.display(17, color: IpColors.ink),
    titleMedium: IpText.body(16, weight: FontWeight.w600, color: IpColors.ink),
    titleSmall: IpText.body(14, weight: FontWeight.w600, color: IpColors.ink),
    bodyLarge: IpText.body(16, color: IpColors.ink),
    bodyMedium: IpText.body(14, color: IpColors.ink),
    bodySmall: IpText.body(12.5, color: IpColors.muted),
    labelLarge: IpText.display(12.8, color: IpColors.ink),
    labelMedium: IpText.body(12, weight: FontWeight.w600, color: IpColors.muted),
    labelSmall: IpText.body(11, weight: FontWeight.w600, color: IpColors.muted),
  );

  OutlineInputBorder border(Color c, [double width = 1]) =>
      OutlineInputBorder(borderRadius: BorderRadius.circular(IpRadius.control), borderSide: BorderSide(color: c, width: width));

  return ThemeData(
    useMaterial3: true,
    brightness: Brightness.dark,
    colorScheme: scheme,
    scaffoldBackgroundColor: Colors.transparent, // AppBackground paints the gradient behind every screen
    canvasColor: IpColors.surface,
    fontFamily: IpText.bodyFamily,
    textTheme: textTheme,
    splashFactory: InkSparkle.splashFactory,
    appBarTheme: AppBarTheme(
      backgroundColor: Colors.transparent,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
      titleTextStyle: textTheme.titleLarge,
      iconTheme: const IconThemeData(color: IpColors.ink),
    ),
    dividerTheme: const DividerThemeData(color: IpColors.line, thickness: 1, space: 1),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: const Color.fromRGBO(0, 0, 0, 0.32),
      contentPadding: const EdgeInsets.symmetric(horizontal: 14.4, vertical: 13),
      hintStyle: IpText.body(15, color: const Color(0xFF5F7268)),
      errorStyle: IpText.body(13, color: const Color(0xFFFF8A8A)),
      border: border(IpColors.line),
      enabledBorder: border(IpColors.line),
      focusedBorder: border(IpColors.neon, 1.5),
      errorBorder: border(IpColors.danger),
      focusedErrorBorder: border(IpColors.danger, 1.5),
      disabledBorder: border(IpColors.line),
    ),
    snackBarTheme: SnackBarThemeData(
      backgroundColor: IpColors.surface2,
      contentTextStyle: IpText.body(14, color: IpColors.ink),
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(IpRadius.control),
        side: const BorderSide(color: IpColors.lineStrong),
      ),
    ),
    dialogTheme: DialogThemeData(
      backgroundColor: IpColors.surface,
      surfaceTintColor: Colors.transparent,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(IpRadius.card), side: const BorderSide(color: IpColors.lineStrong)),
      titleTextStyle: textTheme.titleLarge,
    ),
    bottomSheetTheme: const BottomSheetThemeData(
      backgroundColor: IpColors.surface,
      surfaceTintColor: Colors.transparent,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(IpRadius.card)),
        side: BorderSide(color: IpColors.lineStrong),
      ),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: const Color(0xEB0D1210),
      indicatorColor: const Color.fromRGBO(57, 255, 20, 0.14),
      surfaceTintColor: Colors.transparent,
      height: 68,
      labelTextStyle: WidgetStateProperty.resolveWith((states) => IpText.display(
            9.5,
            color: states.contains(WidgetState.selected) ? IpColors.neon : IpColors.muted,
          )),
      iconTheme: WidgetStateProperty.resolveWith((states) => IconThemeData(color: states.contains(WidgetState.selected) ? IpColors.neon : IpColors.muted)),
    ),
    progressIndicatorTheme: const ProgressIndicatorThemeData(color: IpColors.neon, linearTrackColor: Color(0xFF1C2A22)),
    checkboxTheme: CheckboxThemeData(
      fillColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? IpColors.neon : Colors.transparent),
      checkColor: const WidgetStatePropertyAll(Color(0xFF041006)),
      side: const BorderSide(color: IpColors.lineStrong, width: 1.5),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
    ),
    textSelectionTheme: const TextSelectionThemeData(cursorColor: IpColors.neon, selectionColor: Color.fromRGBO(57, 255, 20, 0.3)),
  );
}
