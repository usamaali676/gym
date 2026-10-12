import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_error.dart';
import '../../../core/api/loaded.dart';
import '../../../core/providers.dart';
import '../../../core/storage/cache_provider.dart';
import '../../../core/sync/offline_queue.dart';
import '../../../core/sync/pending_op.dart';
import '../../../core/utilities/clock.dart';
import '../../../core/utilities/device_feedback.dart';
import '../../dashboard/presentation/dashboard_providers.dart';
import '../../workout_programs/presentation/plan_providers.dart';
import '../data/session_repository.dart';
import '../domain/session_engine.dart';
import '../domain/workout_session.dart';

final sessionRepositoryProvider = Provider<SessionRepository>(
  (ref) => SessionRepository(api: ref.watch(apiClientProvider), cache: ref.watch(jsonCacheProvider)),
);

/// Everything the workout screen draws.
class WorkoutView {
  const WorkoutView({required this.session, required this.engine, this.busy = false, this.error, this.finished = false, this.fromCache = false});

  final WorkoutSession session;
  final EngineState engine;

  /// An API call (logging a set, finishing) is running.
  final bool busy;

  /// The last action's failure, shown without losing what the member typed.
  final ApiError? error;
  final bool finished;
  final bool fromCache;

  SessionExercise? get exercise => engine.idx < session.exercises.length ? session.exercises[engine.idx] : null;

  List<ExerciseStep> get steps => stepsOf(session);

  WorkoutView copyWith({WorkoutSession? session, EngineState? engine, bool? busy, ApiError? error, bool clearError = false, bool? finished}) => WorkoutView(
        session: session ?? this.session,
        engine: engine ?? this.engine,
        busy: busy ?? this.busy,
        error: clearError ? null : (error ?? this.error),
        finished: finished ?? this.finished,
        fromCache: fromCache,
      );
}

List<ExerciseStep> stepsOf(WorkoutSession s) => [for (final e in s.exercises) ExerciseStep(targetSets: e.targetSets, loggedSets: e.sets.length, skipped: e.isSkipped, restSeconds: e.restSeconds)];

/// Runs one workout. The state machine is [SessionEngine]; this class adds the network (log sets, finish) and the
/// on-phone copy that lets a workout survive a kill.
class WorkoutController extends AsyncNotifier<WorkoutView> {
  WorkoutController(this.sessionId);
  final int sessionId;

  SessionRepository get _repo => ref.read(sessionRepositoryProvider);
  DateTime _now() => ref.read(clockProvider)();

  OfflineQueue get _queue => ref.read(offlineQueueProvider.notifier);

  @override
  Future<WorkoutView> build() async {
    // When the server accepts (or the member dismisses) queued changes, the "waiting to sync" marks must follow.
    ref.listen(offlineQueueProvider, (_, queue) => _refreshPendingMarks(queue.forSession(sessionId)));

    final loaded = await _load();
    // What was done on this phone while offline and is still waiting for the server counts as done.
    final session = withQueuedChanges(loaded.data, ref.read(offlineQueueProvider).forSession(sessionId));
    final steps = stepsOf(session);

    // Both the server and this phone may hold a saved position; the newer one wins, then it is checked against
    // what has really been logged.
    final server = EngineState.fromJson(session.clientState);
    final local = _repo.readLocal(sessionId);
    EngineState? chosen = server;
    if (local != null && (server == null || (local.savedAt ?? DateTime(0)).isAfter(server.savedAt ?? DateTime(0)))) chosen = local;

    var engine = chosen == null ? SessionEngine.initial(steps) : SessionEngine.reconcile(chosen, steps);
    engine = SessionEngine.normalize(engine, _now());
    final closing = ref.read(offlineQueueProvider).forSession(sessionId).any((o) => o.type == OpTypes.workoutFinish || o.type == OpTypes.workoutAbandon);
    return WorkoutView(session: session, engine: engine, fromCache: loaded.fromCache, finished: closing);
  }

  void _refreshPendingMarks(List<PendingOp> mine) {
    final view = _view;
    if (view == null) return;
    final waiting = {
      for (final o in mine)
        if (o.type == OpTypes.setLog) '${o.payload['session_exercise_id']}/${o.payload['set_number']}',
    };
    var changed = false;
    final exercises = [
      for (final e in view.session.exercises)
        e.copyWith(sets: [
          for (final set in e.sets)
            if (set.pending && !waiting.contains('${e.id}/${set.number}')) () {
              changed = true;
              return set.copyWith(pending: false);
            }() else set,
        ]),
    ];
    if (changed) state = AsyncData(view.copyWith(session: view.session.withExercises(exercises)));
  }

  Future<Loaded<WorkoutSession>> _load() async {
    final cache = ref.read(jsonCacheProvider);
    final api = ref.read(apiClientProvider);
    return loadWithCache(
      cache: cache,
      key: 'session.$sessionId.data',
      fetch: () async => (await api.get('/workout-sessions/$sessionId')).data,
      parse: WorkoutSession.fromJson,
    );
  }

  WorkoutView? get _view => state.asData?.value;

