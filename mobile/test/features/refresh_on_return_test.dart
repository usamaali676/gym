import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

Future<void> _away(WidgetTester tester, TestWorld w, Duration d) async {
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
  w.clock.advance(d);
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
  await tester.pump(const Duration(milliseconds: 300));
}

void main() {
  testWidgets('coming back after a while shows what changed on the server; a short trip does not refetch', (tester) async {
    final w = await TestWorld.create();
    w.serveSignedInMember();
    await w.storeSession();
    await pumpApp(tester, w);
    await pumpUntilHome(tester);
    expect(find.text('START WORKOUT'), findsOneWidget);
    final before = w.server.log.where((l) => l == 'GET /dashboard').length;

    // something changes on the server (a workout was started on the website)
    w.server.reply('GET', '/dashboard', 200, {'data': dashboardJson(openSessionId: 1)});

    await _away(tester, w, const Duration(minutes: 2));
    expect(w.server.log.where((l) => l == 'GET /dashboard').length, before, reason: 'two minutes away is not worth a refetch');
    expect(find.text('START WORKOUT'), findsOneWidget);

    await _away(tester, w, const Duration(minutes: 6));
    await pumpUntil(tester, find.text('RESUME WORKOUT'));
    expect(find.text('START WORKOUT'), findsNothing);
  });

  testWidgets('offline after a long absence the screens keep their last answer', (tester) async {
    final w = await TestWorld.create();
    w.serveSignedInMember();
    await w.storeSession();
    await pumpApp(tester, w);
    await pumpUntilHome(tester);
    w.server.reply('GET', '/dashboard', 503, errorJson('maintenance', 'Back soon'));
    w.server.on('GET', '/dashboard', (_) => FakeReply.json(500, errorJson('server_error', 'down')));

    await _away(tester, w, const Duration(minutes: 30));
    await tester.pump(const Duration(seconds: 1));
    expect(find.text('START WORKOUT'), findsOneWidget, reason: 'the cached dashboard is still shown');
  });
}
