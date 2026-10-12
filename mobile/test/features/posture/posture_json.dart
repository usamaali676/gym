import 'package:ironpulse/features/posture/domain/posture_analyzer.dart';

/// The events and rep feedback in the same JSON shape the web engine produces, so the two can be compared.
Map<String, dynamic> repFeedbackJson(RepFeedback f) => {
  ...f.record.toJson(),
  'feedback': f.feedback,
  'problems': [for (final p in f.problems) p.toJson()],
};

Map<String, dynamic> eventJson(PostureEvent e) => switch (e) {
  ReadyEvent() => {'type': 'ready'},
  PeakEvent() => {'type': 'peak'},
  CueEvent() => {'type': 'cue', 'id': e.id, 'message': e.message, 'label': e.label, 'severity': e.severity},
  AbortEvent() => {'type': 'abort', 'reason': e.reason},
  HoldShortEvent() => {'type': 'hold_short', 'ms': e.ms},
  RepEvent() => {'type': 'rep', ...repFeedbackJson(e.rep), 'reps': e.reps, 'goodReps': e.goodReps},
};
