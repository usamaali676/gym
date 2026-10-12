import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/loaded.dart';
import '../../../core/providers.dart';
import '../../../core/storage/cache_provider.dart';
import '../../dashboard/presentation/dashboard_providers.dart';
import '../data/plan_repository.dart';
import '../domain/plan.dart';
import '../domain/programme.dart';

final planRepositoryProvider = Provider<PlanRepository>(
  (ref) => PlanRepository(api: ref.watch(apiClientProvider), cache: ref.watch(jsonCacheProvider)),
);

/// Which week the Train tab shows: `null` is the current week, otherwise any date inside the wanted week.
class WeekAnchor extends Notifier<DateTime?> {
  @override
  DateTime? build() => null;

  void show(DateTime? anchor) => state = anchor;
}

final weekAnchorProvider = NotifierProvider<WeekAnchor, DateTime?>(WeekAnchor.new);

final weekPlanProvider = FutureProvider<Loaded<WeekPlan>>((ref) => ref.watch(planRepositoryProvider).week(anchor: ref.watch(weekAnchorProvider)));

/// (weekday 1-7, date `yyyy-MM-dd` or null for the next occurrence). Records compare by value, so this works as a key.
typedef DayKey = (int weekday, String? date);

final dayDetailProvider = FutureProvider.family<Loaded<DayDetail>, DayKey>(
  (ref, key) => ref.watch(planRepositoryProvider).day(key.$1, date: key.$2 == null ? null : DateTime.parse(key.$2!)),
);

final programmesProvider = FutureProvider<Loaded<List<Programme>>>((ref) => ref.watch(planRepositoryProvider).programmes());

final programmeDetailProvider = FutureProvider.family<Loaded<ProgrammeDetail>, int>((ref, id) => ref.watch(planRepositoryProvider).programme(id));

/// After anything that changes what the plan shows (skip, new programme, finished workout) refresh every view of it.
void invalidatePlanViews(WidgetRef ref) {
  ref.invalidate(weekPlanProvider);
  ref.invalidate(dayDetailProvider);
  ref.invalidate(programmesProvider);
  ref.invalidate(programmeDetailProvider);
  ref.invalidate(dashboardProvider);
}
