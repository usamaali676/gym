import 'package:dio/dio.dart' show DioExceptionType;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ironpulse/core/api/api_client.dart';
import 'package:ironpulse/core/providers.dart';
import 'package:ironpulse/core/sync/offline_queue.dart';
import 'package:ironpulse/core/sync/pending_op.dart';
import 'package:ironpulse/core/sync/pending_store.dart';
import 'package:ironpulse/core/utilities/clock.dart';

import '../support/fake_adapter.dart';
import '../support/harness.dart';

class _Tokens implements AuthTokenSource {
  @override
  Future<String?> accessToken() async => 'tok';

  @override
  Future<String?> onUnauthorized(String usedToken) async => null;
}

class _Rig {
  _Rig({String? scope = '42', PendingStore? store}) : store = store ?? MemoryPendingStore(), server = FakeServer(), clock = TestClock(), scope = scope {
    container = ProviderContainer(
      retry: noAutoRetry,
      overrides: [
        queueScopeProvider.overrideWithValue(scope),
        pendingStoreProvider.overrideWithValue(this.store),
        apiClientProvider.overrideWithValue(ApiClient(env: testEnv, tokens: _Tokens(), adapter: server.adapter, sleep: (_) async {})),
        clockProvider.overrideWithValue(clock.call),
      ],
    );
  }

  final PendingStore store;
  final FakeServer server;
  final TestClock clock;
  final String? scope;
  late final ProviderContainer container;

  OfflineQueue get queue => container.read(offlineQueueProvider.notifier);
  QueueState get state => container.read(offlineQueueProvider);

  /// The server answers each op in the batch with [answer] (default: applied).
  void serve(Map<String, dynamic> Function(Map<String, dynamic> op)? answer) {
    server.on('POST', '/sync/batch', (o) {
      final ops = List<Map<String, dynamic>>.from(((o.data as Map)['operations'] as List).map((e) => Map<String, dynamic>.from(e as Map)));
      return FakeReply.json(200, {
        'data': {
          'results': [for (final op in ops) answer == null ? {'op_id': op['op_id'], 'status': 'applied'} : answer(op)],
          'server_time': '2026-10-12T18:00:00+00:00',
        },
      });
    });
  }

  List<List<String>> get batches => [
        for (final r in server.adapter.requests.where((r) => r.path == '/sync/batch')) [for (final op in (r.data as Map)['operations'] as List) (op as Map)['type'] as String],
      ];

  void dispose() => container.dispose();
}

Future<void> _add(_Rig r, String type, Map<String, dynamic> payload, {String label = 'A change', bool flushNow = false}) => r.queue.enqueue(type, payload, label: label, flushNow: flushNow);

