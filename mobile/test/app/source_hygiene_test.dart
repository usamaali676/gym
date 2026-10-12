import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Cheap guards for the rules the app is built on ("treat the mobile app as an untrusted client", "no credentials in the
/// app", "no WebView"). They read the source, so a change that breaks a rule fails here before anyone reviews it.
void main() {
  final files = [
    for (final f in Directory('lib').listSync(recursive: true))
      if (f is File && f.path.endsWith('.dart') && !f.path.endsWith('.g.dart')) f,
  ];
  // Comments are prose, not code.
  String code(File f) => f.readAsLinesSync().where((l) => !l.trimLeft().startsWith('//')).join('\n');

  List<String> offenders(RegExp pattern, {Set<String> allowIn = const {}}) => [
        for (final f in files)
          if (!allowIn.any(f.path.endsWith) && pattern.hasMatch(code(f))) f.path,
      ];

  test('the source tree was found', () => expect(files.length, greaterThan(80)));

  test('nothing is written to the console or a log (a token or a health number must never end up there)', () {
    expect(offenders(RegExp(r'(?<![\w.])(print|debugPrint|debugPrintStack)\s*\(|developer\.log\s*\(|dart:developer')), isEmpty);
  });

  test('no WebView: every screen is native', () {
    expect(offenders(RegExp('WebView|webview_flutter|InAppWebView')), isEmpty);
    expect(File('pubspec.yaml').readAsStringSync(), isNot(contains('webview')));
  });

  test('cleartext addresses exist only in the development default of the environment file', () {
    expect(offenders(RegExp(r'''['"]http://'''), allowIn: {'environment.dart'}), isEmpty);
  });

  test('certificate checks are never switched off', () {
    expect(offenders(RegExp('badCertificateCallback|allowInsecure|HttpOverrides')), isEmpty);
  });

  test('the Authorization header is built only by the API client and the token refresh', () {
    expect(offenders(RegExp('Bearer '), allowIn: {'api_client.dart', 'session_manager.dart'}), isEmpty);
  });

  test('addresses are opened outside the app only through the checked helper', () {
    expect(offenders(RegExp(r'launchUrl\s*\(|launch\s*\('), allowIn: {'store_link.dart'}), isEmpty);
  });

  test('no credential, key or token is written into the source', () {
    final secret = RegExp(r'''(api[_-]?key|secret|token|passwd|password)\s*[:=]\s*['"][A-Za-z0-9_\-+=][A-Za-z0-9_\-/+=]{11,}['"]''', caseSensitive: false); // a route like '/forgot-password' is not a secret
    expect(offenders(secret), isEmpty);
    expect(offenders(RegExp(r'(sk_live|sk_test|AIza[0-9A-Za-z_\-]{20,}|-----BEGIN [A-Z ]*PRIVATE KEY-----|xox[bp]-)')), isEmpty);
  });

  test('the Android release manifest does not allow cleartext traffic or backups', () {
    final main = File('android/app/src/main/AndroidManifest.xml').readAsStringSync();
    expect(main, isNot(contains('usesCleartextTraffic="true"')), reason: 'only the debug manifest may');
    expect(main, contains('android:allowBackup="false"'));
    expect(main, isNot(contains('android:debuggable')));
    expect(RegExp('<intent-filter>').allMatches(main).length, 1, reason: 'the launcher filter only: no deep links or browsable links');
    expect(File('android/app/src/debug/AndroidManifest.xml').readAsStringSync(), contains('usesCleartextTraffic="true"'));
  });

  test('the iOS app allows no arbitrary loads and registers no URL schemes', () {
    final plist = File('ios/Runner/Info.plist').readAsStringSync();
    expect(plist, isNot(contains('NSAllowsArbitraryLoads')));
    expect(plist, isNot(contains('CFBundleURLTypes')));
  });
}
