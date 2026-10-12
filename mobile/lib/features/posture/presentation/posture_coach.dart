// The camera coach's state machine for one set:
//
//   intro -> loading -> position -> countdown -> live -> summary
//                \-> error (camera blocked, no model, ...)
//
// Mirrors the web's Posture Lab (resources/js/modules/posture-lab.js). The heavy handles (the camera, the analyzer)
// live here and not in widget state: frames arrive 15-30 times a second and must not rebuild the screen. The HUD is
// refreshed about 12 times a second; the skeleton overlay repaints on every frame through [overlay].
//
// Timing comes from the frames' own timestamps, so a recorded sequence replays deterministically in tests.

import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:uuid/uuid.dart';

import '../../../core/api/api_error.dart';
import '../../../core/utilities/device_feedback.dart';
import '../data/pose_capture.dart';
import '../data/posture_repository.dart';
import '../domain/geometry.dart';
import '../domain/posture_analyzer.dart';
import '../domain/posture_models.dart';

enum CoachPhase { intro, loading, position, countdown, live, summary, error }

enum SaveState { idle, saving, saved, failed, empty }

enum CueTone { ok, warn, bad }

class Cue {
  const Cue(this.text, this.tone);

  final String text;
  final CueTone tone;
}

/// What the skeleton painter needs for one frame.
class OverlayData {
  const OverlayData({this.landmarks, this.redBones = const {}, this.showGuide = false, this.mirrored = false});

  static const empty = OverlayData();

  final List<Landmark>? landmarks;
  final Set<String> redBones;

  /// While positioning, an outline to fit the whole body in.
  final bool showGuide;
  final bool mirrored;
}

const _readyFrames = 24; // about 0.8 s of a steady ready pose before the countdown starts
const _hudIntervalMs = 80;
const _cueShowMs = 2600;

const _jointWords = {'shoulder': 'shoulders', 'elbow': 'elbows', 'wrist': 'wrists', 'hip': 'hips', 'knee': 'knees', 'ankle': 'ankles', 'ear': 'ears', 'heel': 'heels', 'foot_index': 'feet'};

String _sideWord(String name) => name.replaceFirst(RegExp(r'^l_'), 'left ').replaceFirst(RegExp(r'^r_'), 'right ').replaceAll('_', ' ');

class PostureCoach extends ChangeNotifier {
  PostureCoach({
    required this.rule,
    required this.opener,
    required this.repository,
    required this.feedback,
    this.workoutSessionId,
    DateTime Function()? now,
    String Function()? newUuid,
    this._front = true,
  })  : _now = now ?? DateTime.now,
        _newUuid = newUuid ?? (() => const Uuid().v4());

  final PostureRule rule;
  final PoseCaptureOpener opener;
  final PostureRepository repository;
  final DeviceFeedback feedback;
  final int? workoutSessionId;
  final DateTime Function() _now;
  final String Function() _newUuid;

  /// Repaints the skeleton on every frame.
  final overlay = ValueNotifier<OverlayData>(OverlayData.empty);

  // ---- state shown on screen
  CoachPhase phase = CoachPhase.intro;
  String? problem;
  String status = 'no_person';
  String statusMessage = '';
  int count = 3;
  int reps = 0;
  int good = 0;
  int bad = 0;
  double holdSeconds = 0;
  int score = 100;
  double progress = 0;
  String repPhase = 'rest';
  Cue? cue;
  RepFeedback? lastRep;
  int elapsed = 0;
  bool paused = false;
  int positionSeconds = 0;
  double aspect = 9 / 16;
  PostureSummary? summary;
  SaveState saveState = SaveState.idle;
  String saveError = '';
  PostureSaveResult? saved;

  bool _front;
  bool get front => _front;
  bool get mirrored => _capture?.mirrored ?? _front;
  bool get canSwitch => _capture?.canSwitch ?? false;
  double get fps => _capture?.fps ?? 0;
  bool get timed => rule.timed;
  Widget? preview(BuildContext context) => _capture?.buildPreview(context);

