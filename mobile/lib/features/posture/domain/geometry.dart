// Pose geometry on the 33 BlazePose landmarks (the same numbering MediaPipe and ML Kit use). A line-for-line port of
// resources/js/modules/geometry.js; mobile/test/features/posture/parity_test.dart checks it against the JavaScript.
//
// Coordinates are normalised image coordinates (x and y both 0..1, y grows downwards, "up" is -y). A degree is only a
// degree when both axes share a unit, so callers scale x by the frame's aspect ratio first (see [scaleLandmarks]).

import 'dart:math' as math;

class Landmark {
  const Landmark(this.x, this.y, {this.z = 0, this.visibility = 1});

  final double x;
  final double y;
  final double z;

  /// 0..1: how sure the model is that the joint is visible (ML Kit calls it in-frame likelihood).
  final double visibility;
}

/// A landmark after smoothing and scaling; [v] is its visibility.
class Pt {
  const Pt(this.x, this.y, this.v, [this.z = 0]);

  final double x;
  final double y;
  final double v;
  final double z;
}

const landmarkIndex = <String, int>{
  'nose': 0,
  'l_eye_inner': 1, 'l_eye': 2, 'l_eye_outer': 3,
  'r_eye_inner': 4, 'r_eye': 5, 'r_eye_outer': 6,
  'l_ear': 7, 'r_ear': 8,
  'l_mouth': 9, 'r_mouth': 10,
  'l_shoulder': 11, 'r_shoulder': 12,
  'l_elbow': 13, 'r_elbow': 14,
  'l_wrist': 15, 'r_wrist': 16,
  'l_pinky': 17, 'r_pinky': 18,
  'l_index': 19, 'r_index': 20,
  'l_thumb': 21, 'r_thumb': 22,
  'l_hip': 23, 'r_hip': 24,
  'l_knee': 25, 'r_knee': 26,
  'l_ankle': 27, 'r_ankle': 28,
  'l_heel': 29, 'r_heel': 30,
  'l_foot_index': 31, 'r_foot_index': 32,
};

const landmarkCount = 33;

/// Names that exist on both sides. A bare name ("elbow") means "the side facing the camera".
const sidedPoints = [
  'eye_inner', 'eye', 'eye_outer', 'ear', 'mouth', 'shoulder', 'elbow', 'wrist', //
  'pinky', 'index', 'thumb', 'hip', 'knee', 'ankle', 'heel', 'foot_index',
];

const _midPoints = <String, List<String>>{
  'mid_shoulder': ['l_shoulder', 'r_shoulder'],
  'mid_hip': ['l_hip', 'r_hip'],
  'mid_ankle': ['l_ankle', 'r_ankle'],
  'mid_wrist': ['l_wrist', 'r_wrist'],
  'mid_ear': ['l_ear', 'r_ear'],
};

/// Every point name a rule may use.
final Set<String> pointNames = {
  'nose',
  ...sidedPoints,
  for (final p in sidedPoints) ...['l_$p', 'r_$p'],
  ..._midPoints.keys,
};

bool isPointName(Object? name) => name is String && pointNames.contains(name);
bool isSidedBare(Object? name) => name is String && sidedPoints.contains(name);

/// Landmark indices behind a point name: one for a landmark, two for a midpoint, null when unknown.
/// [side] is 'l' or 'r' and only matters for bare sided names.
List<int>? resolvePoint(String name, [String side = 'l']) {
  if (name == 'nose') return [landmarkIndex['nose']!];
  final mid = _midPoints[name];
  if (mid != null) return [for (final n in mid) landmarkIndex[n]!];
  if (isSidedBare(name)) return [landmarkIndex['${side}_$name']!];
  final index = landmarkIndex[name];
  return index == null ? null : [index];
}

// ---------------------------------------------------------------------------------------------- bones

const bones = <String, List<String>>{
  'shoulders': ['l_shoulder', 'r_shoulder'],
  'hips': ['l_hip', 'r_hip'],
  'torso_l': ['l_shoulder', 'l_hip'],
  'torso_r': ['r_shoulder', 'r_hip'],
  'neck': ['mid_shoulder', 'nose'],
  'upper_arm_l': ['l_shoulder', 'l_elbow'],
  'upper_arm_r': ['r_shoulder', 'r_elbow'],
  'forearm_l': ['l_elbow', 'l_wrist'],
  'forearm_r': ['r_elbow', 'r_wrist'],
  'hand_l': ['l_wrist', 'l_index'],
  'hand_r': ['r_wrist', 'r_index'],
  'thigh_l': ['l_hip', 'l_knee'],
  'thigh_r': ['r_hip', 'r_knee'],
  'shin_l': ['l_knee', 'l_ankle'],
  'shin_r': ['r_knee', 'r_ankle'],
  'foot_l': ['l_ankle', 'l_foot_index'],
  'foot_r': ['r_ankle', 'r_foot_index'],
  'heel_l': ['l_ankle', 'l_heel'],
  'heel_r': ['r_ankle', 'r_heel'],
};

