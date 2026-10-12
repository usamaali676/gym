// Rep counting as a small hysteresis state machine over one smoothed signal (usually a joint angle). Port of
// resources/js/modules/rep-counter.js.
//
//   idle  -> ready  : the signal rests in the start zone for `settleMs`
//   ready -> out    : it moves away from the start by more than the start zone plus a margin
//   out   -> peak   : it reaches the peak zone
//   peak  -> back   : it leaves the peak zone (with a margin, so jitter at the edge cannot flip it)
//   back  -> ready  : it returns to the start zone; the rep is complete
//
// Everything is expressed as progress p = (value - start) / (peak - start): 0 at the start position, 1 at the peak,
// so the same machine counts a squat (angle falls), a lateral raise (angle rises) and a shrug. A rep that is too short
// in time is noise, one that never reaches the peak but travelled far enough is a partial rep, and the signal
// vanishing for too long aborts the rep.

import 'rule_engine.dart';

const _startMargin = 0.06; // how far past the start zone counts as "left the start"
const _peakMargin = 0.07; // how far back from the peak zone counts as "left the peak"

enum RepState { idle, ready, out, peak, back }

sealed class CounterEvent {
  const CounterEvent(this.t);

  final double t;
}

class ReadyCounted extends CounterEvent {
  const ReadyCounted(super.t);
}

class RepStarted extends CounterEvent {
  const RepStarted(super.t);
}

class PeakReached extends CounterEvent {
  const PeakReached(super.t);
}

class RepEnded extends CounterEvent {
  const RepEnded(super.t, {required this.t0, required this.ms, required this.partial, required this.rom, required this.fast, required this.peakAt});

  final double t0;
  final double ms;
  final bool partial;
  final double rom;
  final bool fast;
  final double? peakAt;
}

class NoiseRep extends CounterEvent {
  const NoiseRep(super.t, this.ms);

  final double ms;
}

class RepAborted extends CounterEvent {
  const RepAborted(super.t, this.reason);

  /// lost | too_long
  final String reason;
}

class HoldStarted extends CounterEvent {
  const HoldStarted(super.t);
}

class HoldEnded extends CounterEvent {
  const HoldEnded(super.t, {required this.ms, required this.counted});

  final double ms;
  final bool counted;
}

class _Rep {
  _Rep(this.t0, this.maxP);

  final double t0;
  double maxP;
  double? tPeak;
  bool reachedPeak = false;
}

class RepCounter {
  RepCounter(this.cfg)
      : _range = cfg.peak - cfg.start,
        _startZone = cfg.tolerance / (cfg.peak - cfg.start).abs(),
        _peakZone = 1 - cfg.tolerance / (cfg.peak - cfg.start).abs() {
    reset();
  }

  final MovementRep cfg;
  final double _range;
  final double _startZone;
  final double _peakZone;

  RepState state = RepState.idle;
  double progress = 0;
  double? _settledSince;
  double? _lostSince;
  _Rep? _rep;

  void reset() {
    state = RepState.idle;
    progress = 0;
    _settledSince = null;
    _lostSince = null;
    _rep = null;
  }

  /// Progress of a raw value along start -> peak.
  double progressOf(double value) => (value - cfg.start) / _range;

  /// Phase name used by form checks: rest | out | peak | back.
  String get phase => switch (state) {
        RepState.idle || RepState.ready => 'rest',
        RepState.out => 'out',
        RepState.peak => 'peak',
        RepState.back => 'back',
      };

  /// Feed one sample. [value] is null when the signal could not be measured.
  List<CounterEvent> update(double? value, double tMs) {
    final events = <CounterEvent>[];

    if (value == null || value.isNaN) {
      _lostSince ??= tMs;
      if (tMs - _lostSince! > cfg.lostMs && state != RepState.idle) {
        if (_rep != null) events.add(RepAborted(tMs, 'lost'));
        state = RepState.idle;
        _rep = null;
        _settledSince = null;
      }
      return events;
    }
    _lostSince = null;

    final p = progressOf(value);
    progress = p;

    switch (state) {
      case RepState.idle:
        if (p <= _startZone) {
          _settledSince ??= tMs;
          if (tMs - _settledSince! >= cfg.settleMs) {
            state = RepState.ready;
            events.add(ReadyCounted(tMs));
          }
        } else {
          _settledSince = null;
        }
      case RepState.ready:
        if (p > _startZone + _startMargin) {
          state = RepState.out;
          _rep = _Rep(tMs, p);
          events.add(RepStarted(tMs));
        }
      case RepState.out || RepState.peak || RepState.back:
        _stepRep(p, tMs, events);
    }
    return events;
  }

