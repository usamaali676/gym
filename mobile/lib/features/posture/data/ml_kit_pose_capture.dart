// The real camera and the real pose model: the `camera` plugin streams frames into Google ML Kit pose detection (on
// device; nothing is uploaded). THIS FILE HAS NEVER RUN ON A PHONE. It compiles and is analysed in CI, and the pure
// parts it depends on are unit-tested (ml_kit_mapping.dart), but camera orientation, frame formats, coordinate
// conventions and the model's behaviour have to be confirmed on a real Android and iOS device. See
// docs/mobile/posture-coach.md for the checklist.

import 'dart:async';
import 'dart:io';

import 'package:camera/camera.dart';
import 'package:flutter/widgets.dart';
import 'package:google_mlkit_pose_detection/google_mlkit_pose_detection.dart';

import 'ml_kit_mapping.dart';
import 'pose_capture.dart';

PoseProblem _problemFor(CameraException e) => switch (e.code) {
      'CameraAccessDenied' || 'CameraAccessDeniedWithoutPrompt' || 'CameraAccessRestricted' || 'cameraPermission' => PoseProblem.permissionDenied,
      _ => PoseProblem.cameraBusy,
    };

class MlKitPoseOpener implements PoseCaptureOpener {
  const MlKitPoseOpener();

  @override
  Future<PoseCapture> open({required bool front}) async {
    if (!Platform.isAndroid && !Platform.isIOS) throw const PoseUnavailable(PoseProblem.unsupported);

    final List<CameraDescription> cameras;
    try {
      cameras = await availableCameras();
    } on CameraException catch (e) {
      throw PoseUnavailable(_problemFor(e));
    }
    if (cameras.isEmpty) throw const PoseUnavailable(PoseProblem.noCamera);

    final wanted = front ? CameraLensDirection.front : CameraLensDirection.back;
    final description = cameras.firstWhere((c) => c.lensDirection == wanted, orElse: () => cameras.first);
    final controller = CameraController(
      description,
      ResolutionPreset.medium,
      enableAudio: false,
      imageFormatGroup: Platform.isAndroid ? ImageFormatGroup.nv21 : ImageFormatGroup.bgra8888,
    );
    try {
      await controller.initialize();
    } on CameraException catch (e) {
      await controller.dispose();
      throw PoseUnavailable(_problemFor(e));
    }

    final detector = PoseDetector(options: PoseDetectorOptions(model: PoseDetectionModel.base, mode: PoseDetectionMode.stream));
    final capture = MlKitPoseCapture._(controller, detector, description, canSwitch: cameras.map((c) => c.lensDirection).toSet().length > 1);
    try {
      await capture._start();
    } catch (_) {
      await capture.close();
      throw const PoseUnavailable(PoseProblem.modelFailed);
    }
    return capture;
  }
}

class MlKitPoseCapture implements PoseCapture {
  MlKitPoseCapture._(this._controller, this._detector, this._description, {required this.canSwitch});

  final CameraController _controller;
  final PoseDetector _detector;
  final CameraDescription _description;
  final _out = StreamController<PoseFrame>.broadcast();
  final _clock = Stopwatch()..start();
  bool _busy = false;
  bool _closed = false;
  double _fps = 0;
  double _lastFrameMs = 0;

  @override
  final bool canSwitch;

  @override
  Stream<PoseFrame> get frames => _out.stream;

  @override
  bool get mirrored => _description.lensDirection == CameraLensDirection.front;

  @override
  double get fps => _fps;

  @override
  Widget buildPreview(BuildContext context) => CameraPreview(_controller);

  Future<void> _start() => _controller.startImageStream(_onImage);

  Future<void> _onImage(CameraImage image) async {
    if (_busy || _closed) return; // a busy model drops frames instead of queueing them
    _busy = true;
    try {
      final android = Platform.isAndroid;
      final rotation = imageRotationDegrees(
        android: android,
        sensorOrientation: _description.sensorOrientation,
        front: mirrored,
        deviceOrientationDegrees: deviceOrientationDegrees(_controller.value.deviceOrientation.name),
      );
      final format = InputImageFormatValue.fromRawValue(image.format.raw);
      if (format == null || (android && format != InputImageFormat.nv21) || (!android && format != InputImageFormat.bgra8888) || image.planes.length != 1) return;
      final plane = image.planes.first;

      final input = InputImage.fromBytes(
        bytes: plane.bytes,
        metadata: InputImageMetadata(
          size: Size(image.width.toDouble(), image.height.toDouble()),
          rotation: InputImageRotationValue.fromRawValue(rotation) ?? InputImageRotation.rotation0deg,
          format: format,
          bytesPerRow: plane.bytesPerRow,
        ),
      );
      final poses = await _detector.processImage(input);
      if (_closed) return;

      final upright = uprightSize(android: android, rotationDegrees: rotation, rawWidth: image.width.toDouble(), rawHeight: image.height.toDouble());
      final t = _clock.elapsedMicroseconds / 1000;
      if (_lastFrameMs > 0 && t > _lastFrameMs) {
        final instant = 1000 / (t - _lastFrameMs);
        _fps = _fps == 0 ? instant : _fps + 0.08 * (instant - _fps);
      }
      _lastFrameMs = t;
      _out.add(PoseFrame(
        landmarks: landmarksFromPose(poses.isEmpty ? null : poses.first, width: upright.width, height: upright.height),
        tMs: t,
        aspect: upright.width / upright.height,
      ));
    } catch (_) {
      // One failed frame is not fatal (a throttled camera, a model hiccup): skip it.
    } finally {
      _busy = false;
    }
  }

  @override
  Future<void> close() async {
    if (_closed) return;
    _closed = true;
    try {
      if (_controller.value.isStreamingImages) await _controller.stopImageStream();
    } catch (_) {}
    try {
      await _controller.dispose();
    } catch (_) {}
    try {
      await _detector.close();
    } catch (_) {}
    await _out.close();
  }
}
