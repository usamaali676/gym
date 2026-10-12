import 'package:dio/dio.dart';

enum ApiErrorKind {
  /// No connection / DNS / TLS failure: the request never got an answer.
  network,
  timeout,
  unauthenticated,
  forbidden,
  entitlementRequired,
  notFound,
  validation,
  conflict,
  rateLimited,
  upgradeRequired,
  maintenance,
  server,
  unknown,
}

/// Every failure from the API client, already mapped. UI code switches on [kind] or [code], never on raw Dio errors.
class ApiError implements Exception {
  const ApiError({
    required this.kind,
    required this.code,
    required this.message,
    this.status,
    this.details = const {},
    this.requestId,
    this.retryAfter,
  });

  final ApiErrorKind kind;

  /// Stable machine code from the server (`validation_failed`, `entitlement_required`, ...) or a client-side one
  /// (`offline`, `timeout`).
  final String code;

  /// Text that is safe to show to the member.
  final String message;
  final int? status;
  final Map<String, dynamic> details;
  final String? requestId;
  final Duration? retryAfter;

  bool get isOffline => kind == ApiErrorKind.network || kind == ApiErrorKind.timeout;

  /// Field messages of a 422: `{"email": ["The email has already been taken."]}`.
  Map<String, String> get fieldErrors {
    if (kind != ApiErrorKind.validation) return const {};
    final out = <String, String>{};
    details.forEach((field, value) {
      if (value is List && value.isNotEmpty) {
        out[field] = value.first.toString();
      } else if (value is String) {
        out[field] = value;
      }
    });
    return out;
  }

  /// The entitlement a 403 `entitlement_required` names, if any.
  String? get missingEntitlement {
    final v = details['required_entitlement'];
    return v is String ? v : null;
  }

  /// A plan limit (custom foods, photos, ...) was reached: `403 limit_reached`.
  bool get isLimitReached => code == 'limit_reached';

  static ApiError offline() => const ApiError(
        kind: ApiErrorKind.network,
        code: 'offline',
        message: 'Cannot reach the server. Check your connection and try again.',
      );

  static ApiError timedOut() => const ApiError(
        kind: ApiErrorKind.timeout,
        code: 'timeout',
        message: 'The server took too long to answer. Try again.',
      );

  /// Builds an [ApiError] from a failed Dio call (or returns the one already attached).
  static ApiError from(Object error) {
    if (error is ApiError) return error;
    if (error is DioException) {
      if (error.error is ApiError) return error.error! as ApiError;
      return _fromDio(error);
    }
    return const ApiError(kind: ApiErrorKind.unknown, code: 'unknown', message: 'Something went wrong. Try again.');
  }

  static ApiError _fromDio(DioException e) {
    switch (e.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
        return timedOut();
      case DioExceptionType.connectionError:
        return offline();
      case DioExceptionType.cancel:
        return const ApiError(kind: ApiErrorKind.unknown, code: 'cancelled', message: 'Cancelled.');
      case DioExceptionType.badCertificate:
        return const ApiError(
          kind: ApiErrorKind.network,
          code: 'bad_certificate',
          message: 'The secure connection to the server could not be verified.',
        );
      case DioExceptionType.badResponse:
      case DioExceptionType.transformTimeout:
      case DioExceptionType.unknown:
        break;
    }

    final response = e.response;
    if (response == null) return offline();

    final status = response.statusCode ?? 0;
    final body = response.data;
    var code = 'http_$status';
    var message = '';
    var details = <String, dynamic>{};
    String? requestId = response.headers.value('x-request-id');

    if (body is Map && body['error'] is Map) {
      final err = body['error'] as Map;
      code = (err['code'] ?? code).toString();
      message = (err['message'] ?? '').toString();
      if (err['details'] is Map) details = Map<String, dynamic>.from(err['details'] as Map);
      requestId = (err['request_id'] ?? requestId)?.toString();
    }

    final retryHeader = int.tryParse(response.headers.value('retry-after') ?? '');
    final retryAfter = retryHeader != null ? Duration(seconds: retryHeader) : null;

    final kind = switch (status) {
      401 => ApiErrorKind.unauthenticated,
      403 when code == 'entitlement_required' => ApiErrorKind.entitlementRequired,
      403 => ApiErrorKind.forbidden,
      404 => ApiErrorKind.notFound,
      409 => ApiErrorKind.conflict,
      422 => ApiErrorKind.validation,
      426 => ApiErrorKind.upgradeRequired,
      429 => ApiErrorKind.rateLimited,
      503 when code == 'maintenance' => ApiErrorKind.maintenance,
      >= 500 => ApiErrorKind.server,
      _ => ApiErrorKind.unknown,
    };

    if (message.isEmpty) {
      message = switch (kind) {
        ApiErrorKind.server => 'The server had a problem. Try again in a moment.',
        ApiErrorKind.rateLimited => 'Too many attempts. Wait a moment and try again.',
        _ => 'Something went wrong. Try again.',
      };
    }

    return ApiError(
      kind: kind,
      code: code,
      message: message,
      status: status,
      details: details,
      requestId: requestId,
      retryAfter: retryAfter,
    );
  }

  @override
  String toString() => 'ApiError($code, $status, $message${requestId == null ? '' : ', request $requestId'})';
}
