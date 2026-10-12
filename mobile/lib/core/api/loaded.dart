import '../storage/json_cache.dart';
import 'api_error.dart';

/// A parsed server answer and where it came from. [fromCache] drives the "offline, showing saved data" banner.
class Loaded<T> {
  const Loaded(this.data, {this.fromCache = false, this.savedAt});
  final T data;
  final bool fromCache;
  final DateTime? savedAt;
}

/// Fetch from the server; remember the answer; when the network (or the server) is down fall back to the last
/// remembered answer. Errors that mean "you may not see this" (401, 403, 404, 422...) are never papered over with
/// old data.
Future<Loaded<T>> loadWithCache<T>({
  required JsonCache cache,
  required String key,
  required Future<Object?> Function() fetch,
  required T Function(Object? json) parse,
}) async {
  try {
    final json = await fetch();
    final value = parse(json);
    await cache.write(key, json);
    return Loaded(value);
  } on ApiError catch (e) {
    if (e.isOffline || e.kind == ApiErrorKind.server) {
      final entry = cache.read(key);
      if (entry != null) {
        try {
          return Loaded(parse(entry.data), fromCache: true, savedAt: entry.savedAt);
        } catch (_) {
          // An entry written by an older app version that no longer parses: treat as missing.
          await cache.remove(key);
        }
      }
    }
    rethrow;
  }
}
