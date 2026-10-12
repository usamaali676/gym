import 'dart:async';
import 'dart:math';

import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

import '../../app/config/environment.dart';
import 'api_error.dart';

/// Something the whole app must react to, raised from any API call.
sealed class PolicySignal {
  const PolicySignal();
}

/// `426 upgrade_required`: this build is below the server's minimum.
class UpgradeRequiredSignal extends PolicySignal {
  const UpgradeRequiredSignal(this.details);
  final Map<String, dynamic> details;
}

/// `503 maintenance`.
class MaintenanceSignal extends PolicySignal {
  const MaintenanceSignal(this.message, this.details);
  final String message;
  final Map<String, dynamic> details;
}

/// Source of the bearer token. The session manager implements it; the client never stores credentials itself.
abstract interface class AuthTokenSource {
  /// The token to send, refreshed first when it is due. `null` when signed out.
  Future<String?> accessToken();

  /// The server rejected [usedToken] (401). Returns the token to retry with when it was rotated in the meantime,
  /// otherwise signs the session out and returns `null`.
  Future<String?> onUnauthorized(String usedToken);
}

/// A decoded response: `{"data": ..., "meta": ..., "links": ...}`.
class ApiResponse {
  const ApiResponse(this.status, this.json, this.headers);

  final int status;
  final Map<String, dynamic> json;
  final Map<String, List<String>> headers;

  dynamic get data => json['data'];
  Map<String, dynamic> get map => Map<String, dynamic>.from(data as Map);
  List<dynamic> get list => data as List<dynamic>;
  Map<String, dynamic>? get meta => json['meta'] is Map ? Map<String, dynamic>.from(json['meta'] as Map) : null;
  Map<String, dynamic>? get links => json['links'] is Map ? Map<String, dynamic>.from(json['links'] as Map) : null;
  bool get wasReplayed => headers['idempotent-replayed']?.contains('true') ?? false;
}

/// Builds the Dio instance shared by the API client and the token refresher: base URL, timeouts and the app
/// headers the server uses for version enforcement. No credentials are attached here.
Dio buildDio(AppEnvironment env, {HttpClientAdapter? adapter}) {
  final dio = Dio(BaseOptions(
    baseUrl: env.apiRoot,
    connectTimeout: const Duration(seconds: 10),
    sendTimeout: const Duration(seconds: 30),
    receiveTimeout: const Duration(seconds: 30),
    responseType: ResponseType.json,
    // Let every status through so the client maps errors itself (one place, one shape).
    validateStatus: (_) => true,
    headers: {
      'Accept': 'application/json',
      'X-App-Platform': env.platform,
      'X-App-Version': env.appVersion,
    },
  ));
  if (adapter != null) dio.httpClientAdapter = adapter;
  return dio;
}

/// The single door to the Laravel API.
///
/// * attaches the bearer token (rotating it when due) and the app headers,
/// * retries only what is safe: GET/HEAD, and writes that carry an Idempotency-Key, with backoff and jitter,
/// * maps every failure to an [ApiError],
/// * reports 426 / 503 to [signals] and signs out when the server revokes the token.
class ApiClient {
  ApiClient({
    required AppEnvironment env,
    required this._tokens,
    HttpClientAdapter? adapter,
    this.maxAttempts = 3,
    Future<void> Function(Duration)? sleep,
    Random? random,
  })  : _sleep = sleep ?? Future<void>.delayed,
        _random = random ?? Random(),
        _dio = buildDio(env, adapter: adapter);

  final Dio _dio;
  final AuthTokenSource _tokens;
  final Future<void> Function(Duration) _sleep;
  final Random _random;
  final int maxAttempts;
  static const _uuid = Uuid();

  final _signals = StreamController<PolicySignal>.broadcast();
  Stream<PolicySignal> get signals => _signals.stream;

  Future<ApiResponse> get(String path, {Map<String, dynamic>? query, CancelToken? cancel, bool auth = true}) =>
      _send('GET', path, query: query, cancel: cancel, auth: auth);

  Future<ApiResponse> post(String path, {Object? body, Map<String, dynamic>? query, bool idempotent = false, String? idempotencyKey, bool auth = true}) =>
      _send('POST', path, body: body, query: query, key: idempotencyKey ?? (idempotent ? _uuid.v4() : null), auth: auth);

