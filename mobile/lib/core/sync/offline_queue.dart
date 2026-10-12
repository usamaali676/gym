// The offline queue: changes made without a connection are kept on the phone and replayed, in order, through
// `POST /sync/batch`.
//
// What makes this safe:
//   * every change has a client-generated op id; the server applies an op id once, however often it is sent, so a lost
//     response or a retry cannot double-log a set
//   * the server re-checks the member's plan for every op (a queued change is never a way around a paywall) and runs the
//     same validation as the online endpoints
//   * a change whose record was edited more recently somewhere else comes back as a conflict, shown to the member; it is
//     never silently overwritten either way
//   * the queue is stored per account and survives app kills; sign-out does not delete it
//
// The server's batch contract is documented in docs/mobile/openapi.yaml (POST /sync/batch).

import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';

import '../../features/authentication/presentation/auth_controller.dart';
import '../api/api_error.dart';
import '../providers.dart';
import '../utilities/clock.dart';
import 'pending_op.dart';
import 'pending_store.dart';

final pendingStoreProvider = Provider<PendingStore>((ref) => PrefsPendingStore(ref.watch(sharedPreferencesProvider)));

/// The account the queue belongs to; `null` while nobody is signed in.
final queueScopeProvider = Provider<String?>((ref) => ref.watch(authControllerProvider.select((a) => a is SignedIn ? '${a.user.id}' : null)));

class QueueState {
  const QueueState({this.ops = const [], this.syncing = false, this.lastSyncedAt, this.problem, this.appliedRevision = 0, this.lastApplied = const {}});

  final List<PendingOp> ops;
  final bool syncing;
  final DateTime? lastSyncedAt;

  /// Why the last attempt did not finish ("No connection", "The server is busy"), shown on the sync screen.
  final String? problem;

  /// Increases every time the server accepted at least one change; [lastApplied] says which kinds.
  final int appliedRevision;
  final Set<String> lastApplied;

  List<PendingOp> get waiting => [for (final o in ops) if (o.state == OpState.queued) o];
  List<PendingOp> get conflicts => [for (final o in ops) if (o.state == OpState.conflict) o];
  List<PendingOp> get rejected => [for (final o in ops) if (o.state == OpState.rejected) o];
  int get needAttention => conflicts.length + rejected.length;
  bool get isEmpty => ops.isEmpty;

  /// Queued or sent ops that belong to one workout.
  List<PendingOp> forSession(int sessionId, {String? type}) => [for (final o in ops) if (o.sessionId == sessionId && (type == null || o.type == type)) o];

  QueueState copyWith({List<PendingOp>? ops, bool? syncing, DateTime? lastSyncedAt, String? problem, bool clearProblem = false, int? appliedRevision, Set<String>? lastApplied}) => QueueState(
        ops: ops ?? this.ops,
        syncing: syncing ?? this.syncing,
        lastSyncedAt: lastSyncedAt ?? this.lastSyncedAt,
        problem: clearProblem ? null : (problem ?? this.problem),
        appliedRevision: appliedRevision ?? this.appliedRevision,
        lastApplied: lastApplied ?? this.lastApplied,
      );
}

/// What one pass over the queue achieved.
class FlushReport {
  const FlushReport({this.applied = 0, this.conflicts = 0, this.rejected = 0, this.remaining = 0, this.offline = false});

  final int applied;
  final int conflicts;
  final int rejected;
  final int remaining;

  /// The server could not be reached (or was busy): everything stays queued and is tried again later.
  final bool offline;
}

class OfflineQueue extends Notifier<QueueState> {
  static const _uuid = Uuid();
  static const maxBatch = 100;

  /// Waits between automatic retries after a failed attempt; the last value repeats.
  static const backoff = [Duration(seconds: 15), Duration(seconds: 30), Duration(minutes: 1), Duration(minutes: 2), Duration(minutes: 5)];

  Timer? _retry;
  int _failures = 0;
  Future<FlushReport>? _inFlight;

  String? get _scope => ref.read(queueScopeProvider);
  PendingStore get _store => ref.read(pendingStoreProvider);
  DateTime _now() => ref.read(clockProvider)();

  @override
  QueueState build() {
    final scope = ref.watch(queueScopeProvider);
    ref.onDispose(() => _retry?.cancel());
    final ops = scope == null ? const <PendingOp>[] : _store.read(scope);
    // Changes left over from the last run are sent as soon as the app is up.
    if (ops.any((o) => o.state == OpState.queued)) Future<void>.microtask(() => ref.mounted ? flush() : null);
    return QueueState(ops: ops);
  }

  // ------------------------------------------------------------------------------------------------ adding

