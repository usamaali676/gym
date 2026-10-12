// The Dart posture engine must give the same answers as the web's (resources/js/modules). The fixture was produced by
// running the JavaScript engine over deterministic synthetic poses (scripts/export-posture-parity.mjs); this replays
// the same landmarks through the port and compares every frame. It proves the port matches the JavaScript. It does not
// prove either engine works on a real camera.
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/features/posture/domain/geometry.dart';
import 'package:ironpulse/features/posture/domain/posture_analyzer.dart';
import 'package:ironpulse/features/posture/domain/rule_engine.dart';

import 'posture_json.dart';

const _ruleDir = '../database/seeders/data/posture-rules';
const _tolerance = 1e-6;

Map<String, dynamic> _fixture() {
  final bytes = gzip.decode(File('test/fixtures/posture_parity.json.gz').readAsBytesSync());
  return jsonDecode(utf8.decode(bytes)) as Map<String, dynamic>;
}

Object? _rule(String slug) => (jsonDecode(File('$_ruleDir/$slug.json').readAsStringSync()) as Map<String, dynamic>)['config'];

/// Deep comparison where numbers may differ by [_tolerance] (the two runtimes' maths libraries can differ in the last bit).
void _expectClose(Object? actual, Object? expected, String path) {
  if (expected is num && actual is num) {
    expect(actual.toDouble(), closeTo(expected.toDouble(), _tolerance), reason: path);
  } else if (expected is Map) {
    expect(actual, isA<Map>(), reason: path);
    final a = actual! as Map;
    expect(a.keys.toSet(), expected.keys.toSet(), reason: '$path keys');
    for (final k in expected.keys) {
      _expectClose(a[k], expected[k], '$path.$k');
    }
  } else if (expected is List) {
    expect(actual, isA<List>(), reason: path);
    final a = actual! as List;
    expect(a.length, expected.length, reason: '$path length');
    for (var i = 0; i < expected.length; i++) {
      _expectClose(a[i], expected[i], '$path[$i]');
    }
  } else {
    expect(actual, expected, reason: path);
  }
}

List<Landmark>? _frame(List<dynamic>? row) {
  if (row == null) return null;
  return [for (var k = 0; k < landmarkCount; k++) Landmark((row[k * 3] as int) / 1e4, (row[k * 3 + 1] as int) / 1e4, visibility: (row[k * 3 + 2] as int) / 100)];
}

void main() {
  final fixture = _fixture();

  group('rules', () {
    test('the fixture knows every shipped rule file', () {
      final files = Directory(_ruleDir).listSync().whereType<File>().map((f) => f.uri.pathSegments.last).where((n) => n.endsWith('.json')).map((n) => n.substring(0, n.length - 5)).toList()..sort();
      expect(fixture['rules'], files, reason: 'a rule was added or removed: run node scripts/export-posture-parity.mjs');
    });

    for (final slug in (fixture['rules'] as List).cast<String>()) {
      test('$slug validates and compiles', () {
        final config = _rule(slug);
        expect(validateRule(config), isEmpty);
        final compiled = compileRule(config);
        expect(compiled.measures, isNotEmpty);
        expect(compiled.checks.every((c) => c.phases.isNotEmpty), isTrue);
      });
    }
  });

  group('validation messages match the JavaScript and the PHP validator', () {
    for (final c in (fixture['validation'] as List).cast<Map<String, dynamic>>()) {
      test(c['name'] as String, () {
        expect(validateRule(c['config']), (c['errors'] as List).cast<String>());
      });
    }

    test('compileRule refuses an invalid config and says why', () {
      expect(() => compileRule({'schema': 2}), throwsA(isA<RuleConfigError>().having((e) => e.errors, 'errors', contains('schema must be 1'))));
    });
  });

  group('the engine answers like the web engine, frame by frame', () {
    for (final s in (fixture['scenarios'] as List).cast<Map<String, dynamic>>()) {
      final head = s['head'] as Map<String, dynamic>;
      test(head['id'] as String, () {
        final analyzer = PostureAnalyzer(compileRule(_rule(head['rule'] as String)), aspect: (head['aspect'] as num).toDouble());
        final times = (head['times'] as List).cast<num>();
        final frames = s['frames'] as List;
        final expected = (s['expected'] as List).cast<Map<String, dynamic>>();
        expect(frames.length, expected.length);

        for (var i = 0; i < frames.length; i++) {
          final r = analyzer.update(_frame(frames[i] as List?), times[i].toDouble(), aspect: (head['aspect'] as num).toDouble());
          final e = expected[i];
          final at = '${head['id']} frame $i (t=${times[i]})';

          expect(r.status, e['status'], reason: '$at status');
          expect(r.phase, e['phase'], reason: '$at phase');
          expect(r.reps, e['reps'], reason: '$at reps');
          expect(r.good, e['good'], reason: '$at good');
          expect(r.bad, e['bad'], reason: '$at bad');
          expect(r.side, e['side'], reason: '$at side');
          expect(r.facing, e['facing'], reason: '$at facing');
          expect(r.score, e['score'], reason: '$at score');
          expect(r.missing, (e['missing'] as List).cast<String>(), reason: '$at missing');
          expect(r.hidden.toList()..sort(), (e['hidden'] as List).cast<String>(), reason: '$at hidden');
          expect([for (final c in r.active) c.id], (e['active'] as List).cast<String>(), reason: '$at active');
          expect(r.redBones.toList()..sort(), (e['red'] as List).cast<String>(), reason: '$at red bones');
          _expectClose(r.progress, e['progress'], '$at progress');
          _expectClose(r.signal, e['signal'], '$at signal');
          _expectClose(r.holdMs, e['holdMs'], '$at holdMs');
          _expectClose(r.measures, e['measures'], '$at measures');
          _expectClose([for (final ev in r.events) eventJson(ev)], e['events'], '$at events');
          _expectClose(r.lastRep == null ? null : repFeedbackJson(r.lastRep!), e['lastRep'], '$at lastRep');
        }

        final flushed = analyzer.flush(times.last.toDouble());
        _expectClose([for (final ev in flushed) eventJson(ev)], head['flushEvents'], '${head['id']} flush events');
        _expectClose(analyzer.summary((head['durationSeconds'] as num).toDouble()).toJson(), head['summary'], '${head['id']} summary');
      });
    }
  });
}
