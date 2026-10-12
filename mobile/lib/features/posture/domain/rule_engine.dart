// Posture rule engine: validates a rule's JSON config (schema 1), compiles it, and evaluates its named measures on one
// frame of landmarks. Pure functions. A port of resources/js/modules/rule-engine.js: the messages and their order match
// the JavaScript and the PHP validator, and mobile/test/features/posture/parity_test.dart proves it.
//
// The rules themselves come from the server (GET /posture/rules/{exercise}); the app hard-codes none of them.

import 'geometry.dart';

const ruleSchema = 1;
const _sides = ['auto', 'left', 'right', 'both'];
const _measureTypes = ['angle', 'inclination', 'offset', 'ratio', 'line_offset', 'diff'];
const _norms = ['torso', 'shoulder_width', 'thigh', 'shank', 'upper_arm', 'forearm', 'none'];
const _axes = ['x', 'forward', 'up'];
const _ops = ['<', '>', 'outside', 'inside'];
const _phases = ['rest', 'out', 'peak', 'back', 'hold'];
const _stats = ['min', 'max', 'peak', 'range'];
const _severities = ['major', 'minor'];
const _combines = ['mean', 'min', 'max'];
const _relatives = ['delta', 'ratio'];

final _nameRe = RegExp(r'^[a-z][a-z0-9_]*$');

bool _isNum(Object? v) => v is num && v.isFinite;
bool _isObj(Object? v) => v is Map;
bool _isInt(Object? v) => v is num && v.isFinite && v == v.roundToDouble();

/// How JavaScript would print [v] inside a template string (only used to keep validation messages identical).
String _str(Object? v) {
  if (v == null) return 'null';
  if (v is double && v.isFinite && v == v.roundToDouble()) return v.toInt().toString();
  if (v is List) return v.map(_str).join(',');
  if (v is Map) return '[object Object]';
  return '$v';
}

/// [key] of [map] as a template string would show it: a missing key reads "undefined".
String _show(Object? map, String key) => map is Map && map.containsKey(key) ? _str(map[key]) : 'undefined';

bool _has(Map<dynamic, dynamic> map, String key) => map.containsKey(key);

class RuleConfigError implements Exception {
  RuleConfigError(this.errors);

  final List<String> errors;

  @override
  String toString() => 'Invalid posture rule: ${errors.join('; ')}';
}

// ---------------------------------------------------------------------------------------------- validation

/// Human-readable problems with [config]; empty when it is valid.
List<String> validateRule(Object? config) {
  final errors = <String>[];
  if (!_isObj(config)) return ['config must be an object'];
  final c = config! as Map;

  if (c['schema'] != ruleSchema) errors.add('schema must be $ruleSchema');
  if (_has(c, 'side') && !_sides.contains(c['side'])) errors.add('side must be one of ${_sides.join(', ')}');
  if (_has(c, 'min_visibility')) {
    final v = c['min_visibility'];
    if (!(_isNum(v) && (v as num) >= 0.1 && v <= 0.95)) errors.add('min_visibility must be a number between 0.1 and 0.95');
  }
  if (_has(c, 'good_score')) {
    final v = c['good_score'];
    if (!(_isNum(v) && (v as num) >= 0 && v <= 100)) errors.add('good_score must be a number between 0 and 100');
  }
  if (_has(c, 'smoothing')) {
    final s = c['smoothing'];
    var bad = !_isObj(s);
    if (!bad) {
      final m = s! as Map;
      if (_has(m, 'min_cutoff') && !(_isNum(m['min_cutoff']) && (m['min_cutoff'] as num) > 0)) bad = true;
      if (_has(m, 'beta') && !(_isNum(m['beta']) && (m['beta'] as num) >= 0)) bad = true;
    }
    if (bad) errors.add('smoothing needs min_cutoff > 0 and beta >= 0');
  }

  final required = c['required'];
  if (required is! List || required.isEmpty) {
    errors.add('required must list at least one point');
  } else {
    for (final p in required) {
      if (!isPointName(p)) errors.add('unknown point "${_str(p)}" in required');
    }
  }

  final measures = _isObj(c['measures']) ? c['measures'] as Map : null;
  if (measures == null || measures.isEmpty) {
    errors.add('measures must define at least one measure');
  } else {
    for (final entry in measures.entries) {
      _validateMeasure(entry.key as String, entry.value, measures, errors);
    }
  }

  final names = measures == null ? <String>{} : {for (final k in measures.keys) k as String};
  _validateRep(c['rep'], names, errors);
  _validateChecks(c, names, errors);
  return errors;
}