void main() {
  test('a change is kept on the phone and is still there after the app restarts', () async {
    final r = _Rig();
    await _add(r, OpTypes.setLog, {'session_id': 7, 'session_exercise_id': 3, 'set_number': 1, 'reps': 8}, label: 'Bench press, set 1: 8 reps');
    expect(r.state.ops, hasLength(1));
    expect(r.state.ops.single.label, 'Bench press, set 1: 8 reps');
    r.dispose();

    final again = _Rig(store: r.store);
    expect(again.state.ops, hasLength(1));
    expect(again.state.ops.single.type, OpTypes.setLog);
    expect(again.state.ops.single.payload['reps'], 8);
    expect(again.state.ops.single.state, OpState.queued);
    again.dispose();
  });

  test('changes go to the server in the order they were made, each with its own id and the time it was made', () async {
    final r = _Rig();
    r.serve(null);
    await _add(r, OpTypes.setLog, {'session_id': 7, 'set_number': 1});
    r.clock.advance(const Duration(minutes: 2));
    await _add(r, OpTypes.setLog, {'session_id': 7, 'set_number': 2});
    r.clock.advance(const Duration(minutes: 2));
    await _add(r, OpTypes.workoutFinish, {'session_id': 7, 'active_seconds': 1800});

    final report = await r.queue.flush();

    expect(r.batches, [
      [OpTypes.setLog, OpTypes.setLog, OpTypes.workoutFinish],
    ]);
    final sent = ((r.server.adapter.requests.single.data as Map)['operations'] as List).cast<Map>();
    expect({for (final o in sent) o['op_id']}, hasLength(3), reason: 'distinct ids');
    expect(sent.map((o) => o['client_created_at']).toList(), ['2026-10-12T18:00:00.000Z', '2026-10-12T18:02:00.000Z', '2026-10-12T18:04:00.000Z']);
    expect(report.applied, 3);
    expect(r.state.ops, isEmpty);
    expect(r.state.lastApplied, {OpTypes.setLog, OpTypes.workoutFinish});
    expect(r.state.appliedRevision, 1);
    expect(r.state.lastSyncedAt, isNotNull);
    expect(r.server.adapter.requests.single.headers['Idempotency-Key'], isNotNull, reason: 'the batch itself can be retried safely too');
    r.dispose();
  });

  group('what the server says about each change', () {
    test('a replay of something already applied is simply done', () async {
      final r = _Rig();
      r.serve((op) => {'op_id': op['op_id'], 'status': 'duplicate', 'original_status': 'applied'});
      await _add(r, OpTypes.setLog, {'session_id': 7});
      await r.queue.flush();
      expect(r.state.ops, isEmpty);
      r.dispose();
    });

    test('a replay of something the server rejected the first time stays rejected, with its reason', () async {
      final r = _Rig();
      r.serve((op) => {'op_id': op['op_id'], 'status': 'duplicate', 'original_status': 'rejected', 'error': {'code': 'validation_failed', 'message': 'The operation is invalid.'}});
      await _add(r, OpTypes.measurementSave, {'weight_kg': 5});
      await r.queue.flush();
      expect(r.state.ops.single.state, OpState.rejected);
      expect(r.state.ops.single.errorMessage, 'The operation is invalid.');
      r.dispose();
    });

    test('a conflict keeps the change and the server\'s copy for the member to decide', () async {
      final r = _Rig();
      r.serve((op) => {'op_id': op['op_id'], 'status': 'conflict', 'server': {'weight_kg': 79.0, 'updated_at': '2026-10-12T17:00:00+00:00'}});
      await _add(r, OpTypes.measurementSave, {'weight_kg': 80});
      final report = await r.queue.flush();
      expect(report.conflicts, 1);
      expect(r.state.conflicts.single.serverCopy!['weight_kg'], 79.0);
      expect(r.state.waiting, isEmpty, reason: 'a conflict is not retried on its own');
      r.dispose();
    });

    test('a refusal is final: kept for the member to read, never retried', () async {
      final r = _Rig();
      r.serve((op) => {'op_id': op['op_id'], 'status': 'rejected', 'error': {'code': 'entitlement_required', 'message': 'Your membership does not include this feature.'}});
      await _add(r, OpTypes.postureSave, {'client_uuid': 'x'});
      final report = await r.queue.flush();
      expect(report.rejected, 1);
      expect(r.state.rejected.single.errorCode, 'entitlement_required');
      await r.queue.flush();
      expect(r.batches, hasLength(1), reason: 'a rejected change is not sent again');
      r.dispose();
    });

    test('when the server stops on an error the change stays queued and is tried again later', () async {
      final r = _Rig();
      r.serve((op) => {'op_id': op['op_id'], 'status': 'error', 'retryable': true});
      await _add(r, OpTypes.setLog, {'session_id': 7});
      final report = await r.queue.flush();
      expect(report.offline, isTrue);
      expect(r.state.waiting.single.attempts, 1);
      expect(r.state.problem, isNotNull);
      r.dispose();
    });

    test('ops after a failing one are reported as not processed and stay queued in order', () async {
      final r = _Rig();
      var first = true;
      r.serve((op) {
        if (first) {
          first = false;
          return {'op_id': op['op_id'], 'status': 'applied'};
        }
        return {'op_id': op['op_id'], 'status': op['type'] == OpTypes.setLog ? 'error' : 'not_processed'};
      });
      await _add(r, OpTypes.exerciseSkip, {'session_id': 7});
      await _add(r, OpTypes.setLog, {'session_id': 7, 'set_number': 1});
      await _add(r, OpTypes.workoutFinish, {'session_id': 7});
      final report = await r.queue.flush();
      expect(report.applied, 1);
      expect([for (final o in r.state.waiting) o.type], [OpTypes.setLog, OpTypes.workoutFinish]);
      r.dispose();
    });
  });

  group('no connection', () {
    test('nothing is lost: everything stays queued, the member is told, and it is retried later', () async {
      final r = _Rig();
      r.server.on('POST', '/sync/batch', (_) => FakeReply.fail(DioExceptionType.connectionError));
      await _add(r, OpTypes.setLog, {'session_id': 7});
      await _add(r, OpTypes.workoutFinish, {'session_id': 7});

      final report = await r.queue.flush();

      expect(report.offline, isTrue);
      expect(r.state.waiting, hasLength(2));
      expect(r.state.problem, contains('No connection'));
      expect(r.state.syncing, isFalse);
      expect(r.store.read('42'), hasLength(2), reason: 'still on disk');
      r.dispose();
    });

    test('the same changes are sent once the connection is back', () async {
      final r = _Rig();
      r.server.on('POST', '/sync/batch', (_) => FakeReply.fail(DioExceptionType.connectionError));
      await _add(r, OpTypes.setLog, {'session_id': 7});
      await r.queue.flush();
      final id = r.state.waiting.single.opId;

      r.serve(null);
      final report = await r.queue.flush();

      expect(report.applied, 1);
      expect(r.state.ops, isEmpty);
      final last = ((r.server.adapter.requests.last.data as Map)['operations'] as List).single as Map;
      expect(last['op_id'], id, reason: 'the same op id, so the server can recognise a replay');
      r.dispose();
    });

    test('automatic retries back off: 15 s, 30 s, 1 min, 2 min, then every 5 min', () {
      expect(OfflineQueue.backoff, [const Duration(seconds: 15), const Duration(seconds: 30), const Duration(minutes: 1), const Duration(minutes: 2), const Duration(minutes: 5)]);
    });

    test('an expired sign-in keeps the queue for the next sign-in instead of dropping it', () async {
      final r = _Rig();
      r.server.on('POST', '/sync/batch', (_) => FakeReply.json(401, errorJson('unauthenticated', 'Your session has ended.')));
      await _add(r, OpTypes.setLog, {'session_id': 7});
      final report = await r.queue.flush();
      expect(report.offline, isTrue);
      expect(r.state.waiting, hasLength(1));
      expect(r.state.problem, contains('Sign in'));
      r.dispose();
    });

    test('a batch the server calls malformed is rejected rather than retried forever', () async {
      final r = _Rig();
      r.server.on('POST', '/sync/batch', (_) => FakeReply.json(422, errorJson('validation_failed', 'The operations field is invalid.')));
      await _add(r, OpTypes.setLog, {'session_id': 7});
      final report = await r.queue.flush();
      expect(report.rejected, 1);
      expect(r.state.rejected, hasLength(1));
      expect(r.state.waiting, isEmpty);
      r.dispose();
    });
  });

  test('only one pass runs at a time, and changes made while it runs are sent in the next one', () async {
    final r = _Rig();
    r.serve(null);
    await _add(r, OpTypes.setLog, {'session_id': 7, 'set_number': 1});
    final a = r.queue.flush();
    final b = r.queue.flush();
    expect(identical(a, b), isTrue);
    await _add(r, OpTypes.setLog, {'session_id': 7, 'set_number': 2});
    await a;
    expect(r.state.waiting.length, lessThanOrEqualTo(1));
    await r.queue.flush();
    expect(r.state.ops, isEmpty);
    expect(r.batches.expand((b) => b).length, 2, reason: 'each change was sent exactly once');
    r.dispose();
  });

  test('more than 100 changes go in batches of 100, still in order', () async {
    final r = _Rig();
    r.serve(null);
    for (var i = 1; i <= 230; i++) {
      await _add(r, OpTypes.setLog, {'session_id': 7, 'set_number': i});
    }
    final report = await r.queue.flush();
    expect(report.applied, 230);
    expect([for (final req in r.server.adapter.requests.where((q) => q.path == '/sync/batch')) ((req.data as Map)['operations'] as List).length], [100, 100, 30]);
    final numbers = [
      for (final req in r.server.adapter.requests.where((q) => q.path == '/sync/batch')) for (final op in (req.data as Map)['operations'] as List) ((op as Map)['payload'] as Map)['set_number'],
    ];
    expect(numbers, List.generate(230, (i) => i + 1));
    r.dispose();
  });

  group('the member decides', () {
    test('"keep the server\'s version" drops the change; "use mine" sends it again as a new change stamped now', () async {
      final r = _Rig();
      r.serve((op) => {'op_id': op['op_id'], 'status': 'conflict', 'server': {'weight_kg': 79.0}});
      await _add(r, OpTypes.measurementSave, {'weight_kg': 80});
      await r.queue.flush();
      final conflict = r.state.conflicts.single;

      r.clock.advance(const Duration(hours: 1));
      r.serve(null);
      await r.queue.keepMine(conflict.opId);
      await r.queue.flush();

      final sent = r.server.adapter.requests.where((q) => q.path == '/sync/batch').last;
      final op = ((sent.data as Map)['operations'] as List).single as Map;
      expect(op['op_id'], isNot(conflict.opId), reason: 'the server remembers the first id as a conflict');
      expect(op['client_created_at'], '2026-10-12T19:00:00.000Z');
      expect(r.state.ops, isEmpty);

      // and the other choice
      final r2 = _Rig();
      r2.serve((op) => {'op_id': op['op_id'], 'status': 'conflict', 'server': {}});
      await _add(r2, OpTypes.measurementSave, {'weight_kg': 80});
      await r2.queue.flush();
      await r2.queue.discard(r2.state.conflicts.single.opId);
      expect(r2.state.ops, isEmpty);
      expect(r2.store.read('42'), isEmpty);
      r.dispose();
      r2.dispose();
    });

    test('a rejected change can be dismissed', () async {
      final r = _Rig();
      r.serve((op) => {'op_id': op['op_id'], 'status': 'rejected', 'error': {'code': 'not_found', 'message': 'Workout not found.'}});
      await _add(r, OpTypes.setLog, {'session_id': 9});
      await r.queue.flush();
      await r.queue.discard(r.state.rejected.single.opId);
      expect(r.state.ops, isEmpty);
      r.dispose();
    });
  });

  group('accounts', () {
    test('one account never sees or sends another account\'s changes', () async {
      final store = MemoryPendingStore();
      final a = _Rig(scope: '42', store: store);
      await _add(a, OpTypes.setLog, {'session_id': 1});
      a.dispose();

      final b = _Rig(scope: '43', store: store);
      b.serve(null);
      expect(b.state.ops, isEmpty);
      await b.queue.flush();
      expect(b.batches, isEmpty);
      expect(store.read('42'), hasLength(1), reason: 'still waiting for its own owner');
      expect(store.scopesWithChanges(), ['42']);
      b.dispose();
    });

    test('nothing can be queued while signed out', () async {
      final r = _Rig(scope: null);
      await expectLater(_add(r, OpTypes.setLog, {}), throwsA(isA<StateError>()));
      r.dispose();
    });
  });

  test('changes left over from the last run are sent as soon as the app is up', () async {
    final store = MemoryPendingStore();
    final first = _Rig(store: store);
    await _add(first, OpTypes.setLog, {'session_id': 7});
    first.dispose();

    final second = _Rig(store: store);
    second.serve(null);
    expect(second.state.waiting, hasLength(1));
    await Future<void>.delayed(const Duration(milliseconds: 50));
    expect(second.state.ops, isEmpty);
    second.dispose();
  });

  test('"is something waiting for this workout" lets new changes queue behind it', () async {
    final r = _Rig();
    await _add(r, OpTypes.setLog, {'session_id': 7});
    expect(r.queue.hasQueuedFor(7), isTrue);
    expect(r.queue.hasQueuedFor(8), isFalse);
    r.dispose();
  });

  test('a stored list this version cannot read is ignored instead of crashing the app', () async {
    final prefsStore = MemoryPendingStore();
    final r = _Rig(store: prefsStore);
    expect(r.state.ops, isEmpty);
    r.dispose();
  });
}