/// Names a rule can use in `segments`. Each expands to a list of bone ids.
final Map<String, List<String>> segmentGroups = () {
  final base = <String, List<String>>{
    'torso': ['torso_l', 'torso_r', 'shoulders', 'hips'],
    'shoulders': ['shoulders'],
    'hips': ['hips'],
    'neck': ['neck'],
    'upper_arm': ['upper_arm_l', 'upper_arm_r'],
    'forearm': ['forearm_l', 'forearm_r'],
    'hand': ['hand_l', 'hand_r'],
    'thigh': ['thigh_l', 'thigh_r'],
    'shin': ['shin_l', 'shin_r'],
    'foot': ['foot_l', 'foot_r', 'heel_l', 'heel_r'],
  };
  base['arm'] = [...base['upper_arm']!, ...base['forearm']!, ...base['hand']!];
  base['leg'] = [...base['thigh']!, ...base['shin']!, ...base['foot']!];
  base['body'] = bones.keys.toList();
  final groups = {...base};
  for (final entry in base.entries) {
    for (final side in ['l', 'r']) {
      final scoped = entry.value.where((b) => b.endsWith('_$side')).toList();
      if (scoped.isNotEmpty) groups['${entry.key}_$side'] = scoped;
    }
  }
  return groups;
}();

bool isSegmentGroup(Object? name) => name is String && segmentGroups.containsKey(name);

/// Bones drawn as the skeleton, in draw order.
const drawOrder = [
  'shoulders', 'hips', 'torso_l', 'torso_r', 'neck', //
  'thigh_l', 'thigh_r', 'shin_l', 'shin_r', 'foot_l', 'foot_r', 'heel_l', 'heel_r',
  'upper_arm_l', 'upper_arm_r', 'forearm_l', 'forearm_r', 'hand_l', 'hand_r',
];

// ---------------------------------------------------------------------------------------------- vector maths

double deg(double radians) => radians * 180 / math.pi;
double rad(double degrees) => degrees * math.pi / 180;
double dist(Pt a, Pt b) {
  final dx = a.x - b.x;
  final dy = a.y - b.y;
  return math.sqrt(dx * dx + dy * dy);
}

double clamp(double v, double lo, double hi) => math.min(hi, math.max(lo, v));

/// Interior angle at [b] in degrees (0..180). Null if a segment has zero length.
double? angleAt(Pt a, Pt b, Pt c) {
  final v1x = a.x - b.x;
  final v1y = a.y - b.y;
  final v2x = c.x - b.x;
  final v2y = c.y - b.y;
  final n1 = math.sqrt(v1x * v1x + v1y * v1y);
  final n2 = math.sqrt(v2x * v2x + v2y * v2y);
  if (n1 < 1e-9 || n2 < 1e-9) return null;
  final cos = (v1x * v2x + v1y * v2y) / (n1 * n2);
  return deg(math.acos(math.min(1.0, math.max(-1.0, cos))));
}

/// Angle between the vector from->to and a reference axis, in degrees.
/// 'vertical': 0 when [to] is straight above [from], 90 horizontal, 180 straight below.
/// 'horizontal': the acute angle between the line and the horizontal, 0..90.
double? inclination(Pt from, Pt to, String ref) {
  final dx = to.x - from.x;
  final dy = to.y - from.y;
  if (math.sqrt(dx * dx + dy * dy) < 1e-9) return null;
  if (ref == 'vertical') return deg(math.atan2(dx.abs(), -dy));
  return deg(math.atan2(dy.abs(), dx.abs()));
}

/// Signed perpendicular distance of [p] from the line a->b; positive when p is above the line (smaller y).
double? lineOffsetUp(Pt a, Pt b, Pt p) {
  final dx = b.x - a.x;
  final dy = b.y - a.y;
  final len = math.sqrt(dx * dx + dy * dy);
  if (len < 1e-9) return null;
  // The 2D cross product is the signed distance; image y points down, so orient by the direction of travel.
  final cross = (dx * (p.y - a.y) - dy * (p.x - a.x)) / len;
  return dx >= 0 ? -cross : cross;
}

/// Scale x by the frame's aspect ratio (width / height) so distances and angles are isotropic.
List<Pt> scaleLandmarks(List<Landmark> landmarks, double aspect) => [for (final l in landmarks) Pt(l.x * aspect, l.y, l.visibility, l.z * aspect)];
