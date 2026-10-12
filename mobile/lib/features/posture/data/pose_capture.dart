import 'package:flutter/widgets.dart';

import '../domain/geometry.dart';

/// One analysed camera frame: the landmarks (null when nobody is in view), when it was taken and the frame's shape.
class PoseFrame {
  const PoseFrame({required this.landmarks, required this.tMs, required this.aspect});

  final List<Landmark>? landmarks;

  /// Milliseconds on a monotonic clock.
  final double tMs;

  /// Width / height of the upright frame (about 0.56 for a portrait phone).
  final double aspect;
}

/// Why the camera or the pose model could not start. The text is what the member reads.
enum PoseProblem {
  permissionDenied('Camera permission was blocked. Allow the camera for IronPulse in your phone\'s settings, then try again.'),
  noCamera('No camera was found on this device.'),
  cameraBusy('The camera is busy or could not be started. Close other apps that use it and try again.'),
  modelFailed('The pose model could not be started on this device.'),
  unsupported('The camera coach is not available on this device.');

  const PoseProblem(this.message);

  final String message;
}

class PoseUnavailable implements Exception {
  const PoseUnavailable(this.problem);

  final PoseProblem problem;

  @override
  String toString() => problem.message;
}

/// An opened camera plus a pose model. Everything runs on the phone: frames are analysed in memory and never stored or
/// sent anywhere.
abstract interface class PoseCapture {
  /// One event per analysed frame. A busy model drops frames rather than queueing them.
  Stream<PoseFrame> get frames;

  /// The live camera picture (the app draws the skeleton on top of it).
  Widget buildPreview(BuildContext context);

  /// True when the picture is mirrored like a selfie (the overlay must mirror too).
  bool get mirrored;

  /// Frames analysed per second, smoothed. 0 until known.
  double get fps;

  /// Whether the phone has more than one camera to choose from.
  bool get canSwitch;

  Future<void> close();
}

abstract interface class PoseCaptureOpener {
  /// [front] picks the selfie camera. Throws [PoseUnavailable].
  Future<PoseCapture> open({required bool front});
}
