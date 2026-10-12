// Pure helpers between ML Kit's pose output and the app's landmark model. Kept apart from the camera code so they can be
// unit-tested without a device (test/features/posture/ml_kit_mapping_test.dart).

import 'package:google_mlkit_pose_detection/google_mlkit_pose_detection.dart';

import '../domain/geometry.dart';

/// ML Kit's `PoseLandmarkType` has the 33 BlazePose landmarks in the same order MediaPipe uses, which is the order the
/// rule engine's point names refer to ([landmarkIndex]).
const _count = landmarkCount;

/// ML Kit pixel coordinates -> normalised 0..1 landmarks. [width] and [height] are the size of the **upright** image the
/// coordinates refer to (see [uprightSize]). A landmark ML Kit did not report becomes invisible rather than being invented.
/// Returns null when there is no pose.
List<Landmark>? landmarksFromPose(Pose? pose, {required double width, required double height}) {
  if (pose == null || pose.landmarks.isEmpty || width <= 0 || height <= 0) return null;
  const types = PoseLandmarkType.values;
  assert(types.length == _count, 'ML Kit and the rule engine must agree on 33 landmarks');
  return [
    for (var i = 0; i < _count; i++)
      switch (pose.landmarks[types[i]]) {
        final l? => Landmark(l.x / width, l.y / height, z: l.z / width, visibility: l.likelihood.clamp(0.0, 1.0)),
        null => const Landmark(0.5, 0.5, visibility: 0),
      },
  ];
}

/// The rotation, in degrees, to tell ML Kit so it reads a camera frame upright. Only Android uses it; iOS delivers
/// frames already upright.
int imageRotationDegrees({required bool android, required int sensorOrientation, required bool front, required int deviceOrientationDegrees}) {
  if (!android) return sensorOrientation;
  return front ? (sensorOrientation + deviceOrientationDegrees) % 360 : (sensorOrientation - deviceOrientationDegrees + 360) % 360;
}

/// Size of the frame as the person sees it. On Android the raw frame is landscape and ML Kit reports coordinates in
/// the rotated (portrait) image, so width and height swap for a quarter turn.
({double width, double height}) uprightSize({required bool android, required int rotationDegrees, required double rawWidth, required double rawHeight}) {
  final quarterTurn = rotationDegrees == 90 || rotationDegrees == 270;
  return android && quarterTurn ? (width: rawHeight, height: rawWidth) : (width: rawWidth, height: rawHeight);
}

/// Device orientation (as the camera plugin reports it) in degrees for [imageRotationDegrees].
int deviceOrientationDegrees(String orientation) => switch (orientation) {
      'landscapeLeft' => 90,
      'portraitDown' => 180,
      'landscapeRight' => 270,
      _ => 0,
    };