void _checkPoint(String measureName, Object? point, List<String> errors) {
  if (!isPointName(point)) errors.add('unknown point "${_str(point)}" in measure "$measureName"');
}

void _checkPointKey(String measureName, Map def, String key, List<String> errors) {
  if (!isPointName(def[key])) errors.add('unknown point "${_show(def, key)}" in measure "$measureName"');
}

void _validateMeasure(String name, Object? raw, Map measures, List<String> errors) {
  if (!_nameRe.hasMatch(name)) {
    errors.add('measure name "$name" is invalid');
    return;
  }
  if (!_isObj(raw)) {
    errors.add('measure "$name" must be an object');
    return;
  }
  final def = raw! as Map;
  if (!_measureTypes.contains(def['type'])) {
    errors.add('measure "$name" has unknown type "${_show(def, 'type')}"');
    return;
  }
  if (_has(def, 'combine') && !_combines.contains(def['combine'])) errors.add('measure "$name": combine must be one of ${_combines.join(', ')}');
  if (_has(def, 'relative') && def['relative'] != null && !_relatives.contains(def['relative'])) errors.add('measure "$name": relative must be one of ${_relatives.join(', ')}');
  if (_has(def, 'norm') && !_norms.contains(def['norm'])) errors.add('measure "$name": norm must be one of ${_norms.join(', ')}');

  switch (def['type']) {
    case 'angle':
      final points = def['points'];
      if (points is! List || points.length != 3) {
        errors.add('measure "$name": points must list exactly 3 points');
      } else {
        for (final p in points) {
          _checkPoint(name, p, errors);
        }
      }
    case 'inclination':
      _checkPointKey(name, def, 'from', errors);
      _checkPointKey(name, def, 'to', errors);
      if (!['vertical', 'horizontal'].contains(def['ref'])) errors.add('measure "$name": ref must be vertical or horizontal');
    case 'offset':
      _checkPointKey(name, def, 'from', errors);
      _checkPointKey(name, def, 'to', errors);
      if (!_axes.contains(def['axis'])) errors.add('measure "$name": axis must be one of ${_axes.join(', ')}');
    case 'ratio':
      for (final key in ['num', 'den']) {
        final list = def[key];
        if (list is! List || list.length != 2) {
          errors.add('measure "$name": $key must list exactly 2 points');
        } else {
          for (final p in list) {
            _checkPoint(name, p, errors);
          }
        }
      }
    case 'line_offset':
      _checkPointKey(name, def, 'from', errors);
      _checkPointKey(name, def, 'to', errors);
      _checkPointKey(name, def, 'point', errors);
    case 'diff':
      for (final key in ['a', 'b']) {
        final ref = def[key];
        final target = ref is String ? measures[ref] : null;
        if (ref is! String || ref == name || !_isObj(target)) {
          errors.add('measure "$name": $key must reference another measure');
        } else if ((target as Map)['type'] == 'diff') {
          errors.add('measure "$name": $key cannot reference a diff measure');
        }
      }
  }
}