  /// Applies a new engine state: saves it on the phone and (best effort) on the server.
  void _commit(WorkoutView view, EngineState next, {WorkoutSession? session, bool busy = false, bool clearError = true}) {
    final now = _now();
    final stamped = next.copyWith(savedAt: now);
    state = AsyncData(view.copyWith(session: session, engine: stamped, busy: busy, clearError: clearError));
    unawaited(_repo.writeLocal(sessionId, stamped));
    unawaited(_repo.saveState(sessionId, stamped.toServerJson(startedAt: (session ?? view.session).startedAt, now: now)));
  }

  /// Called every few hundred ms by the screen, and when the app returns to the foreground. Cheap: it only changes
  /// anything when a rest has run out.
  void tick() {
    final view = _view;
    if (view == null || view.finished) return;
    final next = SessionEngine.normalize(view.engine, _now());
    if (identical(next, view.engine)) return;
    ref.read(deviceFeedbackProvider).restOver(sound: view.session.soundOn);
    _commit(view, next);
  }

  void startSet() {
    final view = _view;
    if (view == null) return;
    _commit(view, SessionEngine.startSet(view.engine, _now()));
  }

  void pause() {
    final view = _view;
    if (view == null) return;
    _commit(view, SessionEngine.pause(view.engine, _now()));
  }

  void resume() {
    final view = _view;
    if (view == null) return;
    _commit(view, SessionEngine.resume(view.engine, _now()));
  }

  void skipRest() {
    final view = _view;
    if (view == null) return;
    _commit(view, SessionEngine.skipRest(view.engine, _now()));
  }

  void extendRest([Duration by = const Duration(seconds: 30)]) {
    final view = _view;
    if (view == null) return;
    _commit(view, SessionEngine.extendRest(view.engine, by, _now()));
  }

  /// Log the set on screen. Online, the workout moves on only after the server has accepted the set, so what the member
  /// sees is what is saved. Without a connection the set is kept in the offline queue and the workout moves on at once;
  /// the set is marked "waiting to sync" until the server has it. Any other failure keeps them on the set with their numbers.
  Future<bool> completeSet({double? weightKg, int? reps, int? durationSeconds}) async {
    final view = _view;
    final exercise = view?.exercise;
    if (view == null || exercise == null || view.busy) return false;

    final entry = SetEntry(sessionExerciseId: exercise.id, setNumber: view.engine.setIdx + 1, weightKg: weightKg, reps: reps, durationSeconds: durationSeconds);
    state = AsyncData(view.copyWith(busy: true, clearError: true));

    // Changes already waiting for this workout go first, so the server sees the sets in the order they were done.
    if (_queue.hasQueuedFor(sessionId)) return _logQueued(entry, exercise);

    try {
      final result = await _repo.logSet(sessionId, entry);
      final current = _view ?? view;
      final sets = [...exercise.sets.where((s) => s.number != result.set.number), result.set]..sort((a, b) => a.number.compareTo(b.number));
      final updated = exercise.copyWith(sets: sets, status: result.exerciseStatus == 'done' ? 'done' : exercise.status);
      final session = current.session.withExercise(current.engine.idx, updated);
      ref.read(deviceFeedbackProvider).setLogged();
      _commit(current, SessionEngine.setLogged(current.engine, stepsOf(session), _now()), session: session);
      return true;
    } on ApiError catch (e) {
      if (e.isOffline) return _logQueued(entry, exercise);
      final current = _view ?? view;
      state = AsyncData(current.copyWith(busy: false, error: e));
      return false;
    }
  }

  Future<bool> _logQueued(SetEntry entry, SessionExercise exercise) async {
    final view = _view!;
    await _queue.enqueue(OpTypes.setLog, SessionRepository.setPayload(sessionId, entry), label: _setLabel(exercise.name, entry));
    final current = _view ?? view;
    final local = LoggedSet(id: -DateTime.now().microsecondsSinceEpoch, number: entry.setNumber, weightKg: entry.weightKg, reps: entry.reps, durationSeconds: entry.durationSeconds, isPr: false, pending: true);
    final sets = [...exercise.sets.where((s) => s.number != local.number), local]..sort((a, b) => a.number.compareTo(b.number));
    final session = current.session.withExercise(current.engine.idx, exercise.copyWith(sets: sets));
    ref.read(deviceFeedbackProvider).setLogged();
    _commit(current, SessionEngine.setLogged(current.engine, stepsOf(session), _now()), session: session);
    return true;
  }

  Future<void> skipExercise() async {
    final view = _view;
    final exercise = view?.exercise;
    if (view == null || exercise == null || view.busy) return;
    state = AsyncData(view.copyWith(busy: true, clearError: true));

    Future<void> skipLocally({required bool queued}) async {
      if (queued) await _queue.enqueue(OpTypes.exerciseSkip, {'session_id': sessionId, 'session_exercise_id': exercise.id}, label: 'Skip ${exercise.name}');
      final current = _view ?? view;
      final session = current.session.withExercise(current.engine.idx, exercise.copyWith(status: 'skipped'));
      _commit(current, SessionEngine.exerciseSkipped(current.engine, stepsOf(session), _now()), session: session);
    }

    if (_queue.hasQueuedFor(sessionId)) return skipLocally(queued: true);
    try {
      await _repo.skipExercise(sessionId, exercise.id);
      await skipLocally(queued: false);
    } on ApiError catch (e) {
      if (e.isOffline) return skipLocally(queued: true);
      state = AsyncData((_view ?? view).copyWith(busy: false, error: e));
    }
  }

