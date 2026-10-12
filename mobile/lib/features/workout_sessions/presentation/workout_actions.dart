import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/router.dart';
import '../../../core/api/api_error.dart';
import 'workout_controller.dart';

/// Starts a workout (or resumes the open one) and opens the session screen.
///
/// [openSessionId] is the member's unfinished workout (from the dashboard); when given it is simply resumed. If the
/// server says another workout is already open (`session_in_progress`) that one is resumed instead of showing an error.
Future<void> startOrResumeWorkout(BuildContext context, WidgetRef ref, {int? weekday, DateTime? date, int? openSessionId}) async {
  final messenger = ScaffoldMessenger.of(context);
  final router = GoRouter.of(context);
  try {
    final id = openSessionId ?? (await ref.read(sessionRepositoryProvider).start(weekday: weekday, date: date)).id;
    router.push('${Routes.workout}/$id');
  } on ApiError catch (e) {
    final other = e.code == 'session_in_progress' ? (e.details['session_id'] as num?)?.toInt() : null;
    if (other != null) {
      router.push('${Routes.workout}/$other');
      return;
    }
    messenger.showSnackBar(SnackBar(content: Text(e.message)));
  }
}
