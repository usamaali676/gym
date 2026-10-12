import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/loaded.dart';
import '../../../core/providers.dart';
import '../../../core/storage/cache_provider.dart';
import '../data/membership_repository.dart';
import '../domain/membership.dart';

final membershipRepositoryProvider = Provider<MembershipRepository>(
  (ref) => MembershipRepository(api: ref.watch(apiClientProvider), cache: ref.watch(jsonCacheProvider)),
);

/// The member's plan and entitlements. Invalidate to refresh (pull-to-refresh, app resume).
final membershipProvider = FutureProvider<Loaded<Membership>>((ref) => ref.watch(membershipRepositoryProvider).membership());

final plansProvider = FutureProvider<Loaded<List<PlanInfo>>>((ref) => ref.watch(membershipRepositoryProvider).plans());

/// What the plan allows, for drawing locks. `unknown` (everything shown) until the first answer: the server enforces.
final entitlementsProvider = Provider<Entitlements>((ref) => ref.watch(membershipProvider).asData?.value.data.entitlements ?? Entitlements.unknown);