  void _stepRep(double p, double tMs, List<CounterEvent> events) {
    final rep = _rep!;
    if (p > rep.maxP) rep.maxP = p;

    if (tMs - rep.t0 > cfg.maxMs) {
      events.add(RepAborted(tMs, 'too_long'));
      state = RepState.idle;
      _rep = null;
      _settledSince = null;
      return;
    }

    if (p >= _peakZone) {
      if (state != RepState.peak) {
        state = RepState.peak;
        if (!rep.reachedPeak) {
          rep.reachedPeak = true;
          rep.tPeak = tMs;
          events.add(PeakReached(tMs));
        }
      }
      return;
    }

    if (state == RepState.peak && p < _peakZone - _peakMargin) state = RepState.back;

    if (p <= _startZone) _finish(tMs, events);
  }

  void _finish(double tMs, List<CounterEvent> events) {
    final rep = _rep!;
    final ms = tMs - rep.t0;
    _rep = null;
    state = RepState.ready;

    if (ms < cfg.minMs) {
      events.add(NoiseRep(tMs, ms));
      return;
    }
    if (!rep.reachedPeak && rep.maxP < cfg.minRom) {
      events.add(NoiseRep(tMs, ms));
      return;
    }

    final fastMs = cfg.fastMs;
    events.add(RepEnded(
      tMs,
      t0: rep.t0,
      ms: ms,
      partial: !rep.reachedPeak,
      rom: rep.maxP < 1.5 ? rep.maxP : 1.5,
      fast: fastMs != null && ms < fastMs,
      peakAt: rep.tPeak,
    ));
  }
}

/// Timed holds (plank, wall sit): counts time while every in-position condition holds. A hold begins after `settleMs`
/// in position and ends after `graceMs` out of it, so one noisy frame does not reset the clock.
class HoldCounter {
  HoldCounter(this.cfg) {
    reset();
  }

  final HoldRep cfg;

  /// idle | holding
  bool holding = false;
  double? _inSince;
  double? _outSince;
  double _holdStart = 0;
  double? _lastIn;

  void reset() {
    holding = false;
    _inSince = null;
    _outSince = null;
    _holdStart = 0;
    _lastIn = null;
  }

  /// True while waiting for a hold to start (the rest state).
  bool get isIdle => !holding;

  String get phase => holding ? 'hold' : 'rest';

  /// Elapsed hold time in ms for the hold in progress (0 when not holding).
  double get holdMs => holding && _lastIn != null ? _lastIn! - _holdStart : 0;

  /// [inPosition] is null when the pose cannot be measured.
  List<CounterEvent> update(bool? inPosition, double tMs) {
    final events = <CounterEvent>[];
    final inside = inPosition == true;
    final unknown = inPosition == null;

    if (!holding) {
      if (inside) {
        _inSince ??= tMs;
        if (tMs - _inSince! >= cfg.settleMs) {
          holding = true;
          _holdStart = _inSince!;
          _lastIn = tMs;
          _outSince = null;
          events.add(HoldStarted(tMs));
        }
      } else {
        _inSince = null;
      }
      return events;
    }

    if (inside) {
      _lastIn = tMs;
      _outSince = null;
    } else {
      _outSince ??= tMs;
      if (tMs - _outSince! > cfg.graceMs || (unknown && tMs - _outSince! > cfg.graceMs * 2)) {
        final ms = _lastIn! - _holdStart;
        holding = false;
        _inSince = null;
        _outSince = null;
        events.add(HoldEnded(tMs, ms: ms, counted: ms >= cfg.minHoldMs));
      }
    }
    return events;
  }

  /// Closes a hold in progress (the lifter pressed Finish).
  List<CounterEvent> flush(double tMs) {
    if (!holding) return const [];
    final ms = _lastIn! - _holdStart;
    holding = false;
    return [HoldEnded(tMs, ms: ms, counted: ms >= cfg.minHoldMs)];
  }
}
