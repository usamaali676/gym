import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_error.dart';
import '../../../core/api/loaded.dart';
import '../../../core/providers.dart';
import '../../../core/storage/cache_provider.dart';
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

  @override
  Future<WorkoutView> build() async {
    final loaded = await _load();
    final session = loaded.data;
    final steps = stepsOf(session);

    // Both the server and this phone may hold a saved position; the newer one wins, then it is checked against
    // what has really been logged.
    final server = EngineState.fromJson(session.clientState);
    final local = _repo.readLocal(sessionId);
    EngineState? chosen = server;
    if (local != null && (server == null || (local.savedAt ?? DateTime(0)).isAfter(server.savedAt ?? DateTime(0)))) chosen = local;

    var engine = chosen == null ? SessionEngine.initial(steps) : SessionEngine.reconcile(chosen, steps);
    engine = SessionEngine.normalize(engine, _now());
    return WorkoutView(session: session, engine: engine, fromCache: loaded.fromCache);
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

  /// Log the set on screen. Only after the server has accepted it does the workout move on, so what the member sees
  /// is what is saved. A failure keeps them on the set with their numbers.
  Future<bool> completeSet({double? weightKg, int? reps, int? durationSeconds}) async {
    final view = _view;
    final exercise = view?.exercise;
    if (view == null || exercise == null || view.busy) return false;

    state = AsyncData(view.copyWith(busy: true, clearError: true));
    try {
      final result = await _repo.logSet(
        sessionId,
        SetEntry(sessionExerciseId: exercise.id, setNumber: view.engine.setIdx + 1, weightKg: weightKg, reps: reps, durationSeconds: durationSeconds),
      );
      final current = _view ?? view;
      final sets = [...exercise.sets.where((s) => s.number != result.set.number), result.set]..sort((a, b) => a.number.compareTo(b.number));
      final updated = exercise.copyWith(sets: sets, status: result.exerciseStatus == 'done' ? 'done' : exercise.status);
      final session = current.session.withExercise(current.engine.idx, updated);
      ref.read(deviceFeedbackProvider).setLogged();
      _commit(current, SessionEngine.setLogged(current.engine, stepsOf(session), _now()), session: session);
      return true;
    } on ApiError catch (e) {
      final current = _view ?? view;
      state = AsyncData(current.copyWith(busy: false, error: e));
      return false;
    }
  }

  Future<void> skipExercise() async {
    final view = _view;
    final exercise = view?.exercise;
    if (view == null || exercise == null || view.busy) return;
    state = AsyncData(view.copyWith(busy: true, clearError: true));
    try {
      await _repo.skipExercise(sessionId, exercise.id);
      final current = _view ?? view;
      final session = current.session.withExercise(current.engine.idx, exercise.copyWith(status: 'skipped'));
      _commit(current, SessionEngine.exerciseSkipped(current.engine, stepsOf(session), _now()), session: session);
    } on ApiError catch (e) {
      state = AsyncData((_view ?? view).copyWith(busy: false, error: e));
    }
  }

  /// Ends the workout. The server needs at least one logged set; the active time sent is the phone's own measure
  /// (wall time minus pauses).
  Future<bool> finish() async {
    final view = _view;
    if (view == null || view.busy) return false;
    state = AsyncData(view.copyWith(busy: true, clearError: true));
    try {
      final active = SessionEngine.activeElapsed(view.engine, startedAt: view.session.startedAt, now: _now()).inSeconds;
      await _repo.finish(sessionId, activeSeconds: active);
      await _repo.clearLocal(sessionId);
      state = AsyncData((_view ?? view).copyWith(busy: false, finished: true));
      _refreshViews();
      return true;
    } on ApiError catch (e) {
      state = AsyncData((_view ?? view).copyWith(busy: false, error: e));
      return false;
    }
  }

  Future<bool> abandon() async {
    final view = _view;
    if (view == null || view.busy) return false;
    state = AsyncData(view.copyWith(busy: true, clearError: true));
    try {
      await _repo.abandon(sessionId);
      await _repo.clearLocal(sessionId);
      state = AsyncData((_view ?? view).copyWith(busy: false, finished: true));
      _refreshViews();
      return true;
    } on ApiError catch (e) {
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

final workoutControllerProvider = AsyncNotifierProvider.family<WorkoutController, WorkoutView, int>(WorkoutController.new);

final workoutSummaryProvider = FutureProvider.family<FinishedSummary, int>((ref, id) => ref.watch(sessionRepositoryProvider).summary(id));
