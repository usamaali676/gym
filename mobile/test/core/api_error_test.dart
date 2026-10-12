import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/core/api/api_error.dart';

import '../support/fake_adapter.dart';

DioException badResponse(int status, Object? body, {Map<String, List<String>> headers = const {}}) {
  final req = RequestOptions(path: '/x');
  return DioException(
    requestOptions: req,
    type: DioExceptionType.badResponse,
    response: Response(requestOptions: req, statusCode: status, data: body, headers: Headers.fromMap(headers)),
  );
}

void main() {
  group('ApiError.from', () {
    test('keeps the server code, message and request id', () {
      final e = ApiError.from(badResponse(404, errorJson('not_found', 'Nope.')));
      expect(e.kind, ApiErrorKind.notFound);
      expect(e.code, 'not_found');
      expect(e.message, 'Nope.');
      expect(e.requestId, 'req-123');
    });

    test('exposes the first message per field of a validation error', () {
      final e = ApiError.from(badResponse(422, errorJson('validation_failed', 'The given data was invalid.', details: {
        'email': ['The email has already been taken.', 'second'],
        'password': ['Too short.'],
      })));
      expect(e.kind, ApiErrorKind.validation);
      expect(e.fieldErrors, {'email': 'The email has already been taken.', 'password': 'Too short.'});
    });

    test('tells entitlement problems apart from plain 403s and names the missing feature', () {
      final ent = ApiError.from(badResponse(403, errorJson('entitlement_required', 'Not in your plan.', details: {'required_entitlement': 'nutrition', 'current_plan': 'free'})));
      expect(ent.kind, ApiErrorKind.entitlementRequired);
      expect(ent.missingEntitlement, 'nutrition');

      final plain = ApiError.from(badResponse(403, errorJson('forbidden', 'No.')));
      expect(plain.kind, ApiErrorKind.forbidden);

      final limit = ApiError.from(badResponse(403, errorJson('limit_reached', 'Plan limit.', details: {'limit': 'custom_foods', 'max': 5})));
      expect(limit.isLimitReached, isTrue);
    });

    test('maps 401, 409, 426 and 429 (with Retry-After)', () {
      expect(ApiError.from(badResponse(401, errorJson('unauthenticated', 'x'))).kind, ApiErrorKind.unauthenticated);
      expect(ApiError.from(badResponse(409, errorJson('conflict', 'x'))).kind, ApiErrorKind.conflict);
      expect(ApiError.from(badResponse(426, errorJson('upgrade_required', 'x'))).kind, ApiErrorKind.upgradeRequired);
      final limited = ApiError.from(badResponse(429, errorJson('rate_limited', 'x'), headers: {'retry-after': ['7']}));
      expect(limited.kind, ApiErrorKind.rateLimited);
      expect(limited.retryAfter, const Duration(seconds: 7));
    });

    test('503 is maintenance only when the server says so', () {
      expect(ApiError.from(badResponse(503, errorJson('maintenance', 'Back soon'))).kind, ApiErrorKind.maintenance);
      expect(ApiError.from(badResponse(503, errorJson('service_unavailable', 'x'))).kind, ApiErrorKind.server);
    });

    test('a body that is not the error envelope still gives a safe message', () {
      final e = ApiError.from(badResponse(500, '<html>boom</html>'));
      expect(e.kind, ApiErrorKind.server);
      expect(e.message, isNot(contains('<html>')));
      expect(e.message, isNotEmpty);
    });

    test('connection failures are offline, timeouts are timeouts', () {
      final req = RequestOptions(path: '/x');
      expect(ApiError.from(DioException(requestOptions: req, type: DioExceptionType.connectionError)).kind, ApiErrorKind.network);
      expect(ApiError.from(DioException(requestOptions: req, type: DioExceptionType.receiveTimeout)).kind, ApiErrorKind.timeout);
      expect(ApiError.offline().isOffline, isTrue);
      expect(ApiError.timedOut().isOffline, isTrue);
    });
  });
}