  Future<ApiResponse> put(String path, {Object? body, bool idempotent = false, String? idempotencyKey}) =>
      _send('PUT', path, body: body, key: idempotencyKey ?? (idempotent ? _uuid.v4() : null));

  Future<ApiResponse> patch(String path, {Object? body, bool idempotent = false, String? idempotencyKey}) =>
      _send('PATCH', path, body: body, key: idempotencyKey ?? (idempotent ? _uuid.v4() : null));

  Future<ApiResponse> delete(String path, {Object? body}) => _send('DELETE', path, body: body);

  /// For signed media URLs and other absolute links (no base URL, no bearer token).
  Future<Response<List<int>>> download(String url, {CancelToken? cancel}) =>
      Dio(BaseOptions(responseType: ResponseType.bytes, connectTimeout: const Duration(seconds: 10)))
          .get<List<int>>(url, cancelToken: cancel);

  void dispose() {
    _signals.close();
    _dio.close();
  }

  Future<ApiResponse> _send(
    String method,
    String path, {
    Object? body,
    Map<String, dynamic>? query,
    String? key,
    CancelToken? cancel,
    bool auth = true,
  }) async {
    final safeToRepeat = method == 'GET' || method == 'HEAD' || key != null;
    var attempt = 0;
    var rotatedRetry = false;

    while (true) {
      attempt++;
      final token = auth ? await _tokens.accessToken() : null;
      final headers = <String, dynamic>{
        'Authorization': ?(token == null ? null : 'Bearer $token'),
        'Idempotency-Key': ?key,
      };

      Response<dynamic> response;
      try {
        response = await _dio.request<dynamic>(
          path,
          data: body,
          queryParameters: query,
          cancelToken: cancel,
          options: Options(method: method, headers: headers),
        );
      } on DioException catch (e) {
        final error = ApiError.from(e);
        if (safeToRepeat && attempt < maxAttempts && error.isOffline && e.type != DioExceptionType.cancel) {
          await _sleep(_backoff(attempt));
          continue;
        }
        throw error;
      }

      final status = response.statusCode ?? 0;
      if (status >= 200 && status < 300) {
        return ApiResponse(status, _asMap(response.data), response.headers.map);
      }

      final error = ApiError.from(DioException(requestOptions: response.requestOptions, response: response, type: DioExceptionType.badResponse));

      if (status == 401 && token != null) {
        final replacement = rotatedRetry ? null : await _tokens.onUnauthorized(token);
        if (replacement != null) {
          rotatedRetry = true; // the token was rotated by a concurrent refresh: repeat once with the new one
          continue;
        }
        throw error;
      }

      if (error.kind == ApiErrorKind.upgradeRequired) _signals.add(UpgradeRequiredSignal(error.details));
      if (error.kind == ApiErrorKind.maintenance) _signals.add(MaintenanceSignal(error.message, error.details));

      if (safeToRepeat && attempt < maxAttempts && _retryableStatus(error)) {
        await _sleep(error.retryAfter != null && error.retryAfter! <= const Duration(seconds: 5) ? error.retryAfter! : _backoff(attempt));
        continue;
      }
      throw error;
    }
  }

  /// 429 with a short Retry-After, and gateway errors. Never other 4xx, never maintenance (retrying will not help).
  bool _retryableStatus(ApiError e) {
    final s = e.status ?? 0;
    if (s == 429) return e.retryAfter != null && e.retryAfter! <= const Duration(seconds: 5);
    return s == 502 || s == 504 || (s == 503 && e.kind != ApiErrorKind.maintenance);
  }

  /// 0.4 s, 0.8 s, 1.6 s ... ±25 % jitter so many phones do not retry in lockstep.
  Duration _backoff(int attempt) {
    final baseMs = 400 * pow(2, attempt - 1);
    final jitter = 0.75 + _random.nextDouble() * 0.5;
    return Duration(milliseconds: (baseMs * jitter).round());
  }

  Map<String, dynamic> _asMap(dynamic body) => body is Map ? Map<String, dynamic>.from(body) : <String, dynamic>{};
}
