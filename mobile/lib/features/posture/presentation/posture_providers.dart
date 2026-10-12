import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/loaded.dart';
import '../../../core/providers.dart';
import '../../../core/storage/cache_provider.dart';
import '../data/ml_kit_pose_capture.dart';
import '../data/pose_capture.dart';
import '../data/posture_repository.dart';
import '../domain/posture_models.dart';

final postureRepositoryProvider = Provider<PostureRepository>(
  (ref) => PostureRepository(api: ref.watch(apiClientProvider), cache: ref.watch(jsonCacheProvider)),
);

/// The camera + pose model. Overridden in tests with a replay of recorded landmarks.
final poseCaptureOpenerProvider = Provider<PoseCaptureOpener>((ref) => const MlKitPoseOpener());

final postureExercisesProvider = FutureProvider<Loaded<List<PostureExercise>>>((ref) => ref.watch(postureRepositoryProvider).exercises());

/// The rule for one exercise. Fetched again on each visit so a newly published rule version is used.
final postureRuleProvider = FutureProvider.autoDispose.family<Loaded<PostureRule>, String>((ref, slug) => ref.watch(postureRepositoryProvider).rule(slug));
