// Draws the tracked skeleton over the camera picture. Bones the rules flag are red, the rest neon green, bones the
// camera sees poorly are faint. Colour is never the only signal: the HUD shows the cue text as well.
// Port of resources/js/modules/posture-overlay.js.

import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../../app/theme/tokens.g.dart';
import '../domain/geometry.dart';
import 'posture_coach.dart';

const _red = Color(0xFFFF3B3B);

class SkeletonPainter extends CustomPainter {
  SkeletonPainter(this.data, {this.minVisibility = 0.5}) : super(repaint: data);

  final ValueNotifier<OverlayData> data;
  final double minVisibility;

  /// A bone endpoint in normalised coordinates, or null if it is not visible enough.
  static Offset? endpoint(List<Landmark> landmarks, String name, double minVis) {
    if (name.startsWith('mid_')) {
      final ids = name == 'mid_shoulder' ? [11, 12] : [23, 24];
      final pts = [for (final i in ids) if (landmarks[i].visibility >= minVis) landmarks[i]];
      if (pts.isEmpty) return null;
      return Offset(pts.fold<double>(0, (s, p) => s + p.x) / pts.length, pts.fold<double>(0, (s, p) => s + p.y) / pts.length);
    }
    final p = landmarks[landmarkIndex[name]!];
    return p.visibility >= minVis ? Offset(p.x, p.y) : null;
  }

  @override
  void paint(Canvas canvas, Size size) {
    final d = data.value;
    if (d.showGuide) _paintGuide(canvas, size);
    final landmarks = d.landmarks;
    if (landmarks == null || landmarks.length < landmarkCount) return;

    Offset px(Offset p) => Offset((d.mirrored ? 1 - p.dx : p.dx) * size.width, p.dy * size.height);
    final base = math.max(3.0, math.min(size.width, size.height) / 90);

    for (final id in drawOrder) {
      final [a, b] = bones[id]!;
      final pa = endpoint(landmarks, a, 0.2);
      final pb = endpoint(landmarks, b, 0.2);
      if (pa == null || pb == null) continue;
      final visible = endpoint(landmarks, a, minVisibility) != null && endpoint(landmarks, b, minVisibility) != null;
      final bad = d.redBones.contains(id);
      final color = (bad ? _red : IpColors.neon).withValues(alpha: visible ? 1 : 0.22);
      final line = Paint()
        ..color = color
        ..strokeWidth = bad ? base * 1.7 : base * 1.25
        ..strokeCap = StrokeCap.round;
      if (visible) {
        canvas.drawLine(px(pa), px(pb), Paint()..color = color.withValues(alpha: 0.35)..strokeWidth = line.strokeWidth * 2.2..strokeCap = StrokeCap.round..maskFilter = MaskFilter.blur(BlurStyle.normal, base * (bad ? 3 : 2)));
      }
      canvas.drawLine(px(pa), px(pb), line);
    }

    const joints = ['nose', 'l_shoulder', 'r_shoulder', 'l_elbow', 'r_elbow', 'l_wrist', 'r_wrist', 'l_hip', 'r_hip', 'l_knee', 'r_knee', 'l_ankle', 'r_ankle'];
    final fill = Paint()..color = const Color(0xFF041006);
    final ring = Paint()
      ..color = const Color(0xFFE8F5E9)
      ..style = PaintingStyle.stroke
      ..strokeWidth = math.max(1.5, base * 0.45);
    for (final name in joints) {
      final p = endpoint(landmarks, name, minVisibility);
      if (p == null) continue;
      final o = px(p);
      canvas.drawCircle(o, base * 1.05, fill);
      canvas.drawCircle(o, base * 1.05, ring);
    }
  }

  /// A dashed outline to fit the whole body in.
  void _paintGuide(Canvas canvas, Size size) {
    final rect = Rect.fromLTRB(size.width * 0.18, size.height * 0.06, size.width * 0.82, size.height * 0.94);
    final paint = Paint()
      ..color = const Color.fromRGBO(57, 255, 20, 0.55)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2;
    const dash = 10.0;
    const gap = 8.0;
    void edge(Offset from, Offset to) {
      final length = (to - from).distance;
      final dir = (to - from) / length;
      for (var t = 0.0; t < length; t += dash + gap) {
        canvas.drawLine(from + dir * t, from + dir * math.min(t + dash, length), paint);
      }
    }

    edge(rect.topLeft, rect.topRight);
    edge(rect.topRight, rect.bottomRight);
    edge(rect.bottomRight, rect.bottomLeft);
    edge(rect.bottomLeft, rect.topLeft);

    final text = TextPainter(
      text: const TextSpan(text: 'Fit your whole body inside the box', style: TextStyle(color: Color.fromRGBO(57, 255, 20, 0.8), fontSize: 12, fontWeight: FontWeight.w600)),
      textDirection: TextDirection.ltr,
    )..layout(maxWidth: rect.width);
    text.paint(canvas, Offset(rect.left + (rect.width - text.width) / 2, rect.top + 8));
  }

  @override
  bool shouldRepaint(SkeletonPainter old) => old.data != data || old.minVisibility != minVisibility;
}