void _validateRep(Object? raw, Set<String> measureNames, List<String> errors) {
  if (!_isObj(raw)) {
    errors.add('rep must be an object');
    return;
  }
  final rep = raw! as Map;

  if (rep['hold'] == true) {
    final inPosition = rep['in_position'];
    if (inPosition is! List || inPosition.isEmpty) {
      errors.add('rep.in_position must list at least one condition for a hold');
    } else {
      for (var i = 0; i < inPosition.length; i++) {
        final cond = inPosition[i];
        if (!_isObj(cond) || !measureNames.contains((cond as Map)['measure'])) {
          errors.add('rep.in_position[$i] must reference a measure');
        } else {
          _validateComparison('rep.in_position[$i]', cond, errors);
        }
      }
    }
    for (final key in ['settle_ms', 'grace_ms', 'min_hold_ms']) {
      if (_has(rep, key) && !(_isNum(rep[key]) && (rep[key] as num) >= 0)) errors.add('rep.$key must be a number >= 0');
    }
    return;
  }

  final signal = rep['signal'];
  if (signal is! String || !measureNames.contains(signal)) errors.add('rep.signal "${_show(rep, 'signal')}" is not a measure');
  if (!_isNum(rep['start']) || !_isNum(rep['peak'])) {
    errors.add('rep.start and rep.peak must be numbers');
    return;
  }
  final start = (rep['start'] as num).toDouble();
  final peak = (rep['peak'] as num).toDouble();
  if (start == peak) errors.add('rep.start and rep.peak must differ');
  final range = (peak - start).abs();
  final tolerance = rep['tolerance'];
  if (!_isNum(tolerance) || (tolerance as num) <= 0) {
    errors.add('rep.tolerance must be a number greater than 0');
  } else if (tolerance >= range / 2) {
    errors.add('rep.tolerance must be smaller than half the range between start and peak');
  }
  final min = rep['min_ms'] ?? 700;
  final max = rep['max_ms'] ?? 15000;
  if (!_isNum(min) || (min as num) < 100) errors.add('rep.min_ms must be a number >= 100');
  if (!_isNum(max) || (min is num && (max as num) <= min)) errors.add('rep.max_ms must be greater than rep.min_ms');
  if (_has(rep, 'min_rom') && !(_isNum(rep['min_rom']) && (rep['min_rom'] as num) > 0 && (rep['min_rom'] as num) <= 1)) errors.add('rep.min_rom must be a number between 0 and 1');
  if (_has(rep, 'settle_ms') && !(_isNum(rep['settle_ms']) && (rep['settle_ms'] as num) >= 0)) errors.add('rep.settle_ms must be a number >= 0');
  if (_has(rep, 'fast_ms') && !(_isNum(rep['fast_ms']) && min is num && (rep['fast_ms'] as num) > min)) errors.add('rep.fast_ms must be greater than rep.min_ms');
  if (_has(rep, 'partial_message')) {
    final m = rep['partial_message'];
    if (!(m is String && m.trim() != '' && m.length <= 110)) errors.add('rep.partial_message must be a short text (110 characters at most)');
  }
}

void _validateComparison(String where, Map c, List<String> errors) {
  final op = c['op'];
  if (!_ops.contains(op)) {
    errors.add('$where: op must be one of ${_ops.join(', ')}');
  } else if (op == 'outside' || op == 'inside') {
    final v = c['value'];
    if (v is! List || v.length != 2 || !v.every(_isNum) || (v[0] as num) >= (v[1] as num)) errors.add('$where: value must be [low, high] for $op');
  } else if (!_isNum(c['value'])) {
    errors.add('$where: value must be a number');
  }
}

