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
      'entitlements': [
        {'key': 'workouts', 'label': 'Workouts and training plan', 'description': 'Plan pages, starting and logging workouts.'},
        {'key': 'programs.premium', 'label': 'Premium programs', 'description': 'Programs an admin marks as premium.'},
        {'key': 'library.premium', 'label': 'Premium exercise library', 'description': 'Exercises an admin marks as premium.'},
        {'key': 'nutrition', 'label': 'Nutrition and food tracking', 'description': 'Diet targets, meal plans, food search and the food log.'},
        {'key': 'posture_coach', 'label': 'Camera posture coach', 'description': 'Live camera form feedback and rep counting.'},
        {'key': 'analytics', 'label': 'Progress analytics and reports', 'description': 'Strength curves, summaries and weekly reports.'},
        {'key': 'progress_photos', 'label': 'Progress photos', 'description': 'Private progress photo uploads.'},
        {'key': 'coach.ai', 'label': 'AI coach', 'description': 'The optional AI coach.'},
      ],
      'limits': [
        {'key': 'custom_foods', 'label': 'Custom foods a member can create'},
        {'key': 'progress_photos', 'label': 'Progress photos a member can keep'},
      ],
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

const allEntitlements = ['workouts', 'programs.premium', 'library.premium', 'nutrition', 'posture_coach', 'analytics', 'progress_photos', 'coach.ai'];

Map<String, bool> entitlementMap({List<String> allow = allEntitlements}) => {for (final k in allEntitlements) k: allow.contains(k)};

Map<String, dynamic> planJson({int id = 1, String slug = 'free', String name = 'Free', bool free = true, List<String> allow = allEntitlements, List<String> features = const [], String? display}) => {
      'id': id,
      'slug': slug,
      'name': name,
      'description': null,
      'is_default': free,
      'price': {'amount_minor': free ? 0 : 999, 'currency': 'USD', 'display': display ?? (free ? 'Free' : 'USD 9.99')},
      'billing': {'interval': free ? 'none' : 'month', 'interval_count': 1, 'duration_days': null, 'trial_days': 0, 'auto_renews': !free},
      'features': features,
      'entitlements': entitlementMap(allow: allow),
      'limits': {'custom_foods': free ? 5 : null, 'progress_photos': null},
      'store_product_ids': {},
      'updated_at': '2026-10-12T10:00:00+00:00',
    };

Map<String, dynamic> membershipJson({Map<String, dynamic>? plan, Map<String, dynamic>? subscription, List<String> allow = allEntitlements, List<String> providers = const []}) => {
      'plan': plan ?? planJson(allow: allow),
      'subscription': subscription,
      'entitlements': entitlementMap(allow: allow),
      'limits': {'custom_foods': 5, 'progress_photos': null},
      'payments': {'available_providers': providers},
      'checked_at': '2026-10-12T10:00:00+00:00',
      'revalidate_after_seconds': 300,
    };

Map<String, dynamic> dashboardJson({
  String status = 'today',
  bool rest = false,
  bool hasDay = true,
  int? openSessionId,
  bool nutrition = true,
  List<String> allow = allEntitlements,
}) =>
    {
      'greeting_period': 'morning',
      'today': '2026-10-12',
      'workout': {
        'status': status,
        'day': hasDay ? {'id': 3, 'weekday': 1, 'title': 'Push day', 'label': 'PUSH', 'focus': 'Chest, shoulders, triceps', 'is_rest': rest} : null,
        'estimated_minutes': rest ? 0 : 55,
        'open_session_id': openSessionId,
      },
      'week': {
        'done': 2,
        'target': 5,
        'days': [
          for (var i = 0; i < 7; i++)
            {'date': '2026-10-${12 + i}', 'weekday': i + 1, 'status': ['completed', 'completed', 'today', 'upcoming', 'upcoming', 'rest', 'rest'][i], 'is_today': i == 2},
        ],
      },
      'streak': 5,
      'xp': {'total': 420, 'level': 3, 'into': 120, 'needed': 300, 'pct': 0.4, 'next_at': 700},
      'nutrition': nutrition
          ? {
              'targets': {'calories': 2400, 'protein_g': 160, 'carbs_g': 280, 'fat_g': 70, 'fibre_g': 30},
              'totals': {'calories': 900.5, 'protein_g': 60, 'carbs_g': 100, 'fat_g': 20, 'fibre_g': 8},
            }
          : null,
      'habits': {},
      'quote': 'Discipline is choosing between what you want now and what you want most.',
      'membership': {'plan_slug': 'free', 'entitlements': entitlementMap(allow: allow)},
    };
