import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/app/app.dart';
import 'package:ironpulse/app/theme/app_theme.dart';

import 'package:ironpulse/core/providers.dart';

import 'harness.dart';

/// Phone-sized surface (360 x 740 logical px) so layout problems show up as overflow errors.
void usePhoneScreen(WidgetTester tester) {
  tester.view.physicalSize = const Size(360, 740);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

/// Pumps frames until [finder] matches (screens with spinners never "settle").
Future<void> pumpUntil(WidgetTester tester, Finder finder, {int maxFrames = 80}) async {
  for (var i = 0; i < maxFrames; i++) {
    await tester.pump(const Duration(milliseconds: 50));
    if (finder.evaluate().isNotEmpty) return;
  }
  throw TestFailure('Never found ${finder.describeMatch(Plurality.one)} after $maxFrames frames');
}

Future<void> pumpApp(WidgetTester tester, TestWorld world) async {
  usePhoneScreen(tester);
  await tester.pumpWidget(ProviderScope(retry: noAutoRetry, overrides: world.overrides, child: const IronPulseApp()));
}

/// A single screen inside the real theme, without the router.
Future<void> pumpScreen(WidgetTester tester, TestWorld world, Widget screen) async {
  usePhoneScreen(tester);
  await tester.pumpWidget(ProviderScope(
    retry: noAutoRetry,
    overrides: world.overrides,
    child: MaterialApp(theme: buildAppTheme(), home: screen),
  ));
  await tester.pump();
}

/// The signed-in home is ready once the dashboard has loaded (its header shows while loading).
Future<void> pumpUntilHome(WidgetTester tester) async {
  await pumpUntil(tester, find.text('DAY STREAK'));
  await tester.pump(const Duration(milliseconds: 300)); // let any refresh that started with the screen finish
}

/// Scrolls [finder] into view (building it first if the list is lazy), then taps it. A plain tap on something below
/// the fold lands on whatever is on top of that spot (often the bottom navigation).
Future<void> tapVisible(WidgetTester tester, Finder finder) async {
  if (finder.evaluate().isEmpty) {
    await tester.dragUntilVisible(finder, find.byType(Scrollable).last, const Offset(0, -200));
  }
  await tester.ensureVisible(finder);
  await tester.pump(const Duration(milliseconds: 100));
  await tester.tap(finder);
}
