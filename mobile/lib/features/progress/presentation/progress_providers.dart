import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/loaded.dart';
import '../../../core/providers.dart';
import '../../../core/storage/cache_provider.dart';
import '../../dashboard/presentation/dashboard_providers.dart';
import '../data/progress_repository.dart';
import '../domain/progress.dart';

final progressRepositoryProvider = Provider<ProgressRepository>(
  (ref) => ProgressRepository(api: ref.watch(apiClientProvider), cache: ref.watch(jsonCacheProvider)),
);

final progressSummaryProvider = FutureProvider<Loaded<ProgressSummary>>((ref) => ref.watch(progressRepositoryProvider).summary());
final measurementsProvider = FutureProvider<Loaded<List<Measurement>>>((ref) => ref.watch(progressRepositoryProvider).measurements());
final recordsProvider = FutureProvider<Loaded<List<PersonalRecord>>>((ref) => ref.watch(progressRepositoryProvider).records());
final strengthProvider = FutureProvider.family<Loaded<StrengthSeries>, String>((ref, slug) => ref.watch(progressRepositoryProvider).strength(slug));
final achievementsProvider = FutureProvider<Loaded<Achievements>>((ref) => ref.watch(progressRepositoryProvider).achievements());

void invalidateProgress(WidgetRef ref) {
  ref.invalidate(progressSummaryProvider);
  ref.invalidate(measurementsProvider);
  ref.invalidate(recordsProvider);
  ref.invalidate(achievementsProvider);
  ref.invalidate(dashboardProvider);
}
