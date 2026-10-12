import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../providers.dart';
import '../utilities/clock.dart';
import 'local_alerts.dart';

/// "Your rest is over", delivered by the phone itself while the app is in the background. Off until the member turns it
/// on in Account (and the system allows notifications). Nothing is sent to a server and nothing is shown while the app is
/// open (the screen already buzzes and counts down).
class RestPlan {
  const RestPlan({required this.endsAt, required this.next});

  /// When the rest ends: the engine's own absolute time.
  final DateTime endsAt;

  /// What comes next, for the notification text ("Bench press, set 3 of 4").
  final String next;

  @override
  bool operator ==(Object other) => other is RestPlan && other.endsAt == endsAt && other.next == next;

  @override
  int get hashCode => Object.hash(endsAt, next);
}

class RestAlerts {
  RestAlerts({required this._alerts, required this._enabled, required this._now});

  static const notificationId = 4101;
  static const title = 'Rest is over';

  final LocalAlerts _alerts;
  final bool Function() _enabled;
  final DateTime Function() _now;

  RestPlan? _plan;
  bool _background = false;
  RestPlan? _scheduled;
  Future<void> _queue = Future.value();

  /// The workout says what rest is running now (null: none, or paused, or the workout is over).
  Future<void> plan(RestPlan? plan) {
    _plan = plan;
    return _reconcile();
  }

  /// The app went to the background (true) or came back (false).
  Future<void> setBackground(bool background) {
    _background = background;
    return _reconcile(returning: !background);
  }

  /// The member turned the alert off, or signed out: nothing may fire afterwards.
  Future<void> clear() {
    _plan = null;
    return _run(() async {
      if (_scheduled == null && !_enabled()) return; // never switched on: nothing to remove
      _scheduled = null;
      await _alerts.cancel(notificationId);
    });
  }

  Future<void> _reconcile({bool returning = false}) => _run(() async {
        final plan = _plan;
        final want = _enabled() && _background && plan != null && plan.endsAt.isAfter(_now());
        if (want) {
          if (_scheduled == plan) return;
          await _alerts.schedule(id: notificationId, at: plan.endsAt, title: title, body: 'Next: ${plan.next}');
          _scheduled = plan;
        } else if (_scheduled != null || (returning && _enabled())) {
          // Back in the app (or the rest changed): remove an alert still waiting, including one left by an earlier run
          // of the app that was closed while resting.
          _scheduled = null;
          await _alerts.cancel(notificationId);
        }
      });

  /// One platform call at a time, in order; a failure is dropped (an alert that cannot be scheduled must never break the
  /// workout screen) and the next change tries again.
  Future<void> _run(Future<void> Function() action) {
    _queue = _queue.then((_) => action()).catchError((Object _) {
      _scheduled = null;
    });
    return _queue;
  }
}

/// The member's choice. Stored on the phone; off until they turn it on.
class RestAlertsEnabled extends Notifier<bool> {
  static const prefsKey = 'ip.rest_alerts';

  @override
  bool build() => ref.watch(sharedPreferencesProvider).getBool(prefsKey) ?? false;

  /// Turning it on asks the system for permission first; if that is refused it stays off and the answer is `false`.
  Future<bool> setEnabled(bool on) async {
    final prefs = ref.read(sharedPreferencesProvider);
    if (on) {
      final allowed = await ref.read(localAlertsProvider).requestPermission();
      if (!ref.mounted) return false;
      if (!allowed) {
        await prefs.setBool(prefsKey, false);
        state = false;
        return false;
      }
    }
    await prefs.setBool(prefsKey, on);
    if (!ref.mounted) return on;
    state = on;
    if (!on) unawaited(ref.read(restAlertsProvider).clear());
    return on;
  }
}

final restAlertsEnabledProvider = NotifierProvider<RestAlertsEnabled, bool>(RestAlertsEnabled.new);

final restAlertsProvider = Provider<RestAlerts>((ref) => RestAlerts(
      alerts: ref.watch(localAlertsProvider),
      enabled: () => ref.read(restAlertsEnabledProvider),
      now: () => ref.read(clockProvider)(),
    ));
