import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/core/utilities/store_link.dart';
import 'package:ironpulse/core/widgets/credit_badge.dart';

import '../support/harness.dart';
import '../support/pump_app.dart';

void main() {
  testWidgets('the credit is on the account screen and opens the developer\'s LinkedIn page', (tester) async {
    final w = await TestWorld.create();
    await w.storeSession();
    w.serveSignedInMember();
    final opened = <Uri>[];
    await pumpApp(tester, w, extraOverrides: [
      urlOpenerProvider.overrideWithValue((uri) async {
        opened.add(uri);
        return true;
      }),
    ]);
    await pumpUntilHome(tester);

    await tester.tap(find.text('ACCOUNT'));
    await pumpUntil(tester, find.text('ABOUT THIS APP'));
    await tapVisible(tester, find.text(creditName));
    await tester.pump();
    expect(find.text('DESIGNED & DEVELOPED BY'), findsOneWidget);
    expect(opened.single.toString(), creditUrl);
    expect(opened.single.host, 'www.linkedin.com');
  });

  testWidgets('the exercise library has its own tab in the bottom bar', (tester) async {
    final w = await TestWorld.create();
    await w.storeSession();
    w.serveSignedInMember();
    await pumpApp(tester, w);
    await pumpUntilHome(tester);

    await tester.tap(find.descendant(of: find.byType(NavigationBar), matching: find.text('LIBRARY')));
    await pumpUntil(tester, find.text('Exercise 1'));
    expect(find.text('Search exercises'), findsOneWidget);
  });
}
