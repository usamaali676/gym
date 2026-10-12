import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/core/widgets/neon_progress.dart';

void main() {
  Future<Size> fillSize(WidgetTester tester, double value) async {
    await tester.pumpWidget(Directionality(
      textDirection: TextDirection.ltr,
      child: Center(child: SizedBox(width: 200, child: NeonProgress(value: value, height: 8))),
    ));
    return tester.getSize(find.descendant(of: find.byType(NeonProgress), matching: find.byType(DecoratedBox)).last);
  }

  testWidgets('the fill is as wide as the value and as tall as the bar', (tester) async {
    final size = await fillSize(tester, 0.4);
    expect(size.width, closeTo(80, 0.5));
    expect(size.height, 8);
  });

  testWidgets('values outside 0..1 are clamped and NaN counts as empty', (tester) async {
    expect((await fillSize(tester, 3)).width, closeTo(200, 0.5));
    expect((await fillSize(tester, -1)).width, 0);
    expect((await fillSize(tester, double.nan)).width, 0);
  });
}
