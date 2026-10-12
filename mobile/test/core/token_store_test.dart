import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/core/authentication/session.dart';
import 'package:ironpulse/core/authentication/token_store.dart';
import 'package:ironpulse/core/bootstrap.dart';
import 'package:ironpulse/core/storage/secure_store.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  final session = Session(
    accessToken: '9|ipt_secret',
    expiresAt: DateTime.utc(2026, 12, 1),
    refreshAfter: DateTime.utc(2026, 11, 1),
    deviceId: 3,
  );

  test('round-trips a session through secure storage', () async {
    final store = MemorySecureStore();
    final tokens = TokenStore(store);
    await tokens.write(session);

    final back = await TokenStore(store).read();
    expect(back?.accessToken, '9|ipt_secret');
    expect(back?.expiresAt, DateTime.utc(2026, 12, 1));
    expect(back?.refreshAfter, DateTime.utc(2026, 11, 1));
    expect(back?.deviceId, 3);
  });

  test('a corrupt entry reads as signed out and is removed', () async {
    final store = MemorySecureStore()..data['ip.session'] = '{not json';
    expect(await TokenStore(store).read(), isNull);
    expect(store.data.containsKey('ip.session'), isFalse);
  });

  test('clear removes the token and the cached account', () async {
    final store = MemorySecureStore();
    final tokens = TokenStore(store);
    await tokens.write(session);
    await tokens.writeUser({'id': 1, 'email': 'a@b.c'});

    await tokens.clear();

    expect(await tokens.read(), isNull);
    expect(await tokens.readUser(), isNull);
  });

  test('the token only ever goes to the secure store, never to shared preferences', () async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    await TokenStore(MemorySecureStore()).write(session);
    expect(prefs.getKeys(), isEmpty);
  });

  group('wipeStaleSecretsOnFreshInstall', () {
    test('wipes leftovers from a previous install (iOS keychain survives uninstall) exactly once', () async {
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final store = MemorySecureStore()..data['ip.session'] = 'leftover';

      await wipeStaleSecretsOnFreshInstall(prefs, store);
      expect(store.data, isEmpty);

      store.data['ip.session'] = 'created after first launch';
      await wipeStaleSecretsOnFreshInstall(prefs, store);
      expect(store.data['ip.session'], 'created after first launch');
    });
  });
}
