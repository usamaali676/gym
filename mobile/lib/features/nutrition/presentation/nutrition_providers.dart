import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/loaded.dart';
import '../../../core/providers.dart';
import '../../../core/storage/cache_provider.dart';
import '../../dashboard/presentation/dashboard_providers.dart';
import '../data/nutrition_repository.dart';
import '../domain/nutrition.dart';

final nutritionRepositoryProvider = Provider<NutritionRepository>(
  (ref) => NutritionRepository(api: ref.watch(apiClientProvider), cache: ref.watch(jsonCacheProvider)),
);

/// The day the Fuel tab shows; `null` is today.
class NutritionDate extends Notifier<DateTime?> {
  @override
  DateTime? build() => null;

  void show(DateTime? date) => state = date;
}

final nutritionDateProvider = NotifierProvider<NutritionDate, DateTime?>(NutritionDate.new);

final nutritionDayProvider = FutureProvider<Loaded<NutritionDay>>((ref) => ref.watch(nutritionRepositoryProvider).day(ref.watch(nutritionDateProvider)));

/// After logging, editing or deleting food everything that shows totals must reload.
void invalidateNutrition(WidgetRef ref) {
  ref.invalidate(nutritionDayProvider);
  ref.invalidate(dashboardProvider);
}
