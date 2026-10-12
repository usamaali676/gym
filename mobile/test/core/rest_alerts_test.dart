import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/core/notifications/rest_alerts.dart';

import '../support/fake_alerts.dart';
import '../support/harness.dart';

final t0 = DateTime.utc(2026, 10, 12, 18);
RestPlan plan({int seconds = 90, String next = 'Bench press, set 2 of 3'}) => RestPlan(endsAt: t0.add(Duration(seconds: seconds)), next: next);

({RestAlerts alerts, FakeLocalAlerts system, bool Function() setEnabled, void Function(bool) enable, TestClock clock}) make({bool enabled = true}) {
  final system = FakeLocalAlerts();
  final clock = TestClock();
  var on = enabled;
  final alerts = RestAlerts(alerts: system, enabled: () => on, now: clock.call);
  return (alerts: alerts, system: system, setEnabled: () => on, enable: (v) => on = v, clock: clock);
}

void main() {
  group('when an alert is scheduled', () {
    test('in the background, with a rest running, switched on: one alert at the moment the rest ends', () async {
      final m = make();
      await m.alerts.plan(plan());
      expect(m.system.pending, isEmpty, reason: 'the app is open: the screen counts down by itself');

      await m.alerts.setBackground(true);
      final a = m.system.pending[RestAlerts.notificationId]!;
      expect(a.at, t0.add(const Duration(seconds: 90)));
      expect(a.title, 'Rest is over');
      expect(a.body, 'Next: Bench press, set 2 of 3');
      expect(m.system.everScheduled, hasLength(1));
    });

    test('switched off: nothing is ever scheduled and the notification system is never touched', () async {
      final m = make(enabled: false);
      await m.alerts.plan(plan());
      await m.alerts.setBackground(true);
      await m.alerts.setBackground(false);
      expect(m.system.calls, 0);
    });

    test('the same rest is not scheduled twice; an extended rest replaces the alert', () async {
      final m = make();
      await m.alerts.setBackground(true);
      await m.alerts.plan(plan());
      await m.alerts.plan(plan());
      expect(m.system.everScheduled, hasLength(1));

      await m.alerts.plan(plan(seconds: 120)); // +30 s
      expect(m.system.everScheduled, hasLength(2));
      expect(m.system.pending[RestAlerts.notificationId]!.at, t0.add(const Duration(seconds: 120)));
      expect(m.system.pending, hasLength(1), reason: 'one alert id, so the old one is replaced, not duplicated');
    });

    test('a rest that has already run out is not scheduled', () async {
      final m = make();
      m.clock.advance(const Duration(minutes: 5));
      await m.alerts.setBackground(true);
      await m.alerts.plan(plan(seconds: 90));
      expect(m.system.pending, isEmpty);
    });
  });

  group('when it is taken away', () {
    test('skipping the rest, pausing it or finishing the workout (no plan) cancels it', () async {
      final m = make();
      await m.alerts.setBackground(true);
      await m.alerts.plan(plan());
      expect(m.system.pending, hasLength(1));

      await m.alerts.plan(null);
      expect(m.system.pending, isEmpty);
      expect(m.system.cancelled, [RestAlerts.notificationId]);
    });

    test('coming back to the app cancels it, and the rest is not re-announced on the next trip away unless it is still running', () async {
      final m = make();
      await m.alerts.plan(plan());
      await m.alerts.setBackground(true);
      expect(m.system.pending, hasLength(1));

      await m.alerts.setBackground(false);
      expect(m.system.pending, isEmpty);

      await m.alerts.setBackground(true);
      expect(m.system.pending, hasLength(1), reason: 'the same rest is still running, so it is announced again');
    });

    test('opening the app cancels an alert left behind by an earlier run that was closed during a rest', () async {
      final m = make();
      await m.alerts.setBackground(false);
      expect(m.system.cancelled, [RestAlerts.notificationId]);
    });

    test('clear (turned off, signed out) cancels and forgets the rest; with the feature never used it does nothing', () async {
      final m = make();
      await m.alerts.setBackground(true);
      await m.alerts.plan(plan());
      m.enable(false);
      await m.alerts.clear();
      expect(m.system.pending, isEmpty);
      await m.alerts.setBackground(true);
      expect(m.system.pending, isEmpty);

      final never = make(enabled: false);
      await never.alerts.clear();
      expect(never.system.calls, 0);
    });
  });

  group('failures and ordering', () {
    test('a system that refuses the alert does not throw into the workout, and the next change tries again', () async {
      final m = make();
      await m.alerts.setBackground(true);
      m.system.failSchedule = true;
      await m.alerts.plan(plan()); // must not throw
      expect(m.system.pending, isEmpty);

      m.system.failSchedule = false;
      await m.alerts.plan(plan());
      expect(m.system.pending, hasLength(1));
    });

    test('calls reach the system one at a time, and the last word wins', () async {
      final slow = _SlowAlerts();
      final clock = TestClock();
      final alerts = RestAlerts(alerts: slow, enabled: () => true, now: clock.call);
      await alerts.setBackground(true);

      final calls = [alerts.plan(plan(seconds: 60)), alerts.plan(plan(seconds: 90)), alerts.plan(null), alerts.plan(plan(seconds: 120))];
      await Future.wait(calls);

      expect(slow.maxInFlight, 1);
      expect(slow.pending.values.single.at, t0.add(const Duration(seconds: 120)));
    });
  });

  group('the member\'s choice', () {
    test('off by default; turning on asks the system and is remembered', () async {
      final w = await TestWorld.create();
      final c = w.container();
      expect(c.read(restAlertsEnabledProvider), isFalse);

      expect(await c.read(restAlertsEnabledProvider.notifier).setEnabled(true), isTrue);
      expect(w.alerts.prompted, isTrue);
      expect(c.read(restAlertsEnabledProvider), isTrue);
      expect(w.prefs.getBool('ip.rest_alerts'), isTrue);

      final again = w.container();
      expect(again.read(restAlertsEnabledProvider), isTrue, reason: 'it survives a restart');
    });

    test('a refused permission leaves it off and says so', () async {
      final w = await TestWorld.create();
      w.alerts.allowed = false;
      final c = w.container();
      expect(await c.read(restAlertsEnabledProvider.notifier).setEnabled(true), isFalse);
      expect(c.read(restAlertsEnabledProvider), isFalse);
      expect(w.prefs.getBool('ip.rest_alerts'), isFalse);
    });

    test('turning it off cancels an alert that is waiting', () async {
      final w = await TestWorld.create(prefs: {'ip.rest_alerts': true});
      final c = w.container();
      final alerts = c.read(restAlertsProvider);
      await alerts.setBackground(true);
      await alerts.plan(RestPlan(endsAt: w.clock().add(const Duration(minutes: 1)), next: 'x'));
      expect(w.alerts.pending, hasLength(1));

      await c.read(restAlertsEnabledProvider.notifier).setEnabled(false);
      await Future<void>.delayed(Duration.zero);
      expect(w.alerts.pending, isEmpty);
      expect(c.read(restAlertsEnabledProvider), isFalse);
    });
  });
}

class _SlowAlerts extends FakeLocalAlerts {
  int inFlight = 0;
  int maxInFlight = 0;

  Future<void> _busy() async {
    inFlight++;
    if (inFlight > maxInFlight) maxInFlight = inFlight;
    await Future<void>.delayed(const Duration(milliseconds: 5));
    inFlight--;
  }

  @override
  Future<void> schedule({required int id, required DateTime at, required String title, required String body}) async {
    await _busy();
    await super.schedule(id: id, at: at, title: title, body: body);
  }

  @override
  Future<void> cancel(int id) async {
    await _busy();
    await super.cancel(id);
  }
}
