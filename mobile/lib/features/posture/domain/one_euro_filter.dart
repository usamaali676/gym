// One-Euro filter (Casiez, Roussel, Vogel 2012): a low-pass filter whose cut-off rises with speed, so a still limb is
// steady while a fast one keeps up with little lag. Pose landmarks jitter from frame to frame, which would flicker the
// skeleton and wreck threshold-based rep counting. Port of resources/js/modules/one-euro-filter.js.

import 'dart:math' as math;

import 'geometry.dart';

double _smoothingFactor(double cutoff, double dt) {
  final r = 2 * math.pi * cutoff * dt;
  return r / (r + 1);
}

class OneEuroFilter {
  OneEuroFilter({this.minCutoff = 1.0, this.beta = 40, this.dCutoff = 2});

  /// Hz at rest (lower = smoother).
  final double minCutoff;

  /// How fast the cut-off grows with speed.
  final double beta;

  /// Hz for the speed estimate.
  final double dCutoff;

  double? _t;
  double _x = 0;
  double _dx = 0;

  void reset() {
    _t = null;
    _x = 0;
    _dx = 0;
  }

  /// [tMs] is a timestamp in milliseconds.
  double filter(double value, double tMs) {
    final last = _t;
    if (last == null) {
      _t = tMs;
      _x = value;
      _dx = 0;
      return value;
    }

    final dt = math.max((tMs - last) / 1000, 1e-3);
    _t = tMs;

    final rawSpeed = (value - _x) / dt;
    _dx += _smoothingFactor(dCutoff, dt) * (rawSpeed - _dx);

    final cutoff = minCutoff + beta * _dx.abs();
    _x += _smoothingFactor(cutoff, dt) * (value - _x);
    return _x;
  }
}

class _Axes {
  _Axes(double minCutoff, double beta, double dCutoff)
      : x = OneEuroFilter(minCutoff: minCutoff, beta: beta, dCutoff: dCutoff),
        y = OneEuroFilter(minCutoff: minCutoff, beta: beta, dCutoff: dCutoff),
        z = OneEuroFilter(minCutoff: minCutoff, beta: beta, dCutoff: dCutoff);

  final OneEuroFilter x;
  final OneEuroFilter y;
  final OneEuroFilter z;
}

/// One filter per landmark axis. Resets itself after a gap (the person left the frame).
class LandmarkSmoother {
  LandmarkSmoother({this.minCutoff = 1.0, this.beta = 40, this.dCutoff = 2, this.count = landmarkCount, this.resetGapMs = 500}) {
    reset();
  }

  final double minCutoff;
  final double beta;
  final double dCutoff;
  final int count;
  final double resetGapMs;

  late List<_Axes> _filters;
  late List<double?> _visibility;
  double? _lastT;

  void reset() {
    _filters = List.generate(count, (_) => _Axes(minCutoff, beta, dCutoff));
    _visibility = List<double?>.filled(count, null);
    _lastT = null;
  }

  List<Landmark> smooth(List<Landmark> landmarks, double tMs) {
    final last = _lastT;
    if (last != null && tMs - last > resetGapMs) reset();
    _lastT = tMs;

    final out = <Landmark>[];
    for (var i = 0; i < landmarks.length; i++) {
      final l = landmarks[i];
      final f = _filters[i];
      // Visibility flickers around thresholds; a light moving average keeps bones from blinking on and off.
      final previous = _visibility[i];
      final visibility = previous == null ? l.visibility : previous + 0.5 * (l.visibility - previous);
      _visibility[i] = visibility;
      out.add(Landmark(f.x.filter(l.x, tMs), f.y.filter(l.y, tMs), z: f.z.filter(l.z, tMs), visibility: visibility));
    }
    return out;
  }
}