  /// Keep a change on the phone and try to send it. Returns once it is stored (not once it is sent).
  Future<PendingOp> enqueue(String type, Map<String, dynamic> payload, {required String label, bool flushNow = true}) async {
    final scope = _scope;
    if (scope == null) throw StateError('Nothing can be queued while signed out.');
    final op = PendingOp(opId: _uuid.v4(), type: type, payload: payload, createdAt: _now().toUtc(), label: label);
    final ops = [...state.ops, op];
    await _store.write(scope, ops);
    state = state.copyWith(ops: ops);
    if (flushNow) unawaited(flush());
    return op;
  }

  /// Is something waiting for this workout? New changes to that workout must queue behind it so the server sees them in order.
  bool hasQueuedFor(int sessionId) => state.ops.any((o) => o.sessionId == sessionId && o.state == OpState.queued);

  // ------------------------------------------------------------------------------------------------ sending

  /// Sends everything that is waiting. Only one pass runs at a time; callers during a pass get that pass's result.
  ///
  /// [automatic] marks the timer's own retries: only those make the wait before the next retry longer. A new change, the
  /// app coming to the foreground or the member pressing Sync now each try once without slowing the schedule down, so a
  /// phone that regains signal is not left waiting minutes because several sets were logged while it was offline.
  Future<FlushReport> flush({bool automatic = false}) => _inFlight ??= _flush(automatic: automatic).whenComplete(() => _inFlight = null);

  Future<FlushReport> _flush({required bool automatic}) async {
    final scope = _scope;
    if (scope == null) return const FlushReport();
    _retry?.cancel();

    var applied = 0;
    var conflicts = 0;
    var rejected = 0;
    var stopped = false; // the server could not be reached or stopped part-way: the rest waits for the next attempt
    final appliedTypes = <String>{};
    state = state.copyWith(syncing: true, clearProblem: true);

    try {
      // A pass may need several batches (the server takes 100 at a time).
      for (var round = 0; round < 50 && !stopped; round++) {
        final batch = state.waiting.take(maxBatch).toList();
        if (batch.isEmpty) break;
        var decided = 0; // ops the server settled this round (applied, conflict or rejected)

        final Map<String, Map<String, dynamic>> results;
        try {
          final response = await ref.read(apiClientProvider).post('/sync/batch', idempotent: true, body: {'operations': [for (final o in batch) o.toRequest()]});
          if (!ref.mounted) return const FlushReport();
          results = {
            for (final r in (response.map['results'] as List? ?? const [])) (r as Map)['op_id'] as String: Map<String, dynamic>.from(r),
          };
        } on ApiError catch (e) {
          if (!ref.mounted) return const FlushReport();
          if (e.isOffline || e.kind == ApiErrorKind.server || e.kind == ApiErrorKind.rateLimited || e.kind == ApiErrorKind.maintenance || e.kind == ApiErrorKind.upgradeRequired) {
            await _afterFailure(scope, batch, e, escalate: automatic);
            stopped = true;
          } else if (e.kind == ApiErrorKind.unauthenticated) {
            // The session ended; the queue waits for the next sign-in (the app is already on its way to the sign-in screen).
            state = state.copyWith(problem: 'Sign in to send your changes.');
            stopped = true;
          } else {
            // The whole batch was refused as malformed: a bug in this app, not something to retry forever.
            await _markAll(scope, batch, OpState.rejected, e.code, e.message);
            rejected += batch.length;
          }
          continue;
        }

        final sent = {for (final o in batch) o.opId};
        final next = <PendingOp>[];
        for (final op in state.ops) {
          final result = sent.contains(op.opId) ? results[op.opId] : null;
          if (result == null) {
            next.add(op);
            continue;
          }
          final outcome = _outcomeOf(op, result);
          switch (outcome.kind) {
            case _Kind.applied:
              applied += 1;
              decided += 1;
              appliedTypes.add(op.type);
            case _Kind.conflict:
              conflicts += 1;
              decided += 1;
              next.add(outcome.op!);
            case _Kind.rejected:
              rejected += 1;
              decided += 1;
              next.add(outcome.op!);
            case _Kind.retry:
              next.add(outcome.op!);
          }
        }
        await _store.write(scope, next);
        if (!ref.mounted) return const FlushReport();
        state = state.copyWith(ops: next);

        // Nothing was settled this round (the server stopped the batch on an error): leave the rest for a later attempt.
        // The attempts were already counted above.
        if (decided == 0) {
          await _afterFailure(scope, const [], null, escalate: automatic);
          stopped = true;
        }
      }
    } finally {
      if (ref.mounted) state = state.copyWith(syncing: false);
    }

    if (!ref.mounted) return const FlushReport();
    if (!stopped) _failures = 0;
    state = state.copyWith(
      lastSyncedAt: stopped ? null : _now(),
      clearProblem: !stopped,
      appliedRevision: applied > 0 ? state.appliedRevision + 1 : state.appliedRevision,
      lastApplied: applied > 0 ? appliedTypes : state.lastApplied,
    );
    return FlushReport(applied: applied, conflicts: conflicts, rejected: rejected, remaining: state.waiting.length, offline: stopped);
  }

