import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:ironpulse/features/posture/data/pose_capture.dart';
import 'package:ironpulse/features/posture/domain/geometry.dart';

/// The camera, replaced by a stream the test pushes recorded landmarks into. Frames are analysed by the real engine;
/// only the camera and the pose model are missing.
class FakeCapture implements PoseCapture {
  FakeCapture({this.mirrored = true, this.canSwitch = false});

  final _controller = StreamController<PoseFrame>.broadcast(sync: true);
  bool closed = false;

  @override
  final bool mirrored;

  @override
  final bool canSwitch;

  @override
  double fps = 15;

  @override
  Stream<PoseFrame> get frames => _controller.stream;

  @override
  Widget buildPreview(BuildContext context) => const ColoredBox(color: Color(0xFF0B120E));

  void emit(PoseFrame frame) {
    if (!closed) _controller.add(frame);
  }

  @override
  Future<void> close() async {
    closed = true;
    unawaited(_controller.close()); // fake-async tests do not run the real event loop that completes this future
  }
}

class FakeOpener implements PoseCaptureOpener {
  FakeOpener({this.failWith, this.canSwitch = false});

  /// When set, the next [open] throws it (then clears it).
  PoseProblem? failWith;
  final bool canSwitch;
  final List<FakeCapture> captures = [];
  final List<bool> requestedFront = [];

  FakeCapture get current => captures.last;

  @override
  Future<PoseCapture> open({required bool front}) async {
    requestedFront.add(front);
    final problem = failWith;
    if (problem != null) {
      failWith = null;
      throw PoseUnavailable(problem);
    }
    final c = FakeCapture(mirrored: front, canSwitch: canSwitch);
    captures.add(c);
    return c;
  }
}

Map<String, dynamic> loadParityFixture() {
  final bytes = gzip.decode(File('test/fixtures/posture_parity.json.gz').readAsBytesSync());
  return jsonDecode(utf8.decode(bytes)) as Map<String, dynamic>;
}

List<Landmark>? decodeFrame(List<dynamic>? row) {
  if (row == null) return null;
  return [for (var k = 0; k < landmarkCount; k++) Landmark((row[k * 3] as int) / 1e4, (row[k * 3 + 1] as int) / 1e4, visibility: (row[k * 3 + 2] as int) / 100)];
}

/// The frames of one recorded scenario, as the camera would deliver them.
List<PoseFrame> scenarioFrames(String id, {double timeOffsetMs = 0}) {
  final s = (loadParityFixture()['scenarios'] as List).cast<Map<String, dynamic>>().firstWhere((s) => (s['head'] as Map)['id'] == id);
  final head = s['head'] as Map<String, dynamic>;
  final times = (head['times'] as List).cast<num>();
  final aspect = (head['aspect'] as num).toDouble();
  final frames = s['frames'] as List;
  return [for (var i = 0; i < frames.length; i++) PoseFrame(landmarks: decodeFrame(frames[i] as List?), tMs: times[i].toDouble() + timeOffsetMs, aspect: aspect)];
}

/// [frame] held for [seconds] at 15 fps starting at [fromMs]: someone standing still in the start position.
List<PoseFrame> holdStill(PoseFrame frame, {required double seconds, double fromMs = 0}) {
  const dt = 1000 / 15;
  return [for (var t = 0.0; t < seconds * 1000; t += dt) PoseFrame(landmarks: frame.landmarks, tMs: fromMs + t, aspect: frame.aspect)];
}

/// The posture rule as the API serves it, built from the real rule file in the repository.
Map<String, dynamic> ruleApiJson(String slug, {int exerciseId = 15, String name = 'Barbell Squat'}) {
  final file = jsonDecode(File('../database/seeders/data/posture-rules/$slug.json').readAsStringSync()) as Map<String, dynamic>;
  return {
    'exercise': {'id': exerciseId, 'slug': slug, 'name': name},
    'rule_id': 4,
    'rule_version': file['version'],
    'tier': file['tier'],
    'camera_view': file['camera_view'],
    'config': file['config'],
    'guidance': file['guidance'],
    'updated_at': '2026-10-12T10:00:00+00:00',
  };
}

Map<String, dynamic> postureExerciseJson({int id = 15, String slug = 'barbell-squat', String name = 'Barbell Squat', String group = 'quads', String tier = 'full', String view = 'side'}) => {
  'exercise': {'id': id, 'slug': slug, 'name': name, 'muscle_group': group},
  'rule_id': 4,
  'rule_version': 1,
  'tier': tier,
  'camera_view': view,
};