  /// A set with nothing counted is not saved.
  bool get hasResult => summary?.hasResult ?? false;

  // ---- handles
  PoseCapture? _capture;
  StreamSubscription<PoseFrame>? _frames;
  PostureAnalyzer? _analyzer;
  FrameResult? _lastResult;
  Timer? _positionTimer;
  Timer? _countdownTimer;
  Timer? _cueTimer;
  int _readyStreak = 0;
  double _lastHudT = -1000;
  double _lastFrameT = 0;
  double _liveStartT = 0;
  double _pausedAtT = 0;
  double _pausedTotal = 0;
  DateTime? _liveStartedAt;
  double? _avgFps;
  String? _clientUuid;
  bool _released = false; // the camera was given back because the app went to the background
  bool _disposed = false;

  void _notify() {
    if (!_disposed) notifyListeners();
  }

  // ------------------------------------------------------------------------------------------------ starting

  Future<void> start() async {
    if (phase == CoachPhase.loading) return;
    problem = null;
    phase = CoachPhase.loading;
    _notify();
    await _openCamera();
  }

  Future<void> _openCamera() async {
    try {
      final capture = await opener.open(front: _front);
      if (_disposed) {
        await capture.close();
        return;
      }
      _capture = capture;
      _frames = capture.frames.listen(_onFrame);
      unawaited(feedback.keepScreenOn(true));
      if (phase == CoachPhase.loading) {
        _analyzer = PostureAnalyzer(rule.compiled, aspect: aspect);
        _readyStreak = 0;
        _resetHud();
        _enterPosition();
      }
      _notify();
    } on PoseUnavailable catch (e) {
      _fail(e.problem.message);
    } catch (_) {
      _fail(PoseProblem.cameraBusy.message);
    }
  }

  void _fail(String message) {
    _teardownCamera();
    problem = message;
    phase = CoachPhase.error;
    _notify();
  }