  _Outcome _outcomeOf(PendingOp op, Map<String, dynamic> r) {
    final status = (r['status'] ?? '').toString();
    final original = (r['original_status'] ?? '').toString();
    final error = r['error'] is Map ? Map<String, dynamic>.from(r['error'] as Map) : null;

    // A replay of an op the server already decided answers `duplicate` with what it decided the first time.
    final effective = status == 'duplicate' ? original : status;
    switch (effective) {
      case 'applied':
        return const _Outcome(_Kind.applied);
      case 'conflict':
        return _Outcome(_Kind.conflict, op.copyWith(state: OpState.conflict, serverCopy: r['server'] is Map ? Map<String, dynamic>.from(r['server'] as Map) : const {}));
      case 'rejected':
        return _Outcome(_Kind.rejected, op.copyWith(state: OpState.rejected, errorCode: (error?['code'] ?? 'rejected').toString(), errorMessage: (error?['message'] ?? 'The server did not accept this change.').toString()));
      default:
        // `error` (the server stopped here and says to retry) and `not_processed` (it stopped earlier).
        return _Outcome(_Kind.retry, op.copyWith(attempts: op.attempts + 1));
    }
  }

  Future<void> _afterFailure(String scope, List<PendingOp> batch, ApiError? e, {required bool escalate}) async {
    final ids = {for (final o in batch) o.opId};
    final ops = [for (final o in state.ops) ids.contains(o.opId) ? o.copyWith(attempts: o.attempts + 1) : o];
    await _store.write(scope, ops);
    if (!ref.mounted) return;
    state = state.copyWith(ops: ops, problem: e == null ? 'The server could not take everything yet.' : (e.isOffline ? 'No connection. Your changes are safe on this phone.' : 'The server is busy. Trying again soon.'));
    final delay = backoff[_failures.clamp(0, backoff.length - 1)];
    if (escalate) _failures += 1;
    _retry?.cancel();
    _retry = Timer(delay, () {
      if (ref.mounted) unawaited(flush(automatic: true));
    });
  }

  Future<void> _markAll(String scope, List<PendingOp> batch, OpState to, String code, String message) async {
    final ids = {for (final o in batch) o.opId};
    final ops = [for (final o in state.ops) ids.contains(o.opId) ? o.copyWith(state: to, errorCode: code, errorMessage: message) : o];
    await _store.write(scope, ops);
    if (ref.mounted) state = state.copyWith(ops: ops);
  }

  /// Called when the app returns to the foreground or the phone regains a connection.
  void nudge() {
    if (state.waiting.isNotEmpty && !state.syncing) unawaited(flush());
  }

  // ------------------------------------------------------------------------------------------------ the member's decisions

  /// Drop a change (a rejected one, or a conflict where the server's version is the one to keep).
  Future<void> discard(String opId) => _replace((ops) => [for (final o in ops) if (o.opId != opId) o]);

  /// A conflict where the member's version should win: sent again as a new change stamped now, which the server
  /// accepts because it is newer than what it holds.
  Future<void> keepMine(String opId) async {
    await _replace((ops) => [
          for (final o in ops)
            if (o.opId != opId)
              o
            else
              PendingOp(opId: _uuid.v4(), type: o.type, payload: o.payload, createdAt: _now().toUtc(), label: o.label),
        ]);
    unawaited(flush());
  }

  /// Everything that has not been sent, for the sign-out warning and for tests.
  int get unsentCount => state.ops.length;

  Future<void> _replace(List<PendingOp> Function(List<PendingOp>) change) async {
    final scope = _scope;
    if (scope == null) return;
    final ops = change(state.ops);
    await _store.write(scope, ops);
    if (ref.mounted) state = state.copyWith(ops: ops);
  }
}

enum _Kind { applied, conflict, rejected, retry }

class _Outcome {
  const _Outcome(this.kind, [this.op]);

  final _Kind kind;
  final PendingOp? op;
}

final offlineQueueProvider = NotifierProvider<OfflineQueue, QueueState>(OfflineQueue.new);
