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
      'auth': {'guest_mode': guest, 'token_ttl_days': 60, 'refresh_window_days': 30, 'password': {'min': 8, 'letters': true, 'numbers': true}},
      'features': {'coach': false, 'payments': [], 'push': false},
      'settings': {},
      'library_options': {
        'muscle': [
          {'value': 'chest', 'label': 'Chest'},
          {'value': 'back', 'label': 'Back'},
          {'value': 'quads', 'label': 'Quads'},
        ],
        'difficulty': [
          {'value': 'beginner', 'label': 'Beginner'},
          {'value': 'advanced', 'label': 'Advanced'},
        ],
      },
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

Map<String, dynamic> dayHeaderJson(int weekday, {String title = 'Push day', bool rest = false, String focus = 'Chest, shoulders, triceps'}) =>
    {'id': weekday + 10, 'weekday': weekday, 'name': 'Day $weekday', 'slug': 'day-$weekday', 'session_type': rest ? 'rest' : 'push', 'label': rest ? 'REST' : 'PUSH', 'focus': focus, 'title': title, 'is_rest': rest};

Map<String, dynamic> weekPlanJson({DateTime? monday, bool thisWeek = true, int done = 2, int target = 4}) {
  final m = monday ?? DateTime.utc(2026, 10, 12);
  const names = ['Push day', 'Pull day', 'Legs', 'Rest', 'Upper', 'Lower', 'Rest'];
  const statuses = ['completed', 'completed', 'today', 'rest', 'upcoming', 'upcoming', 'rest'];
  String d(DateTime x) => '${x.year}-${x.month.toString().padLeft(2, '0')}-${x.day.toString().padLeft(2, '0')}';
  return {
    'program': {'id': 1, 'name': 'Strength Block'},
    'week_number': thisWeek ? 3 : 4,
    'monday': d(m),
    'sunday': d(m.add(const Duration(days: 6))),
    'is_this_week': thisWeek,
    'progress': {'done': done, 'target': target},
    'days': [
      for (var i = 0; i < 7; i++)
        {
          'date': d(m.add(Duration(days: i))),
          'weekday': i + 1,
          'status': statuses[i],
          'is_today': thisWeek && i == 2,
          'is_training': names[i] != 'Rest',
          'day': dayHeaderJson(i + 1, title: names[i], rest: names[i] == 'Rest'),
          'session': i < 2 ? {'id': 100 + i, 'status': 'completed'} : null,
        },
    ],
  };
}

Map<String, dynamic> planExerciseJson({int item = 1, String name = 'Bench press', int sets = 4, String reps = '6-8', bool withTarget = true, String? notes = 'Pause on the chest.'}) => {
      'item_id': item,
      'position': item,
      'name': name,
      'swapped': false,
      'permanent_swap': false,
      'original_exercise_id': null,
      'exercise': {'id': 20 + item, 'slug': 'bench-press', 'name': name, 'muscle_group': 'chest', 'equipment': ['barbell'], 'is_timed': false},
      'sets': sets,
      'reps_min': 6,
      'reps_max': 8,
      'reps_label': reps,
      'focus': 'Chest',
      'notes': notes,
      'rest_seconds': 120,
      'rest_label': '2-3 min',
      'target': withTarget ? {'last': '60 kg × 8, 8, 7', 'next': '62.5 kg × 8', 'reason': 'You hit the top of the range on every set.'} : null,
    };

Map<String, dynamic> dayDetailJson({
  int weekday = 3,
  String date = '2026-10-14',
  String status = 'today',
  bool canAct = true,
  bool future = false,
  bool rest = false,
  Map<String, dynamic>? session,
}) =>
    {
      'date': date,
      'weekday': weekday,
      'week_number': 3,
      'status': status,
      'estimated_minutes': 55,
      'can_act': canAct,
      'is_future': future,
      'day': {...dayHeaderJson(weekday, title: rest ? 'Rest day' : 'Legs', rest: rest, focus: 'Quads and hamstrings'), 'description': rest ? null : 'Heavy squats first.', 'recovery_note': rest ? 'Walk and stretch.' : null},
      'session': session,
      'exercises': rest ? [] : [planExerciseJson(), planExerciseJson(item: 2, name: 'Romanian deadlift', sets: 3, reps: '8-10', withTarget: false, notes: null)],
    };