  void _enterPosition() {
    phase = CoachPhase.position;
    positionSeconds = 0;
    _positionTimer?.cancel();
    _positionTimer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (phase == CoachPhase.position) {
        positionSeconds += 1;
        _notify();
      }
    });
  }

  Future<void> switchCamera() async {
    if (!canSwitch || phase == CoachPhase.loading) return;
    _front = !_front;
    await _closeCapture();
    await _openCamera();
  }

  // ------------------------------------------------------------------------------------------------ frames

  void _onFrame(PoseFrame f) {
    final analyzer = _analyzer;
    if (analyzer == null) return;
    _lastFrameT = f.tMs;
    aspect = f.aspect;

    final FrameResult result;
    if (paused) {
      result = _lastResult ?? analyzer.update(f.landmarks, f.tMs, aspect: f.aspect);
    } else {
      result = analyzer.update(f.landmarks, f.tMs, aspect: f.aspect);
      _handleEvents(result);
    }
    _lastResult = result;

    final guide = phase == CoachPhase.position && (f.landmarks == null || result.status != 'ready');
    overlay.value = OverlayData(landmarks: f.landmarks, redBones: result.redBones, showGuide: guide, mirrored: mirrored);

    if (phase == CoachPhase.position) _watchReadiness(result);
    if (phase == CoachPhase.countdown && (result.status == 'no_person' || result.status == 'adjust')) _cancelCountdown();

    if (f.tMs - _lastHudT >= _hudIntervalMs) {
      _lastHudT = f.tMs;
      _applyHud(result, f.tMs);
      _notify();
    }
  }

  void _applyHud(FrameResult r, double t) {
    status = r.status;
    statusMessage = _messageFor(r);
    reps = r.reps;
    good = r.good;
    bad = r.bad;
    score = r.score;
    progress = (r.progress.clamp(0.0, 1.0) * 100).round() / 100;
    repPhase = r.phase;
    holdSeconds = (r.holdMs / 100).round() / 10;
    if (phase == CoachPhase.live) elapsed = ((t - _liveStartT - _pausedTotal) / 1000).round().clamp(0, 1 << 30);
    if (r.lastRep != null) lastRep = r.lastRep;
  }

  String _messageFor(FrameResult r) {
    switch (r.status) {
      case 'no_person':
        return "I can't see you. Step into the frame so your whole body is visible.";
      case 'adjust':
        final parts = <String>{
          for (final m in r.missing) RegExp(r'^[lr]_').hasMatch(m) ? _sideWord(m) : (_jointWords[m] ?? m),
        }.take(3).join(', ');
        return 'Move so I can see your $parts. ${rule.cameraView == 'side' ? 'Turn side-on to the camera.' : 'Step back from the camera.'}';
      case 'positioning':
        return timed ? 'Get into position and hold still.' : 'Stand in your starting position.';
      case 'ready':
        return 'Ready. Start your first rep.';
      default:
        return '';
    }
  }

  void _resetHud() {
    status = 'no_person';
    statusMessage = '';
    reps = 0;
    good = 0;
    bad = 0;
    score = 100;
    progress = 0;
    repPhase = 'rest';
    cue = null;
    lastRep = null;
    elapsed = 0;
    holdSeconds = 0;
    _lastResult = null;
  }

  // ------------------------------------------------------------------------------------------------ positioning and countdown

  void _watchReadiness(FrameResult r) {
    final ok = timed ? r.status == 'active' : (r.status == 'ready' || r.status == 'active');
    _readyStreak = ok ? _readyStreak + 1 : 0;
    if (_readyStreak >= (timed ? 2 : _readyFrames)) _beginSet(immediate: timed);
  }

  /// Skip the wait: start the countdown even if the camera has not seen the start position.
  void startNow() {
    if (phase == CoachPhase.position) _beginSet(immediate: false);
  }

  void _beginSet({required bool immediate}) {
    _positionTimer?.cancel();
    if (immediate) {
      _goLive(resetAnalyzer: false);
      return;
    }
    phase = CoachPhase.countdown;
    count = 3;
    _notify();
    _countdownTimer?.cancel();
    _countdownTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      count -= 1;
      if (count > 0) {
        _notify();
      } else {
        timer.cancel();
        _goLive(resetAnalyzer: true);
      }
    });
  }

  void _cancelCountdown() {
    _countdownTimer?.cancel();
    _readyStreak = 0;
    _enterPosition();
    _notify();
  }

  void _goLive({required bool resetAnalyzer}) {
    if (resetAnalyzer) _analyzer?.reset();
    phase = CoachPhase.live;
    reps = 0;
    good = 0;
    bad = 0;
    lastRep = null;
    elapsed = 0;
    paused = false;
    _pausedTotal = 0;
    _liveStartT = _lastFrameT;
    _liveStartedAt = _now();
    _notify();
  }

  // ------------------------------------------------------------------------------------------------ live set

  void _handleEvents(FrameResult r) {
    if (phase != CoachPhase.live) return;
    for (final e in r.events) {
      switch (e) {
        case RepEvent():
          final rep = e.rep;
          if (rep.record.good) {
            _showCue(rep.record.hold ? 'Solid hold, ${(rep.record.ms / 1000).round()} s' : 'Rep ${e.reps}: good', CueTone.ok);
          } else {
            _showCue(rep.feedback, CueTone.bad);
          }
          feedback.repCounted(good: rep.record.good);
        case CueEvent():
          _showCue(e.message, e.severity == 'major' ? CueTone.bad : CueTone.warn);
          feedback.formAlert();
        case AbortEvent():
          _showCue('Lost sight of you, that rep was not counted.', CueTone.warn);
        case HoldShortEvent():
          _showCue('Hold for at least a few seconds to count.', CueTone.warn);
        case ReadyEvent() || PeakEvent():
          break;
      }
    }
  }

  void _showCue(String text, CueTone tone) {
    cue = Cue(text, tone);
    _cueTimer?.cancel();
    _cueTimer = Timer(const Duration(milliseconds: _cueShowMs), () {
      cue = null;
      _notify();
    });
  }

  void pause() {
    if (phase != CoachPhase.live || paused) return;
    paused = true;
    _pausedAtT = _lastFrameT;
    _notify();
  }

  void resume() {
    if (!paused) return;
    _pausedTotal += _lastFrameT - _pausedAtT;
    paused = false;
    _notify();
  }

  /// Throw this set away and get back into position.
  void restart() {
    _analyzer?.reset();
    _resetHud();
    _readyStreak = 0;
    paused = false;
    if (_capture != null) {
      _enterPosition();
    } else {
      unawaited(start());
    }
    _notify();
  }

  Future<void> finish() async {
    if (phase != CoachPhase.live) return;
    final t = _lastFrameT;
    if (paused) _pausedTotal += t - _pausedAtT;
    final analyzer = _analyzer!;
    analyzer.flush(t);
    final seconds = (t - _liveStartT - _pausedTotal) / 1000;
    summary = analyzer.summary(seconds);
    _avgFps = _capture?.fps;
    paused = false;
    _cueTimer?.cancel();
    cue = null;
    await _closeCapture();
    phase = CoachPhase.summary;
    overlay.value = OverlayData.empty;
    _notify();
    await save();
  }

  // ------------------------------------------------------------------------------------------------ saving

  Future<void> save() async {
    final s = summary;
    if (s == null) return;
    if (!s.hasResult) {
      saveState = SaveState.empty;
      _notify();
      return;
    }
    saveState = SaveState.saving;
    saveError = '';
    _notify();
    _clientUuid ??= _newUuid();
    try {
      saved = await repository.save(rule, FinishedSet(summary: s, startedAt: _liveStartedAt ?? _now(), clientUuid: _clientUuid!, avgFps: _avgFps), workoutSessionId: workoutSessionId);
      saveState = SaveState.saved;
    } catch (error) {
      final e = ApiError.from(error);
      saveState = SaveState.failed;
      saveError = switch (e.kind) {
        ApiErrorKind.validation => 'The server rejected this set. It is shown here but was not saved.',
        ApiErrorKind.entitlementRequired => 'Your membership does not include saving camera sets.',
        _ when e.isOffline => 'You are offline. Your set is shown here; save it when you are back online.',
        _ => 'Could not save this set. It is shown here; try saving again.',
      };
    }
    _notify();
  }

  /// Another set: a fresh start with a fresh upload id.
  Future<void> again() async {
    summary = null;
    saved = null;
    _clientUuid = null;
    saveState = SaveState.idle;
    _resetHud();
    await start();
  }

  // ------------------------------------------------------------------------------------------------ app lifecycle

  /// Phones take the camera away when the app is backgrounded; give it back cleanly and ask for it again on return.
  void lifecycle(AppLifecycleState state) {
    switch (state) {
      case AppLifecycleState.paused || AppLifecycleState.hidden:
        if (_capture == null || phase == CoachPhase.summary || phase == CoachPhase.intro) return;
        if (phase == CoachPhase.live) pause();
        if (phase == CoachPhase.countdown) _cancelCountdown();
        _released = true;
        unawaited(_closeCapture());
      case AppLifecycleState.resumed:
        if (!_released) return;
        _released = false;
        unawaited(_openCamera());
      case AppLifecycleState.inactive || AppLifecycleState.detached:
        break;
    }
  }

  // ------------------------------------------------------------------------------------------------ teardown

  Future<void> _closeCapture() async {
    final capture = _capture;
    _capture = null;
    // Cancelling stops the events at once; there is nothing to wait for.
    unawaited(_frames?.cancel());
    _frames = null;
    unawaited(feedback.keepScreenOn(false));
    await capture?.close();
  }

  void _teardownCamera() {
    unawaited(_closeCapture());
    _positionTimer?.cancel();
    _countdownTimer?.cancel();
    _cueTimer?.cancel();
  }

  @override
  void dispose() {
    _disposed = true;
    _teardownCamera();
    overlay.dispose();
    super.dispose();
  }
}
