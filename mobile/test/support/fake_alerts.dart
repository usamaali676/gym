import 'package:ironpulse/core/notifications/local_alerts.dart';

class ScheduledAlert {
  ScheduledAlert(this.id, this.at, this.title, this.body);
  final int id;
  final DateTime at;
  final String title;
  final String body;
}

/// A phone's notification centre in memory: what was scheduled, what was cancelled, and what the system would answer.
class FakeLocalAlerts implements LocalAlerts {
  /// Whether the member lets the app show notifications (the answer to the permission prompt).
  bool allowed = true;
  bool exact = true;
  bool failSchedule = false;
  bool prompted = false;
  int exactRequests = 0;

  final Map<int, ScheduledAlert> pending = {};
  final List<ScheduledAlert> everScheduled = [];
  final List<int> cancelled = [];
  int calls = 0;

  @override
  Future<bool> isAllowed() async {
    calls++;
    return allowed;
  }

  @override
  Future<bool> requestPermission() async {
    calls++;
    prompted = true;
    return allowed;
  }

  @override
  Future<bool> hasExactTiming() async {
    calls++;
    return exact;
  }

  @override
  Future<void> requestExactTiming() async {
    calls++;
    exactRequests++;
  }

  @override
  Future<void> schedule({required int id, required DateTime at, required String title, required String body}) async {
    calls++;
    if (failSchedule) throw StateError('the system refused');
    final a = ScheduledAlert(id, at, title, body);
    pending[id] = a;
    everScheduled.add(a);
  }

  @override
  Future<void> cancel(int id) async {
    calls++;
    cancelled.add(id);
    pending.remove(id);
  }
}