Map<String, dynamic> programmeJson({int id = 1, String name = 'Strength Block', bool selected = true, bool locked = false, String? entitlement}) => {
      'id': id,
      'slug': 'p$id',
      'name': name,
      'description': 'Heavy compound lifts, three weeks on.',
      'source': 'gym',
      'version': 1,
      'is_default': id == 1,
      'is_selected': selected,
      'required_entitlement': entitlement,
      'locked': locked,
      'training_days': 4,
      'phases_count': 2,
      'updated_at': '2026-10-01T10:00:00+00:00',
    };

Map<String, dynamic> programmeDetailJson({int id = 1, bool selected = true}) => {
      ...programmeJson(id: id, selected: selected),
      'rules': {},
      'phases': [
        {'id': 1, 'kind': 'sprint', 'name': 'Foundation sprint', 'summary': 'Learn the lifts.', 'starts_week': 1, 'ends_week': 4, 'settings': {}},
        {'id': 2, 'kind': 'phase', 'name': 'Strength phase', 'summary': 'Add load.', 'starts_week': 5, 'ends_week': 5, 'settings': {}},
      ],
      'days': [
        {
          'day_of_week': 1,
          'slug': 'push',
          'name': 'Monday',
          'session_type': 'push',
          'label': 'PUSH',
          'title': 'Push day',
          'focus': 'Chest',
          'is_rest': false,
          'description': null,
          'recovery_note': null,
          'estimated_minutes': 50,
          'exercises': [
            {'id': 1, 'position': 1, 'exercise_id': 21, 'exercise_slug': 'bench-press', 'name': 'Bench press', 'muscle_group': 'chest', 'sets': 4, 'reps_min': 6, 'reps_max': 8, 'reps_label': '6-8', 'focus': null, 'notes': null, 'rest_seconds': 120, 'is_timed': false, 'hold_seconds': null},
          ],
        },
        {'day_of_week': 7, 'slug': 'rest', 'name': 'Sunday', 'session_type': 'rest', 'label': 'REST', 'title': 'Rest', 'focus': null, 'is_rest': true, 'description': null, 'recovery_note': 'Sleep well.', 'estimated_minutes': 0, 'exercises': []},
      ],
    };

Map<String, dynamic> exerciseJson(int id, {String? name, String muscle = 'chest', String difficulty = 'beginner', bool locked = false, bool full = false, bool posture = false, List<Map<String, dynamic>> media = const []}) {
  final base = {
    'id': id,
    'slug': 'exercise-$id',
    'name': name ?? 'Exercise $id',
    'kind': 'plan',
    'muscle_group': muscle,
    'muscle_group_label': muscle[0].toUpperCase() + muscle.substring(1),
    'secondary_groups': ['triceps'],
    'mechanic': 'compound',
    'difficulty': difficulty,
    'equipment': ['barbell'],
    'is_timed': false,
    'per_side': false,
    'mobility_type': null,
    'required_entitlement': locked ? 'library.premium' : null,
    'locked': locked,
    'updated_at': '2026-10-01T10:00:00+00:00',
  };
  if (locked || !full) return base;
  return {
    ...base,
    'target_regions': {'primary': ['chest'], 'secondary': []},
    'rest': {'min_seconds': 90, 'max_seconds': 180, 'default_seconds': 120},
    'default_hold_seconds': null,
    'description': 'A horizontal press with a barbell.',
    'form_cues': ['Shoulder blades together', 'Feet planted'],
    'common_mistakes': ['Bouncing the bar', 'Flaring elbows'],
    'breathing': 'Inhale down, exhale up.',
    'demo_key': 'bench-press',
    'media': media,
    'posture': posture ? {'supported': true, 'tier': 'tier1', 'camera_view': 'side', 'rule_version': 1} : {'supported': false},
  };
}

