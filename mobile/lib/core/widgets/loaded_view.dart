import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme/tokens.g.dart';
import '../api/api_error.dart';
import '../api/loaded.dart';
import '../../features/membership/presentation/locked_view.dart';
import 'state_views.dart';

/// Renders a provider that yields [Loaded] data the same way on every screen: skeleton while the first answer is on
/// its way, an explanation when it fails (locked feature, offline, server trouble) and the content with pull-to-refresh
/// and an "offline, showing saved data" strip when the answer came from the cache.
class LoadedView<T> extends StatelessWidget {
  const LoadedView({
    super.key,
    required this.value,
    required this.onRefresh,
    required this.children,
    this.padding = const EdgeInsets.fromLTRB(20, 20, 20, 24),
    this.header,
  });

  final AsyncValue<Loaded<T>> value;
  final Future<void> Function() onRefresh;
  final List<Widget> Function(BuildContext context, T data) children;
  final EdgeInsets padding;

  /// Shown above the list inside the scroll view (screen title).
  final Widget? header;

  @override
  Widget build(BuildContext context) {
    return value.when(
      skipLoadingOnReload: true,
      loading: () => ListView(padding: padding, children: [?header, const SkeletonList()]),
      error: (error, _) {
        final e = ApiError.from(error);
        if (e.kind == ApiErrorKind.entitlementRequired) return LockedView(entitlementKey: e.missingEntitlement);
        return RefreshIndicator(
          onRefresh: onRefresh,
          child: LayoutBuilder(
            builder: (context, c) => SingleChildScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              child: SizedBox(height: c.maxHeight, child: ErrorState(error: e, onRetry: onRefresh)),
            ),
          ),
        );
      },
      data: (loaded) => Column(children: [
        if (loaded.fromCache) OfflineBanner(message: _stale(loaded.savedAt)),
        Expanded(
          child: RefreshIndicator(
            color: IpColors.neon,
            backgroundColor: IpColors.surface2,
            onRefresh: onRefresh,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: padding,
              children: [?header, ...children(context, loaded.data)],
            ),
          ),
        ),
      ]),
    );
  }

  static String _stale(DateTime? at) {
    if (at == null) return 'Offline. Showing saved data.';
    final local = at.toLocal();
    final hh = local.hour.toString().padLeft(2, '0');
    final mm = local.minute.toString().padLeft(2, '0');
    return 'Offline. Showing data saved at $hh:$mm.';
  }
}
