import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/core/api/api_error.dart';
import 'package:ironpulse/core/api/loaded.dart';
import 'package:ironpulse/core/storage/json_cache.dart';
import 'package:shared_preferences/shared_preferences.dart';

ApiError error(ApiErrorKind kind, {String code = 'x'}) => ApiError(kind: kind, code: code, message: 'm', status: 500);

void main() {
  late SharedPreferences prefs;
  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
  });

  group('JsonCache', () {
    test('round-trips JSON with the time it was saved', () async {
      final cache = JsonCache(prefs, scope: '7');
      await cache.write('dash', {'a': 1}, now: DateTime.utc(2026, 10, 12, 8));
      final entry = cache.read('dash')!;
      expect(entry.data, {'a': 1});
      expect(entry.savedAt, DateTime.utc(2026, 10, 12, 8));
    });

    test("one account never reads another account's entries", () async {
      await JsonCache(prefs, scope: '7').write('dash', {'private': true});
      expect(JsonCache(prefs, scope: '8').read('dash'), isNull);
    });

    test('clearAll removes the entries of every account and leaves other preferences alone', () async {
      await JsonCache(prefs, scope: '7').write('dash', 1);
      await JsonCache(prefs, scope: '8').write('dash', 2);
      await prefs.setString('ip.app_config', 'keep me');

      await JsonCache.clearAll(prefs);

      expect(JsonCache(prefs, scope: '7').read('dash'), isNull);
      expect(JsonCache(prefs, scope: '8').read('dash'), isNull);
      expect(prefs.getString('ip.app_config'), 'keep me');
    });

    test('nothing is ever stored while signed out', () async {
      await JsonCache(prefs, scope: JsonCache.anonymous).write('plans', [1, 2]);
      expect(prefs.getKeys().where((k) => k.startsWith('ip.cache.')), isEmpty);
    });

    test('a write from before a sign-out purge (a request still in flight) is dropped', () async {
      final beforeSignOut = JsonCache(prefs, scope: '7');
      await JsonCache.clearAll(prefs); // the member signs out

      await beforeSignOut.write('dashboard', {'private': true}); // the old response arrives late

      expect(prefs.getKeys().where((k) => k.startsWith('ip.cache.')), isEmpty);
      // a later sign-in works normally
      await JsonCache(prefs, scope: '7').write('dashboard', {'ok': true});
      expect(JsonCache(prefs, scope: '7').read('dashboard')!.data, {'ok': true});
    });

    test('a damaged entry reads as missing', () async {
      await prefs.setString('ip.cache.7.dash', '{broken');
      expect(JsonCache(prefs, scope: '7').read('dash'), isNull);
    });
  });

  group('loadWithCache', () {
    test('a good answer is returned fresh and remembered', () async {
      final cache = JsonCache(prefs, scope: '1');
      final loaded = await loadWithCache<int>(cache: cache, key: 'k', fetch: () async => 41, parse: (j) => (j as int) + 1);
      expect(loaded.data, 42);
      expect(loaded.fromCache, isFalse);
      expect(cache.read('k')!.data, 41, reason: 'the raw JSON is stored, not the parsed object');
    });

    test('offline: the last answer is returned and marked as coming from the cache', () async {
      final cache = JsonCache(prefs, scope: '1');
      await cache.write('k', 41, now: DateTime.utc(2026, 10, 12, 8));

      final loaded = await loadWithCache<int>(cache: cache, key: 'k', fetch: () async => throw ApiError.offline(), parse: (j) => (j as int) + 1);

      expect(loaded.data, 42);
      expect(loaded.fromCache, isTrue);
      expect(loaded.savedAt, DateTime.utc(2026, 10, 12, 8));
    });

    test('a server outage also falls back to the cache', () async {
      final cache = JsonCache(prefs, scope: '1');
      await cache.write('k', 1);
      final loaded = await loadWithCache<int>(cache: cache, key: 'k', fetch: () async => throw error(ApiErrorKind.server), parse: (j) => j as int);
      expect(loaded.fromCache, isTrue);
    });

    test('offline with nothing cached is still an error', () async {
      final cache = JsonCache(prefs, scope: '1');
      await expectLater(
        loadWithCache<int>(cache: cache, key: 'k', fetch: () async => throw ApiError.offline(), parse: (j) => j as int),
        throwsA(isA<ApiError>().having((e) => e.isOffline, 'offline', true)),
      );
    });

    test('"you may not see this" is never masked by old data', () async {
      final cache = JsonCache(prefs, scope: '1');
      await cache.write('k', 1);
      for (final kind in [ApiErrorKind.entitlementRequired, ApiErrorKind.forbidden, ApiErrorKind.notFound, ApiErrorKind.unauthenticated, ApiErrorKind.validation]) {
        await expectLater(
          loadWithCache<int>(cache: cache, key: 'k', fetch: () async => throw error(kind), parse: (j) => j as int),
          throwsA(isA<ApiError>().having((e) => e.kind, 'kind', kind)),
          reason: '$kind',
        );
      }
    });

    test('an entry an older app version wrote that no longer parses is discarded', () async {
      final cache = JsonCache(prefs, scope: '1');
      await cache.write('k', 'not an int');
      await expectLater(
        loadWithCache<int>(cache: cache, key: 'k', fetch: () async => throw ApiError.offline(), parse: (j) => j as int),
        throwsA(isA<ApiError>()),
      );
      expect(cache.read('k'), isNull);
    });

    test('a timeout counts as offline', () async {
      final cache = JsonCache(prefs, scope: '1');
      await cache.write('k', 5);
      final loaded = await loadWithCache<int>(cache: cache, key: 'k', fetch: () async => throw ApiError.from(DioException(requestOptions: RequestOptions(), type: DioExceptionType.receiveTimeout)), parse: (j) => j as int);
      expect(loaded.fromCache, isTrue);
    });
  });
}