/// A paginated `/exercises` answer: [total] exercises, [perPage] per page, honouring ?page=, ?q=, ?muscle=.
FakeReply exercisePageReply(RequestOptions o, {int total = 45, int perPage = 20, Set<int> locked = const {}}) {
  final page = int.tryParse(o.uri.queryParameters['page'] ?? '1') ?? 1;
  final muscle = o.uri.queryParameters['muscle'];
  final q = o.uri.queryParameters['q'];
  var ids = [for (var i = 1; i <= total; i++) i];
  if (q != null) ids = ids.where((i) => 'Exercise $i'.toLowerCase().contains(q.toLowerCase())).toList();
  final last = (ids.length / perPage).ceil().clamp(1, 9999);
  final slice = ids.skip((page - 1) * perPage).take(perPage);
  return FakeReply.json(200, {
    'data': [for (final i in slice) exerciseJson(i, locked: locked.contains(i), muscle: muscle ?? 'chest')],
    'links': {},
    'meta': {'current_page': page, 'last_page': last, 'per_page': perPage, 'total': ids.length},
  });
}

// ------------------------------------------------------------------------------------------------ workouts

Map<String, dynamic> sessionExerciseJson(int id, String name, {int sets = 3, int rest = 90, bool timed = false, int? hold, List<Map<String, dynamic>> logged = const [], String status = 'pending', double? weight = 60, int reps = 8}) => {
      'id': id,
      'exercise_id': id + 100,
      'slug': name.toLowerCase().replaceAll(' ', '-'),
      'name': name,
      'swapped_from': null,
      'can_swap': true,
      'position': id,
      'status': status,
      'muscle_group': 'chest',
      'muscle_group_label': 'Chest',
      'mechanic': 'compound',
      'equipment': ['barbell'],
      'description': 'A press.',
      'form_cues': ['Shoulder blades together', 'Drive through the floor'],
      'common_mistakes': ['Bouncing the bar'],
      'breathing': 'Inhale down.',
      'target_regions': {'primary': ['chest'], 'secondary': []},
      'focus': 'Chest',
      'is_timed': timed,
      'hold_seconds': hold,
      'target_sets': sets,
      'reps_min': 6,
      'reps_max': 8,
      'rest_seconds': rest,
      'rest_label': '1-2 min',
      'suggested': {'weight_kg': timed ? null : weight, 'reps': timed ? null : reps, 'reason': 'Add a little weight.'},
      'has_history': true,
      'last': timed
          ? null
          : {
              'date': '2026-10-05',
              'sets': [
                {'weight': 57.5, 'reps': 8, 'duration': null},
                {'weight': 57.5, 'reps': 8, 'duration': null},
                {'weight': 57.5, 'reps': 7, 'duration': null},
              ],
            },
      'posture_supported': false,
      'sets': logged,
    };

Map<String, dynamic> sessionJson({
  int id = 1,
  String status = 'in_progress',
  DateTime? startedAt,
  DateTime? serverTime,
  List<Map<String, dynamic>>? exercises,
  Map<String, dynamic>? clientState,
  bool sound = false,
}) {
  final started = startedAt ?? DateTime.utc(2026, 10, 12, 18);
  return {
    'id': id,
    'client_uuid': '11111111-1111-4111-8111-111111111111',
    'status': status,
    'source': 'plan',
    'title': 'Push day',
    'plan_day': {'id': 3, 'weekday': 1, 'name': 'Monday'},
    'scheduled_for': '2026-10-12',
    'week_number': 3,
    'started_at': started.toIso8601String(),
    'ended_at': null,
    'active_seconds': 0,
    'volume_kg': null,
    'sets_completed': 0,
    'xp_earned': 0,
    'client_state': clientState,
    'server_time': (serverTime ?? started).toIso8601String(),
    'preferences': {'sound': sound, 'voice': false},
    'exercises': exercises ?? [sessionExerciseJson(1, 'Bench press'), sessionExerciseJson(2, 'Plank', sets: 1, rest: 0, timed: true, hold: 30)],
  };
}

