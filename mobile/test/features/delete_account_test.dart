// Deleting the account from inside the app (an Apple and Google Play requirement). Nothing is deleted unless the server says
// so, and everything about the account goes from the phone when it does.
import 'package:dio/dio.dart' show DioExceptionType;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/core/sync/pending_op.dart';
import 'package:ironpulse/core/sync/pending_store.dart';
import 'package:ironpulse/core/storage/json_cache.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

Future<void> _openDelete(WidgetTester tester, TestWorld w, {bool guest = false}) async {
  w.serveSignedInMember(user: userJson(guest: guest));
  await w.storeSession();
  await pumpApp(tester, w);
  await pumpUntilHome(tester);
  await tester.tap(find.text('ACCOUNT'));
  await pumpUntil(tester, find.text('Camera form coach'));
  await tapVisible(tester, find.text('DELETE ACCOUNT'));
  await pumpUntil(tester, find.text('THIS CANNOT BE UNDONE'));
}

void main() {
  testWidgets('says plainly what is deleted, that other devices are signed out, and that store subscriptions must be cancelled in the store', (tester) async {
    final w = await TestWorld.create();
    await _openDelete(tester, w);
    expect(find.textContaining('workouts, sets, personal records, nutrition log, measurements, progress photos'), findsOneWidget);
    expect(find.textContaining('Every device signed in to this account is signed out'), findsOneWidget);
    expect(find.textContaining('does not cancel the subscription. Cancel it in the store'), findsOneWidget);
    expect(find.text('YOUR PASSWORD'), findsOneWidget);
  });

  testWidgets('a wrong password changes nothing and the server\'s message shows on the field', (tester) async {
    final w = await TestWorld.create();
    w.server.reply('DELETE', '/me', 422, errorJson('validation_failed', 'The given data was invalid.', details: {'password': ['The password is incorrect.']}));
    await _openDelete(tester, w);

    await tester.enterText(find.byType(EditableText).first, 'wrong');
    await tester.tap(find.text('DELETE MY ACCOUNT'));
    await pumpUntil(tester, find.text('The password is incorrect.'));
    expect(find.text('THIS CANNOT BE UNDONE'), findsOneWidget, reason: 'still here, still signed in');
    expect(w.store.data['ip.session'], isNotNull);
  });

  testWidgets('an empty password is refused before anything is sent', (tester) async {
    final w = await TestWorld.create();
    await _openDelete(tester, w);
    await tester.tap(find.text('DELETE MY ACCOUNT'));
    await tester.pump();
    expect(find.text('Enter your password to confirm.'), findsOneWidget);
    expect(w.server.log, isNot(contains('DELETE /me')));
  });

  testWidgets('with the right password the account is deleted, the phone forgets everything about it, and the welcome screen says so', (tester) async {
    final w = await TestWorld.create();
    w.server.reply('DELETE', '/me', 200, {'data': {'ok': true}});
    await _openDelete(tester, w);

    // something private on the phone: a cached answer and a change that never reached the server
    await JsonCache(w.prefs, scope: '7').write('dashboard', {'secret': true});
    final queue = PrefsPendingStore(w.prefs);
    await queue.write('7', [PendingOp(opId: 'a', type: OpTypes.setLog, payload: const {'session_id': 1}, createdAt: DateTime.utc(2026, 10, 12), label: 'a set')]);

    await tester.enterText(find.byType(EditableText).first, 'correct-horse-9');
    await tester.tap(find.text('DELETE MY ACCOUNT'));
    await pumpUntil(tester, find.text('Your account has been deleted.'));

    final request = w.server.adapter.requests.singleWhere((r) => r.method == 'DELETE' && r.path == '/me');
    expect(request.data, {'password': 'correct-horse-9'});
    expect(w.store.data['ip.session'], isNull, reason: 'the token is gone from secure storage');
    expect(JsonCache(w.prefs, scope: '7').read('dashboard'), isNull, reason: 'cached answers are gone');
    expect(queue.read('7'), isEmpty, reason: 'changes waiting for an account that no longer exists are discarded');
    expect(find.text('SIGN IN'), findsOneWidget);
  });

  testWidgets('a guest confirms by typing DELETE and sends no password', (tester) async {
    final w = await TestWorld.create();
    w.server.reply('DELETE', '/me', 200, {'data': {'ok': true}});
    await _openDelete(tester, w, guest: true);
    expect(find.text('TYPE DELETE TO CONFIRM'), findsOneWidget);
    expect(find.textContaining('no way to get it back on another device'), findsOneWidget);

    await tester.enterText(find.byType(EditableText).first, 'delete me');
    await tester.tap(find.text('DELETE MY ACCOUNT'));
    await tester.pump();
    expect(find.text('Type DELETE to confirm.'), findsOneWidget);
    expect(w.server.log, isNot(contains('DELETE /me')));

    await tester.enterText(find.byType(EditableText).first, 'DELETE');
    await tester.tap(find.text('DELETE MY ACCOUNT'));
    await pumpUntil(tester, find.text('Your account has been deleted.'));
    expect((w.server.adapter.requests.singleWhere((r) => r.method == 'DELETE' && r.path == '/me')).data, {'confirm': 'DELETE'});
  });

  testWidgets('without a connection nothing is deleted and the member is told', (tester) async {
    final w = await TestWorld.create();
    w.server.on('DELETE', '/me', (_) => FakeReply.fail(DioExceptionType.connectionError));
    await _openDelete(tester, w);
    await tester.enterText(find.byType(EditableText).first, 'correct-horse-9');
    await tester.tap(find.text('DELETE MY ACCOUNT'));
    await pumpUntil(tester, find.textContaining('Your account was not deleted'));
    expect(find.text('THIS CANNOT BE UNDONE'), findsOneWidget);
    expect(w.store.data['ip.session'], isNotNull);
  });

  testWidgets('unsent changes are named before the member decides', (tester) async {
    final w = await TestWorld.create();
    await w.storeSession();
    await PrefsPendingStore(w.prefs).write('7', [
      PendingOp(opId: 'a', type: OpTypes.setLog, payload: const {'session_id': 1}, createdAt: DateTime.utc(2026, 10, 12), label: 'a set'),
      PendingOp(opId: 'b', type: OpTypes.workoutFinish, payload: const {'session_id': 1}, createdAt: DateTime.utc(2026, 10, 12), label: 'finish'),
    ]);
    w.serveSignedInMember();
    w.server.on('POST', '/sync/batch', (_) => FakeReply.fail(DioExceptionType.connectionError));
    await pumpApp(tester, w);
    await pumpUntilHome(tester);
    await tester.tap(find.text('ACCOUNT'));
    await pumpUntil(tester, find.text('Camera form coach'));
    await tapVisible(tester, find.text('DELETE ACCOUNT'));
    await pumpUntil(tester, find.text('THIS CANNOT BE UNDONE'));
    expect(find.textContaining('2 changes that have not reached the server yet will be discarded'), findsOneWidget);
  });
}