void _validateChecks(Map config, Set<String> measureNames, List<String> errors) {
  if (!_has(config, 'checks')) return;
  final checks = config['checks'];
  if (checks is! List) {
    errors.add('checks must be a list');
    return;
  }
  final rep = config['rep'];
  final isHold = rep is Map && rep['hold'] == true;
  final seen = <String>{};
  for (var i = 0; i < checks.length; i++) {
    final raw = checks[i];
    final where = 'check #${i + 1}';
    if (!_isObj(raw)) {
      errors.add('$where must be an object');
      continue;
    }
    final c = raw as Map;
    final id = c['id'];
    if (id is! String || !_nameRe.hasMatch(id)) {
      errors.add('$where: id is invalid');
    } else if (seen.contains(id)) {
      errors.add('duplicate check id "$id"');
    } else {
      seen.add(id);
    }
    final label = id is String ? 'check "$id"' : where;

    final text = c['label'];
    if (text is! String || text.trim() == '') errors.add('$label: label is required');
    final message = c['message'];
    if (message is! String || message.trim() == '') errors.add('$label: message is required');
    if (!measureNames.contains(c['measure'])) errors.add('$label: measure "${_show(c, 'measure')}" does not exist');
    if (!_severities.contains(c['severity'] ?? 'major')) errors.add('$label: severity must be major or minor');
    _validateComparison(label, c, errors);

    final scope = c['scope'] ?? 'frame';
    if (!['frame', 'rep'].contains(scope)) errors.add('$label: scope must be frame or rep');
    if (scope == 'rep') {
      if (!_stats.contains(c['stat'])) errors.add('$label: stat must be one of ${_stats.join(', ')}');
      if (isHold) errors.add('$label: rep-scope checks need a movement rep, not a hold');
    }
    if (_has(c, 'phases')) {
      final p = c['phases'];
      if (p is! List || p.isEmpty || !p.every(_phases.contains)) errors.add('$label: phases must be a list of ${_phases.join(', ')}');
    }
    if (_has(c, 'frames') && !(_isInt(c['frames']) && (c['frames'] as num) >= 1 && (c['frames'] as num) <= 30)) errors.add('$label: frames must be a whole number between 1 and 30');
    if (_has(c, 'segments')) {
      final s = c['segments'];
      if (s is! List) {
        errors.add('$label: segments must be a list');
      } else {
        for (final g in s) {
          if (!isSegmentGroup(g)) errors.add('$label: unknown segment "${_str(g)}"');
        }
      }
    }
  }
}

// ---------------------------------------------------------------------------------------------- compiled form

/// A named number computed on every frame.
class Measure {
  Measure({
    required this.name,
    required this.type,
    required this.combine,
    required this.relative,
    required this.norm,
    this.points = const [],
    this.from,
    this.to,
    this.ref,
    this.axis,
    this.point,
    this.numerator = const [],
    this.denominator = const [],
    this.a,
    this.b,
  });

  final String name;
  final String type;
  final String combine;

  /// delta | ratio | null: the value is reported relative to a baseline taken at the start position.
  final String? relative;
  final String norm;

  final List<String> points;
  final String? from;
  final String? to;
  final String? ref;
  final String? axis;
  final String? point;
  final List<String> numerator;
  final List<String> denominator;
  final String? a;
  final String? b;

  /// Does it depend on which body side faces the camera?
  late final bool sided = _measureUsesSide(this);
}

/// "measure op value", optionally on |measure|. [high] is only set for outside / inside.
class Limit {
  const Limit(this.low, [this.high]);

  final double low;
  final double? high;
}

class Condition {
  const Condition({required this.measure, required this.op, required this.limit});

  final String measure;
  final String op;
  final Limit limit;
}

/// A named form error: what is measured, when it counts as wrong, and what to tell the lifter.
class Check {
  const Check({
    required this.id,
    required this.label,
    required this.message,
    required this.severity,
    this.measure = '',
    this.op = '>',
    this.limit = const Limit(0),
    this.abs = false,
    this.scope = 'frame',
    this.stat,
    this.phases = const [],
    this.segments = const [],
    this.frames = 4,
  });

  final String id;
  final String label;
  final String message;
  final String measure;
  final String op;
  final Limit limit;
  final bool abs;
  final String severity;
  final String scope;
  final String? stat;
  final List<String> phases;
  final List<String> segments;
  final int frames;
}

sealed class RepSpec {
  const RepSpec();
}

class MovementRep extends RepSpec {
  const MovementRep({
    required this.signal,
    required this.start,
    required this.peak,
    required this.tolerance,
    required this.settleMs,
    required this.minMs,
    required this.maxMs,
    required this.minRom,
    required this.fastMs,
    required this.lostMs,
    required this.partialMessage,
  });

  final String signal;
  final double start;
  final double peak;
  final double tolerance;
  final double settleMs;
  final double minMs;
  final double maxMs;
  final double minRom;
  final double? fastMs;
  final double lostMs;
  final String partialMessage;
}