  /// Ends the workout. The server needs at least one logged set; the active time sent is the phone's own measure
  /// (wall time minus pauses). Without a connection the finish is queued behind the sets and the summary says that
  /// the results (XP, records, streak) are worked out by the server once it syncs.
  Future<bool> finish() async {
    final view = _view;
    if (view == null || view.busy) return false;
    state = AsyncData(view.copyWith(busy: true, clearError: true));
    final active = SessionEngine.activeElapsed(view.engine, startedAt: view.session.startedAt, now: _now()).inSeconds.clamp(0, 21600);

    Future<bool> queueIt() async {
      await _queue.enqueue(OpTypes.workoutFinish, {'session_id': sessionId, 'active_seconds': active}, label: 'Finish ${view.session.title}');
      await _repo.clearLocal(sessionId);
      state = AsyncData((_view ?? view).copyWith(busy: false, finished: true));
      return true;
    }

    if (_queue.hasQueuedFor(sessionId)) return queueIt();
    try {
      await _repo.finish(sessionId, activeSeconds: active);
      await _repo.clearLocal(sessionId);
      state = AsyncData((_view ?? view).copyWith(busy: false, finished: true));
      _refreshViews();
      return true;
    } on ApiError catch (e) {
      if (e.isOffline) return queueIt();
      state = AsyncData((_view ?? view).copyWith(busy: false, error: e));
      return false;
    }
  }

  Future<bool> abandon() async {
    final view = _view;
    if (view == null || view.busy) return false;
    state = AsyncData(view.copyWith(busy: true, clearError: true));

    Future<bool> queueIt() async {
      await _queue.enqueue(OpTypes.workoutAbandon, {'session_id': sessionId}, label: 'Abandon ${view.session.title}');
      await _repo.clearLocal(sessionId);
      state = AsyncData((_view ?? view).copyWith(busy: false, finished: true));
      return true;
    }

    if (_queue.hasQueuedFor(sessionId)) return queueIt();
    try {
      await _repo.abandon(sessionId);
      await _repo.clearLocal(sessionId);
      state = AsyncData((_view ?? view).copyWith(busy: false, finished: true));
      _refreshViews();
      return true;
    } on ApiError catch (e) {
      if (e.isOffline) return queueIt();
      state = AsyncData((_view ?? view).copyWith(busy: false, error: e));
      return false;
    }
  }

  void _refreshViews() {
    ref.invalidate(dashboardProvider);
    ref.invalidate(weekPlanProvider);
    ref.invalidate(dayDetailProvider);
  }
}

String _kg(double v) => v == v.roundToDouble() ? v.toStringAsFixed(0) : v.toString();

String _setLabel(String exercise, SetEntry e) {
  final what = e.durationSeconds != null && (e.reps ?? 0) == 0 ? '${e.durationSeconds} s' : '${e.weightKg == null ? 'bodyweight' : '${_kg(e.weightKg!)} kg'} × ${e.reps ?? 0}';
  return '$exercise, set ${e.setNumber}: $what';
}

/// The workout as the server last described it plus what this phone has done since and not yet sent: queued sets count
/// as logged (marked "waiting to sync") and queued skips as skipped.
WorkoutSession withQueuedChanges(WorkoutSession s, List<PendingOp> queued) {
  if (queued.isEmpty) return s;
  final exercises = <SessionExercise>[];
  for (final e in s.exercises) {
    final sets = [for (final set in e.sets) set];
    for (final o in queued) {
      if (o.type != OpTypes.setLog || (o.payload['session_exercise_id'] as num?)?.toInt() != e.id) continue;
      final number = (o.payload['set_number'] as num).toInt();
      sets.removeWhere((x) => x.number == number);
      sets.add(LoggedSet(
        id: -o.createdAt.microsecondsSinceEpoch,
        number: number,
        weightKg: (o.payload['weight_kg'] as num?)?.toDouble(),
        reps: (o.payload['reps'] as num?)?.toInt(),
        durationSeconds: (o.payload['duration_seconds'] as num?)?.toInt(),
        isPr: false,
        pending: true,
      ));
    }
    sets.sort((a, b) => a.number.compareTo(b.number));
    final skipped = queued.any((o) => o.type == OpTypes.exerciseSkip && (o.payload['session_exercise_id'] as num?)?.toInt() == e.id);
    exercises.add(e.copyWith(sets: sets, status: skipped ? 'skipped' : e.status));
  }
  return s.withExercises(exercises);
}

final workoutControllerProvider = AsyncNotifierProvider.family<WorkoutController, WorkoutView, int>(WorkoutController.new);

final workoutSummaryProvider = FutureProvider.family<FinishedSummary, int>((ref, id) => ref.watch(sessionRepositoryProvider).summary(id));
