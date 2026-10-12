import 'package:flutter_test/flutter_test.dart';
import 'package:google_mlkit_pose_detection/google_mlkit_pose_detection.dart';
import 'package:ironpulse/features/posture/data/ml_kit_mapping.dart';
import 'package:ironpulse/features/posture/domain/geometry.dart';

PoseLandmark _lm(PoseLandmarkType type, double x, double y, {double z = 0, double likelihood = 0.9}) => PoseLandmark(type: type, x: x, y: y, z: z, likelihood: likelihood);

void main() {
  group('landmarksFromPose', () {
    test('ML Kit and the rule engine number the landmarks the same way', () {
      expect(PoseLandmarkType.values.length, landmarkCount);
      for (final entry in landmarkIndex.entries) {
        final type = PoseLandmarkType.values[entry.value];
        // leftEyeInner -> l_eye_inner, rightFootIndex -> r_foot_index, nose -> nose
        final snake = type.name.replaceAllMapped(RegExp(r'[A-Z]'), (m) => '_${m[0]!.toLowerCase()}');
        final expected = snake.startsWith('left_')
            ? 'l_${snake.substring(5)}'
            : snake.startsWith('right_')
            ? 'r_${snake.substring(6)}'
            : snake;
        expect(entry.key, expected, reason: 'index ${entry.value}');
      }
    });

    test('pixels become 0..1 coordinates of the upright image', () {
      final pose = Pose(
        landmarks: {PoseLandmarkType.nose: _lm(PoseLandmarkType.nose, 360, 640, z: -36, likelihood: 0.97), PoseLandmarkType.leftShoulder: _lm(PoseLandmarkType.leftShoulder, 180, 320)},
      );
      final l = landmarksFromPose(pose, width: 720, height: 1280)!;
      expect(l, hasLength(33));
      expect(l[0].x, 0.5);
      expect(l[0].y, 0.5);
      expect(l[0].z, closeTo(-0.05, 1e-9));
      expect(l[0].visibility, 0.97);
      expect(l[landmarkIndex['l_shoulder']!].x, 0.25);
      expect(l[landmarkIndex['l_shoulder']!].y, 0.25);
    });

    test('a landmark ML Kit did not report is invisible, not invented', () {
      final pose = Pose(landmarks: {PoseLandmarkType.nose: _lm(PoseLandmarkType.nose, 10, 10)});
      final l = landmarksFromPose(pose, width: 100, height: 100)!;
      expect(l[landmarkIndex['l_ankle']!].visibility, 0);
    });

    test('likelihood is kept within 0..1', () {
      final pose = Pose(landmarks: {PoseLandmarkType.nose: _lm(PoseLandmarkType.nose, 10, 10, likelihood: 1.4)});
      expect(landmarksFromPose(pose, width: 100, height: 100)![0].visibility, 1);
    });

    test('no pose, no landmarks, no size: nothing to analyse', () {
      expect(landmarksFromPose(null, width: 100, height: 100), isNull);
      expect(landmarksFromPose(Pose(landmarks: {}), width: 100, height: 100), isNull);
      expect(landmarksFromPose(Pose(landmarks: {PoseLandmarkType.nose: _lm(PoseLandmarkType.nose, 1, 1)}), width: 0, height: 100), isNull);
    });
  });

  group('camera orientation', () {
    test('Android back camera in portrait: the sensor is mounted at 90 degrees', () {
      expect(imageRotationDegrees(android: true, sensorOrientation: 90, front: false, deviceOrientationDegrees: 0), 90);
    });

    test('Android front camera in portrait: the sensor is mounted at 270 degrees', () {
      expect(imageRotationDegrees(android: true, sensorOrientation: 270, front: true, deviceOrientationDegrees: 0), 270);
    });

    test('rotation compensates for the way the phone is held', () {
      expect(imageRotationDegrees(android: true, sensorOrientation: 90, front: false, deviceOrientationDegrees: 90), 0);
      expect(imageRotationDegrees(android: true, sensorOrientation: 270, front: true, deviceOrientationDegrees: 90), 0);
      expect(imageRotationDegrees(android: true, sensorOrientation: 90, front: false, deviceOrientationDegrees: 270), 180);
    });

    test('iOS frames arrive upright: the sensor angle is passed through and not used', () {
      expect(imageRotationDegrees(android: false, sensorOrientation: 90, front: true, deviceOrientationDegrees: 0), 90);
    });

    test('the Android frame is landscape but its coordinates are portrait: width and height swap for a quarter turn', () {
      final s = uprightSize(android: true, rotationDegrees: 90, rawWidth: 1280, rawHeight: 720);
      expect((s.width, s.height), (720.0, 1280.0));
      final flat = uprightSize(android: true, rotationDegrees: 0, rawWidth: 1280, rawHeight: 720);
      expect((flat.width, flat.height), (1280.0, 720.0));
      final upside = uprightSize(android: true, rotationDegrees: 180, rawWidth: 1280, rawHeight: 720);
      expect((upside.width, upside.height), (1280.0, 720.0));
    });

    test('on iOS the frame size is used as it is', () {
      final s = uprightSize(android: false, rotationDegrees: 90, rawWidth: 720, rawHeight: 1280);
      expect((s.width, s.height), (720.0, 1280.0));
    });

    test('device orientation names map to degrees', () {
      expect(
        [
          for (final n in ['portraitUp', 'landscapeLeft', 'portraitDown', 'landscapeRight']) deviceOrientationDegrees(n),
        ],
        [0, 90, 180, 270],
      );
    });
  });
}
