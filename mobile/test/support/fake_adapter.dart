import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';

typedef Handler = FutureOr<FakeReply> Function(RequestOptions options);

/// A scripted reply, or a transport failure.
class FakeReply {
  FakeReply.json(this.status, Object? body, {Map<String, String> headers = const {}})
      : body = body == null ? '' : jsonEncode(body),
        headers = {'content-type': 'application/json', ...headers},
        failure = null;

  FakeReply.fail(DioExceptionType type)
      : status = 0,
        body = '',
        headers = const {},
        failure = type;

  final int status;
  final String body;
  final Map<String, String> headers;
  final DioExceptionType? failure;
}

/// Stands in for the network. Records every request so tests can assert on headers, bodies and counts.
class FakeAdapter implements HttpClientAdapter {
  FakeAdapter(this.handler);

  Handler handler;
  final List<RequestOptions> requests = [];

  int count(String method, String path) => requests.where((r) => r.method == method && r.path == path).length;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    requests.add(options);
    final reply = await handler(options);
    if (reply.failure != null) {
      throw DioException(requestOptions: options, type: reply.failure!, error: 'fake ${reply.failure}');
    }
    return ResponseBody.fromString(reply.body, reply.status, headers: {for (final e in reply.headers.entries) e.key: [e.value]});
  }

  @override
  void close({bool force = false}) {}
}

Map<String, dynamic> tokenJson({String token = '1|ipt_abc', DateTime? expires, DateTime? refreshAfter}) => {
      'access_token': token,
      'token_type': 'Bearer',
      'expires_at': (expires ?? DateTime.now().toUtc().add(const Duration(days: 60))).toIso8601String(),
      'refresh_after': (refreshAfter ?? DateTime.now().toUtc().add(const Duration(days: 30))).toIso8601String(),
    };

Map<String, dynamic> userJson({int id = 7, String name = 'Sam Lifter', bool onboarded = true, bool guest = false}) => {
      'id': id,
      'name': name,
      'email': guest ? null : 'sam@example.com',
      'is_guest': guest,
      'role': 'user',
      'status': 'active',
      'onboarded': onboarded,
      'level': 3,
      'xp_total': 420,
      'workout_streak': 5,
      'longest_workout_streak': 9,
    };

Map<String, dynamic> configJson({String status = 'ok', bool guest = true, bool maintenance = false, String? storeUrl}) => {
      'api_version': 'v1',
      'server_time': DateTime.now().toUtc().toIso8601String(),
      'version_policy': {'platform': 'android', 'status': status, 'min_supported': '1.0.0', 'recommended': '1.0.0', 'latest': '1.0.0', 'store_url': storeUrl, 'release_notes': null},
      'policies': {},
      'maintenance': {'enabled': maintenance, 'message': maintenance ? 'Upgrading the servers.' : null, 'ends_at': null},
      'releases': [],
      'auth': {'guest_mode': guest, 'token_ttl_days': 60, 'refresh_window_days': 30},
      'features': {'coach': false, 'payments': [], 'push': false},
      'settings': {},
      'profile_options': {
        'sex': [
          {'value': 'male', 'label': 'Male'},
          {'value': 'female', 'label': 'Female'},
        ],
        'activity_level': [
          {'value': 'light', 'label': 'Lightly active'},
          {'value': 'moderate', 'label': 'Moderately active'},
        ],
        'fitness_level': [
          {'value': 'beginner', 'label': 'Beginner'},
          {'value': 'intermediate', 'label': 'Intermediate'},
        ],
        'goal': [
          {'value': 'cutting', 'label': 'Cutting (fat loss)'},
          {'value': 'bulking', 'label': 'Bulking (muscle gain)'},
        ],
        'diet_preference': [
          {'value': 'non_veg', 'label': 'Includes meat'},
          {'value': 'veg', 'label': 'Vegetarian'},
        ],
        'unit_system': [
          {'value': 'metric', 'label': 'Metric'},
        ],
        'equipment': [
          {'value': 'barbell', 'label': 'Barbell and plates'},
          {'value': 'dumbbell', 'label': 'Dumbbells'},
        ],
        'training_days': [
          for (var i = 0; i < 7; i++) {'value': i + 1, 'label': const ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][i]},
        ],
      },
    };

Map<String, dynamic> errorJson(String code, String message, {Map<String, dynamic> details = const {}}) => {
      'error': {'code': code, 'message': message, 'details': details, 'request_id': 'req-123'},
    };