class HoldRep extends RepSpec {
  const HoldRep({required this.inPosition, required this.settleMs, required this.graceMs, required this.minHoldMs});

  final List<Condition> inPosition;
  final double settleMs;
  final double graceMs;
  final double minHoldMs;
}

class CompiledRule {
  const CompiledRule({
    required this.side,
    required this.minVisibility,
    required this.goodScore,
    required this.smoothingMinCutoff,
    required this.smoothingBeta,
    required this.required,
    required this.measures,
    required this.rep,
    required this.checks,
  });

  final String side;
  final double minVisibility;
  final double goodScore;
  final double smoothingMinCutoff;
  final double smoothingBeta;
  final List<String> required;
  final Map<String, Measure> measures;
  final RepSpec rep;
  final List<Check> checks;

  bool get isHold => rep is HoldRep;
}

double _d(Object? v) => (v as num).toDouble();
double _dOr(Object? v, double fallback) => v == null ? fallback : _d(v);
List<String> _strings(Object? v) => [for (final e in v! as List) e as String];

Limit _limit(Map c) {
  final v = c['value'];
  return v is List ? Limit(_d(v[0]), _d(v[1])) : Limit(_d(v));
}

/// Validates and fills in defaults. Throws [RuleConfigError].
CompiledRule compileRule(Object? config) {
  final errors = validateRule(config);
  if (errors.isNotEmpty) throw RuleConfigError(errors);
  final c = config! as Map;
  final repConfig = c['rep'] as Map;
  final hold = repConfig['hold'] == true;

  final measures = <String, Measure>{};
  for (final entry in (c['measures'] as Map).entries) {
    final def = entry.value as Map;
    final type = def['type'] as String;
    final norm = (def['norm'] as String?) ?? (type == 'offset' || type == 'line_offset' ? 'torso' : 'none');
    measures[entry.key as String] = Measure(
      name: entry.key as String,
      type: type,
      combine: (def['combine'] as String?) ?? 'mean',
      relative: def['relative'] as String?,
      norm: norm,
      points: type == 'angle' ? _strings(def['points']) : const [],
      from: def['from'] as String?,
      to: def['to'] as String?,
      ref: def['ref'] as String?,
      axis: def['axis'] as String?,
      point: def['point'] as String?,
      numerator: type == 'ratio' ? _strings(def['num']) : const [],
      denominator: type == 'ratio' ? _strings(def['den']) : const [],
      a: def['a'] as String?,
      b: def['b'] as String?,
    );
  }

  final smoothing = c['smoothing'] as Map?;
  final RepSpec rep = hold
      ? HoldRep(
          inPosition: [
            for (final cond in repConfig['in_position'] as List) Condition(measure: (cond as Map)['measure'] as String, op: cond['op'] as String, limit: _limit(cond)),
          ],
          settleMs: _dOr(repConfig['settle_ms'], 800),
          graceMs: _dOr(repConfig['grace_ms'], 900),
          minHoldMs: _dOr(repConfig['min_hold_ms'], 3000),
        )
      : MovementRep(
          signal: repConfig['signal'] as String,
          start: _d(repConfig['start']),
          peak: _d(repConfig['peak']),
          tolerance: _d(repConfig['tolerance']),
          settleMs: _dOr(repConfig['settle_ms'], 250),
          minMs: _dOr(repConfig['min_ms'], 700),
          maxMs: _dOr(repConfig['max_ms'], 15000),
          minRom: _dOr(repConfig['min_rom'], 0.45),
          fastMs: repConfig['fast_ms'] == null ? null : _d(repConfig['fast_ms']),
          lostMs: _dOr(repConfig['lost_ms'], 1200),
          partialMessage: (repConfig['partial_message'] as String?) ?? 'Go through the full range of motion.',
        );

  final checks = <Check>[
    for (final raw in (c['checks'] as List?) ?? const [])
      Check(
        id: (raw as Map)['id'] as String,
        label: raw['label'] as String,
        message: raw['message'] as String,
        measure: raw['measure'] as String,
        op: raw['op'] as String,
        limit: _limit(raw),
        abs: raw['abs'] == true,
        severity: (raw['severity'] as String?) ?? 'major',
        scope: (raw['scope'] as String?) ?? 'frame',
        stat: raw['stat'] as String?,
        phases: raw['phases'] == null ? (hold ? const ['hold'] : const ['out', 'peak', 'back']) : _strings(raw['phases']),
        segments: raw['segments'] == null ? const [] : _strings(raw['segments']),
        frames: raw['frames'] == null ? 4 : (raw['frames'] as num).toInt(),
      ),
  ];

  return CompiledRule(
    side: (c['side'] as String?) ?? 'auto',
    minVisibility: _dOr(c['min_visibility'], 0.5),
    goodScore: _dOr(c['good_score'], 75),
    smoothingMinCutoff: _dOr(smoothing?['min_cutoff'], 1.0),
    smoothingBeta: _dOr(smoothing?['beta'], 40),
    required: _strings(c['required']),
    measures: measures,
    rep: rep,
    checks: checks,
  );
}