Map<String, dynamic> summaryJson() => {
      'session': {'id': 1, 'title': 'Push day', 'scheduled_for': '2026-10-12', 'active_seconds': 1800, 'volume_kg': 2160.0, 'sets_completed': 4, 'xp_earned': 190},
      'exercises_done': 2,
      'exercises_total': 2,
      'xp_breakdown': {'set_complete': 40, 'personal_record': 25, 'workout_complete': 125},
      'personal_records': [
        {'exercise': 'Bench press', 'weight_kg': 62.5, 'reps': 8, 'type': 'est_1rm', 'value': 79.2, 'previous': 76.0},
      ],
      'level_up': true,
      'level': 4,
      'streak': 6,
      'xp': {'level': 4, 'into': 10, 'needed': 300, 'pct': 0.03, 'next_at': 1000},
      'badges': [
        {'slug': 'first-workout', 'name': 'First workout', 'icon': 'trophy', 'tier': 'bronze', 'xp_reward': 25},
      ],
      'exercises': [],
    };

// ------------------------------------------------------------------------------------------------ nutrition

Map<String, dynamic> foodJson(int id, String name, {double kcal = 165, double protein = 31, double carbs = 0, double fat = 3.6, List<Map<String, dynamic>>? servings, bool custom = false}) => {
      'id': id,
      'name': name,
      'brand': null,
      'category': 'meat_fish',
      'diet_type': 'nonveg',
      'is_verified': true,
      'custom': custom,
      'per_100g': {'kcal': kcal, 'protein_g': protein, 'carbs_g': carbs, 'fat_g': fat, 'fibre_g': 0},
      'servings': servings ??
          [
            {'id': id * 10 + 1, 'label': '1 palm-size piece (120 g)', 'grams': 120, 'is_default': true},
            {'id': id * 10 + 2, 'label': '1 small breast (150 g)', 'grams': 150, 'is_default': false},
          ],
      'updated_at': '2026-10-01T10:00:00+00:00',
    };

Map<String, dynamic> logEntryJson(int id, {int foodId = 1, String meal = 'lunch', String name = 'Chicken breast, cooked', double grams = 150, double kcal = 247.5, double protein = 46.5}) =>
    {'id': id, 'food_id': foodId, 'logged_on': '2026-10-12', 'meal': meal, 'name': name, 'quantity': grams, 'unit': 'grams', 'serving_label': null, 'grams': grams, 'kcal': kcal, 'protein_g': protein, 'carbs_g': 0, 'fat_g': 5.4, 'fibre_g': 0};

Map<String, dynamic> nutritionDayJson({String date = '2026-10-12', List<Map<String, dynamic>> lunch = const [], bool targets = true, double eaten = 0}) => {
      'date': date,
      'is_training_day': true,
      'totals': {'calories': eaten, 'protein_g': 0, 'carbs_g': 0, 'fat_g': 0, 'fibre_g': 0},
      'targets': targets ? {'calories': 2400, 'protein_g': 160, 'carbs_g': 280, 'fat_g': 70, 'fibre_g': 30} : null,
      'remaining': targets ? {'calories': 2400 - eaten, 'protein_g': 160, 'carbs_g': 280, 'fat_g': 70, 'fibre_g': 30} : null,
      'suggestions': targets
          ? [
              {'food_id': 13, 'name': 'Prawns, cooked', 'serving': '10 medium prawns (100 g)', 'grams': 100, 'protein_g': 24, 'kcal': 99},
            ]
          : [],
      'meals': [
        {'meal': 'breakfast', 'label': 'Breakfast', 'kcal': 0, 'protein_g': 0, 'entries': []},
        {'meal': 'lunch', 'label': 'Lunch', 'kcal': lunch.fold<double>(0, (n, e) => n + (e['kcal'] as num)), 'protein_g': lunch.fold<double>(0, (n, e) => n + (e['protein_g'] as num)), 'entries': lunch},
        {'meal': 'snack', 'label': 'Snack', 'kcal': 0, 'protein_g': 0, 'entries': []},
        {'meal': 'dinner', 'label': 'Dinner', 'kcal': 0, 'protein_g': 0, 'entries': []},
      ],
    };

