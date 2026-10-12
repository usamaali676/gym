import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/loaded.dart';
import '../../../core/providers.dart';
import '../../../core/storage/cache_provider.dart';
import '../data/dashboard_repository.dart';
import '../domain/dashboard.dart';

final dashboardRepositoryProvider = Provider<DashboardRepository>(
  (ref) => DashboardRepository(api: ref.watch(apiClientProvider), cache: ref.watch(jsonCacheProvider)),
);

final dashboardProvider = FutureProvider<Loaded<Dashboard>>((ref) => ref.watch(dashboardRepositoryProvider).load());