List<String> _pointsOf(Measure m) => switch (m.type) {
      'angle' => m.points,
      'inclination' || 'offset' => [m.from!, m.to!],
      'ratio' => [...m.numerator, ...m.denominator],
      'line_offset' => [m.from!, m.to!, m.point!],
      _ => const [],
    };

bool _measureUsesSide(Measure m) {
  final normSided = const ['torso', 'thigh', 'shank', 'upper_arm', 'forearm'].contains(m.norm) && (m.type == 'offset' || m.type == 'line_offset');
  return normSided || _pointsOf(m).any(isSidedBare);
}

/// Bare sided points a rule needs, expanded to the active side. Used for side selection and readiness.
List<String> requiredPoints(CompiledRule rule, String side) => [
      for (final name in rule.required)
        if (!isSidedBare(name))
          name
        else if (side == 'both') ...['l_$name', 'r_$name'] else '${side == 'left' || side == 'l' ? 'l' : 'r'}_$name',
    ];

// ---------------------------------------------------------------------------------------------- evaluation

/// A frame as the rules see it: landmarks with x already multiplied by the aspect ratio, plus the visibility
/// threshold and which way the person faces (+1 = towards the right of the image).
class FrameView {
  const FrameView({required this.points, required this.minVis, required this.facing, required this.aspect});

  final List<Pt> points;
  final double minVis;
  final double facing;
  final double aspect;
}

bool _inFrame(Pt p, double aspect) => p.x >= -0.05 * aspect && p.x <= 1.05 * aspect && p.y >= -0.05 && p.y <= 1.05;

/// A named point for one side; null when it is hidden or off screen (then it is added to [missing]).
Pt? getPoint(FrameView view, String name, String side, [Set<String>? missing]) {
  final idx = resolvePoint(name, side);
  if (idx == null) return null;
  final pts = [
    for (final i in idx)
      if (view.points[i].v >= view.minVis && _inFrame(view.points[i], view.aspect)) view.points[i],
  ];
  if (pts.isEmpty) {
    missing?.add(name);
    return null;
  }
  if (pts.length == 1) return pts[0];
  return Pt((pts[0].x + pts[1].x) / 2, (pts[0].y + pts[1].y) / 2, pts[0].v < pts[1].v ? pts[0].v : pts[1].v);
}

double? normLength(String norm, FrameView view, String side, [Set<String>? missing]) {
  double? between(String a, String b) {
    final p = getPoint(view, a, side, missing);
    final q = getPoint(view, b, side, missing);
    return p != null && q != null ? dist(p, q) : null;
  }

  return switch (norm) {
    'none' => 1,
    'torso' => between('shoulder', 'hip'),
    'shoulder_width' => between('l_shoulder', 'r_shoulder'),
    'thigh' => between('hip', 'knee'),
    'shank' => between('knee', 'ankle'),
    'upper_arm' => between('shoulder', 'elbow'),
    'forearm' => between('elbow', 'wrist'),
    _ => null,
  };
}