// -------------------------------------------------------------------------------------------------- progress

Map<String, dynamic> measurementJson(int id, String date, double weight, {double? fat = 18.5, double? waist = 85, String? notes}) =>
    {'id': id, 'measured_on': date, 'weight_kg': weight, 'body_fat_pct': fat, 'notes': notes, 'neck_cm': null, 'shoulders_cm': null, 'chest_cm': null, 'waist_cm': waist, 'hips_cm': null, 'bicep_left_cm': null, 'bicep_right_cm': null, 'thigh_cm': null, 'calf_cm': null};

Map<String, dynamic> progressSummaryJson({bool points = true}) => {
      'latest': measurementJson(7, '2026-10-12', 80.2),
      'weight_points': points
          ? [
              {'date': '2026-09-20', 'weight_kg': 82.0},
              {'date': '2026-09-27', 'weight_kg': 81.4},
              {'date': '2026-10-04', 'weight_kg': 80.9},
              {'date': '2026-10-12', 'weight_kg': 80.2},
            ]
          : [],
      'target_weight_kg': 78.0,
      'change_30d_kg': -1.8,
      'trend_per_week_kg': -0.57,
      'bmi': 25.3,
      'bmi_category': 'Overweight range',
      'body_fat_pct': 18.5,
      'body_fat_source': 'logged',
      'lean_kg': 65.4,
    };

Map<String, dynamic> achievementsJson() => {
      'xp': {'total': 130, 'level': 2, 'into': 30, 'needed': 300, 'pct': 0.1, 'next_at': 400},
      'streak': {'current': 2, 'longest': 4},
      'earned_count': 1,
      'badges': [
        {'slug': 'workouts-10', 'name': 'Getting Serious', 'description': 'Finish 10 workouts.', 'icon': 'dumbbell', 'category': 'training', 'tier': 'bronze', 'xp_reward': 25, 'earned': false, 'awarded_at': null, 'progress': {'value': 3, 'target': 10, 'pct': 0.3}},
        {'slug': 'first-workout', 'name': 'First Workout', 'description': 'Finish your first workout.', 'icon': 'dumbbell', 'category': 'training', 'tier': 'bronze', 'xp_reward': 25, 'earned': true, 'awarded_at': '2026-10-05T10:00:00+00:00', 'progress': {'value': 1, 'target': 1, 'pct': 1}},
        {'slug': 'streak-7', 'name': '7-Day Streak', 'description': 'Train 7 scheduled days in a row.', 'icon': 'flame', 'category': 'training', 'tier': 'silver', 'xp_reward': 50, 'earned': false, 'awarded_at': null, 'progress': {'value': 2, 'target': 7, 'pct': 0.29}},
      ],
    };

List<Map<String, dynamic>> recordsJson() => [
      {
        'exercise': {'id': 1, 'slug': 'flat-barbell-bench-press', 'name': 'Flat Barbell Bench Press'},
        'records': {
          'best_set_volume': {'value': 400, 'weight_kg': 50, 'previous': null, 'achieved_at': '2026-10-12T18:51:46+00:00'},
          'est_1rm': {'value': 63.33, 'weight_kg': 50, 'previous': null, 'achieved_at': '2026-10-12T18:51:46+00:00'},
          'max_weight': {'value': 50, 'weight_kg': 50, 'previous': null, 'achieved_at': '2026-10-12T18:51:46+00:00'},
        },
        'latest': '2026-10-12T18:51:46+00:00',
      },
    ];
