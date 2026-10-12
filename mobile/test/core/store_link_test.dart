import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/core/utilities/store_link.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';
import '../support/pump_app.dart';

void main() {
  group('which store addresses are ever opened', () {
    test('a plain https address', () {
      expect(safeStoreUri('https://play.google.com/store/apps/details?id=com.example.app')?.host, 'play.google.com');
      expect(safeStoreUri('  https://apps.apple.com/app/id123456789  ')?.path, '/app/id123456789');
      expect(safeStoreUri('HTTPS://apps.apple.com/app/id1')?.scheme, 'https');
    });

    test('everything else is refused', () {
      for (final bad in [
        null,
        '',
        '   ',
        'http://play.google.com/store/apps/details?id=x', // cleartext
        'tel:+1555123456',
        'sms:+1555123456?body=hi',
        'intent://scan/#Intent;scheme=zxing;package=com.example;end',
        'javascript:alert(1)',
        'file:///etc/passwd',
        'market://details?id=com.example',
        'itms-apps://apps.apple.com/app/id1',
        'myapp://open?x=1',
        'https://', // no host
        'https:///path-only',
        'https://user:pass@evil.example/store', // credentials in the address
        'not a url at all',
        'play.google.com/store/apps/details?id=x', // no scheme
        'https://${'a' * 2100}.example',
      ]) {
        expect(safeStoreUri(bad), isNull, reason: '"$bad" must never be opened');
      }
    });
  });

  group('the buttons', () {
    testWidgets('open an acceptable address in the store', (tester) async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.serveSignedInMember(config: configJson(status: 'update_required', storeUrl: 'https://play.google.com/store/apps/details?id=x'));
      final opened = <Uri>[];
      await pumpApp(tester, w, extraOverrides: [
        urlOpenerProvider.overrideWithValue((uri) async {
          opened.add(uri);
          return true;
        }),
      ]);
      await pumpUntil(tester, find.text('Update required'));

      await tester.tap(find.text('OPEN THE STORE'));
      await tester.pump();
      expect(opened.single.toString(), 'https://play.google.com/store/apps/details?id=x');
    });

    testWidgets('an unacceptable address from the server opens nothing and says so', (tester) async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.serveSignedInMember(config: configJson(status: 'update_required', storeUrl: 'intent://evil#Intent;scheme=x;end'));
      final opened = <Uri>[];
      await pumpApp(tester, w, extraOverrides: [
        urlOpenerProvider.overrideWithValue((uri) async {
          opened.add(uri);
          return true;
        }),
      ]);
      await pumpUntil(tester, find.text('Update required'));

      await tester.tap(find.text('OPEN THE STORE'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));
      expect(opened, isEmpty);
      expect(find.textContaining('could not be opened'), findsOneWidget);
    });

    testWidgets('a launcher that fails does not crash and says so', (tester) async {
      final w = await TestWorld.create();
      await w.storeSession();
      w.serveSignedInMember(config: configJson(status: 'update_required', storeUrl: 'https://apps.apple.com/app/id1'));
      await pumpApp(tester, w, extraOverrides: [urlOpenerProvider.overrideWithValue((uri) async => throw StateError('no browser'))]);
      await pumpUntil(tester, find.text('Update required'));

      await tester.tap(find.text('OPEN THE STORE'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));
      expect(find.textContaining('could not be opened'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });
}
