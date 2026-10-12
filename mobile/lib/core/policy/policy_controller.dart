import 'dart:async';
import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../api/api_error.dart';
import '../providers.dart';
import 'app_config.dart';

class PolicyState {
  const PolicyState({
    this.config,
    this.loading = false,
    this.offline = false,
    this.forcedUpdate = false,
    this.forcedMaintenance,
    this.lastChecked,
  });

  /// Last config from the server (or the cached copy while offline). `null` before the first answer.
  final AppConfig? config;
  final bool loading;
  final bool offline;

  /// Set when any API call came back 426 / 503 before `/app/config` had a chance to say so.
  final bool forcedUpdate;
  final Maintenance? forcedMaintenance;
  final DateTime? lastChecked;

  bool get updateRequired => forcedUpdate || config?.versionStatus == VersionStatus.updateRequired;
  bool get updateRecommended => config?.versionStatus == VersionStatus.updateRecommended;
  Maintenance? get maintenance => forcedMaintenance ?? config?.maintenance;

  PolicyState copyWith({
    AppConfig? config,
    bool? loading,
    bool? offline,
    bool? forcedUpdate,
    Maintenance? forcedMaintenance,
    bool clearForcedMaintenance = false,
    DateTime? lastChecked,
  }) =>
      PolicyState(
        config: config ?? this.config,
        loading: loading ?? this.loading,
        offline: offline ?? this.offline,
        forcedUpdate: forcedUpdate ?? this.forcedUpdate,
        forcedMaintenance: clearForcedMaintenance ? null : (forcedMaintenance ?? this.forcedMaintenance),
        lastChecked: lastChecked ?? this.lastChecked,
      );
}

/// Knows whether this build may run: version policy, maintenance mode, feature switches. Refreshed at launch, when the
/// app returns to the foreground, and whenever an API call answers 426 / 503.
class PolicyController extends Notifier<PolicyState> {
  static const _cacheKey = 'ip.app_config';

  StreamSubscription<PolicySignal>? _signals;

  @override
  PolicyState build() {
    final api = ref.watch(apiClientProvider);
    _signals = api.signals.listen(_onSignal);
    ref.onDispose(() => _signals?.cancel());

    return PolicyState(config: _cached());
  }

  AppConfig? _cached() {
    try {
      final raw = ref.read(sharedPreferencesProvider).getString(_cacheKey);
      return raw == null ? null : AppConfig.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      return null;
    }
  }

  void _onSignal(PolicySignal signal) {
    switch (signal) {
      case UpgradeRequiredSignal():
        state = state.copyWith(forcedUpdate: true);
      case MaintenanceSignal(:final message):
        state = state.copyWith(forcedMaintenance: Maintenance(message: message));
    }
  }

  /// Fetches `/app/config`. Offline, the cached copy stays in force; the answer is trusted only when it arrives.
  Future<void> refresh() async {
    if (state.loading) return;
    state = state.copyWith(loading: true);
    try {
      final response = await ref.read(apiClientProvider).get('/app/config', auth: false);
      final config = AppConfig.fromJson(response.map);
      await ref.read(sharedPreferencesProvider).setString(_cacheKey, jsonEncode(response.map));
      state = PolicyState(config: config, lastChecked: DateTime.now());
    } on ApiError catch (e) {
      // 426/503 on this very call already raised a signal; anything else keeps the last known policy.
      state = state.copyWith(loading: false, offline: e.isOffline, lastChecked: DateTime.now());
    }
  }
}

final policyControllerProvider = NotifierProvider<PolicyController, PolicyState>(PolicyController.new);