double? _evaluateOne(Measure def, FrameView view, String side, Set<String> missing) {
  Pt? get(String name) => getPoint(view, name, side, missing);

  switch (def.type) {
    case 'angle':
      final a = get(def.points[0]);
      final b = get(def.points[1]);
      final c = get(def.points[2]);
      return a != null && b != null && c != null ? angleAt(a, b, c) : null;
    case 'inclination':
      final a = get(def.from!);
      final b = get(def.to!);
      return a != null && b != null ? inclination(a, b, def.ref!) : null;
    case 'offset':
      final a = get(def.from!);
      final b = get(def.to!);
      final n = normLength(def.norm, view, side, missing);
      if (a == null || b == null || n == null || n == 0) return null;
      final dx = b.x - a.x;
      final dy = b.y - a.y;
      final component = def.axis == 'x'
          ? dx
          : def.axis == 'forward'
              ? dx * view.facing
              : -dy;
      return component / n;
    case 'ratio':
      final a = get(def.numerator[0]);
      final b = get(def.numerator[1]);
      final c = get(def.denominator[0]);
      final d = get(def.denominator[1]);
      if (a == null || b == null || c == null || d == null) return null;
      final den = dist(c, d);
      return den < 1e-6 ? null : dist(a, b) / den;
    case 'line_offset':
      final a = get(def.from!);
      final b = get(def.to!);
      final p = get(def.point!);
      final n = normLength(def.norm, view, side, missing);
      if (a == null || b == null || p == null || n == null || n == 0) return null;
      final off = lineOffsetUp(a, b, p);
      return off == null ? null : off / n;
  }
  return null;
}

class MeasureValues {
  const MeasureValues(this.values, this.missing);

  final Map<String, double?> values;
  final Set<String> missing;
}

/// Every measure for a frame. [side] ('l' | 'r') is the active side, used for bare names unless the rule's side is 'both'.
MeasureValues evaluateMeasures(CompiledRule rule, FrameView view, String side) {
  final missing = <String>{};
  final values = <String, double?>{};
  final both = rule.side == 'both';

  for (final entry in rule.measures.entries) {
    final def = entry.value;
    if (def.type == 'diff') continue;
    if (both && def.sided) {
      final l = _evaluateOne(def, view, 'l', missing);
      final r = _evaluateOne(def, view, 'r', missing);
      final present = [?l, ?r];
      if (present.isEmpty) {
        values[entry.key] = null;
      } else if (def.combine == 'min') {
        values[entry.key] = present.reduce((x, y) => x < y ? x : y);
      } else if (def.combine == 'max') {
        values[entry.key] = present.reduce((x, y) => x > y ? x : y);
      } else {
        values[entry.key] = present.fold<double>(0, (s, v) => s + v) / present.length;
      }
    } else {
      values[entry.key] = _evaluateOne(def, view, side, missing);
    }
  }

  for (final entry in rule.measures.entries) {
    final def = entry.value;
    if (def.type != 'diff') continue;
    final a = values[def.a];
    final b = values[def.b];
    values[entry.key] = a == null || b == null ? null : (a - b).abs();
  }
  return MeasureValues(values, missing);
}

/// Does [value] break the limit? Null never does: we cannot judge what we cannot see.
bool violates(double? value, String op, Limit limit, {bool abs = false}) {
  if (value == null || value.isNaN) return false;
  final v = abs ? value.abs() : value;
  return switch (op) {
    '<' => v < limit.low,
    '>' => v > limit.low,
    'outside' => v < limit.low || v > limit.high!,
    'inside' => v >= limit.low && v <= limit.high!,
    _ => false,
  };
}

/// How well each body side is seen for the rule's required points, to pick the side facing the camera.
({double l, double r}) sideVisibility(CompiledRule rule, List<Pt> points) {
  double score(String side) {
    final names = rule.required.where(isSidedBare).toList();
    if (names.isEmpty) return 0;
    var sum = 0.0;
    for (final n in names) {
      sum += points[resolvePoint(n, side)![0]].v;
    }
    return sum / names.length;
  }

  return (l: score('l'), r: score('r'));
}
