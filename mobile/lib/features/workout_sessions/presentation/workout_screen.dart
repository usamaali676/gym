import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/router.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/utilities/clock.dart';
import '../../../core/utilities/device_feedback.dart';
import '../../../core/widgets/error_banner.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/neon_progress.dart';
import '../../../core/widgets/number_stepper.dart';
import '../../../core/widgets/state_views.dart';
import '../../../core/widgets/status_chip.dart';
import '../domain/session_engine.dart';
import '../domain/workout_session.dart';
import 'workout_controller.dart';

/// The immersive workout: one thing on screen at a time (read the exercise, do the set, rest). All timers come from
/// the engine's saved timestamps, so a phone call, the screen locking or an app restart cannot desynchronise them.
class WorkoutScreen extends ConsumerStatefulWidget {
  const WorkoutScreen({super.key, required this.sessionId});
  final int sessionId;

  @override
  ConsumerState<WorkoutScreen> createState() => _WorkoutScreenState();
}

class _WorkoutScreenState extends ConsumerState<WorkoutScreen> with WidgetsBindingObserver {
  Timer? _ticker;
  late final DeviceFeedback _feedback;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _feedback = ref.read(deviceFeedbackProvider);
    _feedback.keepScreenOn(true);
    // Twice a second is enough to notice a rest ending; the displayed numbers are recomputed from the clock each time.
    _ticker = Timer.periodic(const Duration(milliseconds: 500), (_) => ref.read(workoutControllerProvider(widget.sessionId).notifier).tick());
  }

  @override
  void dispose() {
    _ticker?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    _feedback.keepScreenOn(false);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Back from the background: catch up at once instead of waiting for the next tick.
    if (state == AppLifecycleState.resumed) ref.read(workoutControllerProvider(widget.sessionId).notifier).tick();
  }

  Future<void> _leave(WorkoutView view) async {
    final controller = ref.read(workoutControllerProvider(widget.sessionId).notifier);
    final choice = await showModalBottomSheet<String>(
      context: context,
      builder: (context) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 24),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text('Leave this workout?', style: IpText.display(17, color: IpColors.ink)),
            const SizedBox(height: 6),
            Text('Your sets are saved. You can pick up where you left off.', style: IpText.body(13.5, color: IpColors.muted)),
            const SizedBox(height: 16),
            NeonButton(label: 'Keep going', expand: true, onPressed: () => Navigator.pop(context, 'stay')),
            const SizedBox(height: 10),
            NeonButton(label: 'Pause and leave', expand: true, variant: NeonButtonVariant.ghost, onPressed: () => Navigator.pop(context, 'pause')),
            if (view.session.setsLogged > 0) ...[
              const SizedBox(height: 10),
              NeonButton(label: 'Finish workout now', expand: true, variant: NeonButtonVariant.ghost, onPressed: () => Navigator.pop(context, 'finish')),
            ],
            const SizedBox(height: 10),
            NeonButton(label: 'Abandon workout', expand: true, variant: NeonButtonVariant.danger, onPressed: () => Navigator.pop(context, 'abandon')),
          ]),
        ),
      ),
    );
    if (!mounted) return;
    switch (choice) {
      case 'pause':
        controller.pause();
        context.go(Routes.home);
      case 'finish':
        await controller.finish();
      case 'abandon':
        final sure = await showDialog<bool>(
          context: context,
          builder: (context) => AlertDialog(
            title: const Text('Abandon workout?'),
            content: Text('The sets you logged stay in your history, but this workout will not count as completed.', style: IpText.body(14, color: IpColors.muted)),
            actions: [
              TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
              NeonButton(label: 'Abandon', variant: NeonButtonVariant.danger, size: NeonButtonSize.small, onPressed: () => Navigator.pop(context, true)),
            ],
          ),
        );
        if (sure == true) await controller.abandon();
      default:
    }
  }

  @override
  Widget build(BuildContext context) {
    final provider = workoutControllerProvider(widget.sessionId);
    final value = ref.watch(provider);

    ref.listen(provider, (previous, next) {
      final view = next.asData?.value;
      if (view != null && view.finished && !(previous?.asData?.value.finished ?? false)) {
        if (view.session.setsLogged > 0 && view.error == null) {
          context.go('${Routes.workout}/${widget.sessionId}/summary');
        } else {
          context.go(Routes.home);
        }
      }
    });

    return value.when(
      loading: () => const Scaffold(body: Center(child: CircularProgressIndicator())),
      error: (e, _) => Scaffold(
        appBar: AppBar(leading: BackButton(onPressed: () => context.go(Routes.home))),
        body: ErrorState(error: e, onRetry: () => ref.invalidate(provider)),
      ),
      data: (view) => PopScope(
        canPop: false,
        onPopInvokedWithResult: (didPop, _) {
          if (!didPop) _leave(view);
        },
        child: Scaffold(
          body: SafeArea(
            child: Column(children: [
              _TopBar(view: view, onClose: () => _leave(view)),
              if (view.error != null) Padding(padding: const EdgeInsets.fromLTRB(20, 0, 20, 8), child: MessageBanner(view.error!.message)),
              Expanded(child: _Body(view: view)),
            ]),
          ),
        ),
      ),
    );
  }
}

// ------------------------------------------------------------------------------------------------- top bar

class _TopBar extends ConsumerWidget {
  const _TopBar({required this.view, required this.onClose});
  final WorkoutView view;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final controller = ref.read(workoutControllerProvider(view.session.id).notifier);
    final e = view.engine;
    final total = view.session.exercises.length;
    final canPause = e.phase == WorkPhase.work || e.phase == WorkPhase.rest;

    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 4, 8, 4),
      child: Row(children: [
        IconButton(tooltip: 'Leave workout', onPressed: onClose, icon: const Icon(Icons.close)),
        Expanded(
          child: Column(children: [
            Text(view.session.title.toUpperCase(), maxLines: 1, overflow: TextOverflow.ellipsis, style: IpText.eyebrow(color: IpColors.neon)),
            const SizedBox(height: 2),
            Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              if (e.phase != WorkPhase.finish) Text('Exercise ${(e.idx + 1).clamp(1, total)} of $total  ·  ', style: IpText.body(12.5, color: IpColors.muted)),
              _Live(builder: (now) => Text(_clock(SessionEngine.activeElapsed(e, startedAt: view.session.startedAt, now: now)), style: IpText.number(16, color: IpColors.ink))),
            ]),
          ]),
        ),
        if (canPause)
          IconButton(tooltip: 'Pause', onPressed: controller.pause, icon: const Icon(Icons.pause_circle_outline, size: 28))
        else
          const SizedBox(width: 48),
      ]),
    );
  }
}

String _clock(Duration d) {
  final total = d.inSeconds < 0 ? 0 : d.inSeconds;
  final h = total ~/ 3600;
  final m = (total % 3600) ~/ 60;
  final s = total % 60;
  final mm = m.toString().padLeft(2, '0');
  final ss = s.toString().padLeft(2, '0');
  return h > 0 ? '$h:$mm:$ss' : '$mm:$ss';
}

/// Rebuilds a few times a second from the clock. The displayed value is always computed from timestamps.
class _Live extends ConsumerStatefulWidget {
  const _Live({required this.builder});
  final Widget Function(DateTime now) builder;

  @override
  ConsumerState<_Live> createState() => _LiveState();
}

class _LiveState extends ConsumerState<_Live> {
  Timer? _t;

  @override
  void initState() {
    super.initState();
    _t = Timer.periodic(const Duration(milliseconds: 250), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _t?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => widget.builder(ref.read(clockProvider)());
}

// ------------------------------------------------------------------------------------------------------ body

class _Body extends ConsumerWidget {
  const _Body({required this.view});
  final WorkoutView view;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final e = view.engine;
    final exercise = view.exercise;

    if (e.phase == WorkPhase.finish || exercise == null) return _FinishPanel(view: view);
    return switch (e.phase) {
      WorkPhase.intro => _IntroPanel(view: view, exercise: exercise),
      WorkPhase.work => _WorkPanel(view: view, exercise: exercise),
      WorkPhase.rest => _RestPanel(view: view, exercise: exercise),
      WorkPhase.paused => _PausedPanel(view: view, exercise: exercise),
      WorkPhase.finish => _FinishPanel(view: view),
    };
  }
}

class _ExerciseHeader extends StatelessWidget {
  const _ExerciseHeader({required this.exercise, this.compact = false});
  final SessionExercise exercise;
  final bool compact;

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(exercise.name, style: IpText.display(compact ? 20 : 26, color: IpColors.ink)),
        const SizedBox(height: 8),
        Wrap(spacing: 6, runSpacing: 6, children: [
          if (exercise.muscleGroupLabel != null) StatusChip(exercise.muscleGroupLabel!, tone: Tone.good),
          StatusChip(exercise.isTimed ? '${exercise.targetSets} × hold' : '${exercise.targetSets} × ${exercise.repsLabel}'),
          if (exercise.swappedFrom != null) StatusChip('Swapped from ${exercise.swappedFrom}'),
        ]),
      ]);
}

class _IntroPanel extends ConsumerWidget {
  const _IntroPanel({required this.view, required this.exercise});
  final WorkoutView view;
  final SessionExercise exercise;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final controller = ref.read(workoutControllerProvider(view.session.id).notifier);
    final s = exercise.suggested;
    return ListView(padding: const EdgeInsets.fromLTRB(20, 8, 20, 24), children: [
      _ExerciseHeader(exercise: exercise),
      const SizedBox(height: 16),
      if (s.weightKg != null || s.reps != null || exercise.lastSummary != null)
        GlassCard(
          neon: true,
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (exercise.lastSummary != null) Text('Last time: ${exercise.lastSummary}', style: IpText.body(13, color: IpColors.muted)),
            if (s.weightKg != null || s.reps != null) ...[
              const SizedBox(height: 4),
              Text('Today: ${s.weightKg == null ? 'bodyweight' : '${_trim(s.weightKg!)} kg'} × ${s.reps ?? exercise.repsLabel}', style: IpText.body(15, weight: FontWeight.w600, color: IpColors.neon)),
            ],
            if (s.reason != null && s.reason!.isNotEmpty) ...[const SizedBox(height: 4), Text(s.reason!, style: IpText.body(12.5, color: IpColors.muted))],
          ]),
        ),
      if (exercise.formCues.isNotEmpty) ...[
        const SizedBox(height: 14),
        GlassCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('FORM CUES', style: IpText.eyebrow(color: IpColors.neon)),
            const SizedBox(height: 8),
            for (final cue in exercise.formCues.take(5)) Padding(padding: const EdgeInsets.only(bottom: 6), child: Text('• $cue', style: IpText.body(14, color: IpColors.ink))),
          ]),
        ),
      ],
      const SizedBox(height: 24),
      NeonButton(label: exercise.sets.isEmpty ? 'Start set 1' : 'Start set ${view.engine.setIdx + 1}', size: NeonButtonSize.large, expand: true, icon: Icons.play_arrow, onPressed: controller.startSet),
      const SizedBox(height: 10),
      NeonButton(label: 'Skip exercise', expand: true, variant: NeonButtonVariant.ghost, loading: view.busy, onPressed: controller.skipExercise),
    ]);
  }
}

String _trim(double v) => v == v.roundToDouble() ? v.toStringAsFixed(0) : v.toString();

class _WorkPanel extends ConsumerStatefulWidget {
  const _WorkPanel({required this.view, required this.exercise});
  final WorkoutView view;
  final SessionExercise exercise;

  @override
  ConsumerState<_WorkPanel> createState() => _WorkPanelState();
}

class _WorkPanelState extends ConsumerState<_WorkPanel> {
  late double _weight;
  late double _reps;
  (int, int)? _seededFor;

  /// Pre-fills from this exercise's previous set today, else the server's suggestion, else the bottom of the rep range.
  void _seed() {
    final e = widget.exercise;
    final key = (e.id, widget.view.engine.setIdx);
    if (_seededFor == key) return;
    _seededFor = key;
    final lastSet = e.sets.isEmpty ? null : e.sets.last;
    _weight = lastSet?.weightKg ?? e.suggested.weightKg ?? 0;
    _reps = (lastSet?.reps ?? e.suggested.reps ?? e.repsMin ?? 8).toDouble();
  }

  @override
  Widget build(BuildContext context) {
    _seed();
    final view = widget.view;
    final e = widget.exercise;
    final controller = ref.read(workoutControllerProvider(view.session.id).notifier);
    final setNumber = view.engine.setIdx + 1;

    return ListView(padding: const EdgeInsets.fromLTRB(20, 8, 20, 24), children: [
      _ExerciseHeader(exercise: e, compact: true),
      const SizedBox(height: 20),
      Center(child: Text('SET $setNumber OF ${e.targetSets}', style: IpText.display(15, color: IpColors.neon))),
      const SizedBox(height: 18),
      if (e.isTimed) ..._holdControls(view, e, controller) else ..._repControls(view, e, controller),
      const SizedBox(height: 14),
      NeonButton(label: 'Skip exercise', expand: true, variant: NeonButtonVariant.ghost, onPressed: view.busy ? null : controller.skipExercise),
      if (e.sets.isNotEmpty) ...[
        const SizedBox(height: 18),
        Text('LOGGED', style: IpText.eyebrow(color: IpColors.muted)),
        const SizedBox(height: 8),
        for (final s in e.sets)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Row(children: [
              Text('Set ${s.number}', style: IpText.body(13.5, color: IpColors.muted)),
              const Spacer(),
              Text(s.durationSeconds != null && (s.reps ?? 0) == 0 ? '${s.durationSeconds} s' : '${s.weightKg == null ? 'Bodyweight' : '${_trim(s.weightKg!)} kg'} × ${s.reps}', style: IpText.body(14, weight: FontWeight.w600, color: IpColors.ink)),
              if (s.isPr) ...[const SizedBox(width: 8), const StatusChip('PR', tone: Tone.good)],
            ]),
          ),
      ],
    ]);
  }

  List<Widget> _repControls(WorkoutView view, SessionExercise e, WorkoutController controller) => [
        Row(mainAxisAlignment: MainAxisAlignment.spaceEvenly, children: [
          Flexible(child: NumberStepper(label: 'Weight', unit: 'kg', value: _weight, step: 2.5, decimals: 1, max: 500, onChanged: (v) => _weight = v)),
        ]),
        const SizedBox(height: 18),
        NumberStepper(label: 'Reps', value: _reps, step: 1, max: 200, onChanged: (v) => _reps = v),
        const SizedBox(height: 24),
        NeonButton(
          label: 'Complete set',
          size: NeonButtonSize.large,
          expand: true,
          icon: Icons.check,
          loading: view.busy,
          onPressed: () => controller.completeSet(weightKg: _weight > 0 ? _weight : null, reps: _reps.round()),
        ),
      ];

  List<Widget> _holdControls(WorkoutView view, SessionExercise e, WorkoutController controller) {
    final hold = e.holdSeconds ?? 30;
    return [
      _Live(builder: (now) {
        final left = SessionEngine.remainingHold(view.engine, now, hold);
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Center(child: Text(_clock(left), style: IpText.number(64, color: left == Duration.zero ? IpColors.neon : IpColors.ink))),
          const SizedBox(height: 8),
          NeonProgress(value: 1 - left.inMilliseconds / (hold * 1000), semanticLabel: 'Hold progress'),
          const SizedBox(height: 4),
          Center(child: Text('Hold for $hold seconds', style: IpText.body(12.5, color: IpColors.muted))),
        ]);
      }),
      const SizedBox(height: 24),
      NeonButton(
        label: 'Complete hold',
        size: NeonButtonSize.large,
        expand: true,
        icon: Icons.check,
        loading: view.busy,
        onPressed: () {
          final held = ref.read(clockProvider)().difference(view.engine.workStartedAt ?? ref.read(clockProvider)()).inSeconds;
          controller.completeSet(durationSeconds: held.clamp(1, hold * 3), reps: 0);
        },
      ),
    ];
  }
}

class _RestPanel extends ConsumerWidget {
  const _RestPanel({required this.view, required this.exercise});
  final WorkoutView view;
  final SessionExercise exercise;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final controller = ref.read(workoutControllerProvider(view.session.id).notifier);
    final next = view.engine.idx < view.session.exercises.length ? view.session.exercises[view.engine.idx] : null;
    final nextIsSameExercise = view.engine.setIdx > 0;

    return ListView(padding: const EdgeInsets.fromLTRB(20, 8, 20, 24), children: [
      Center(child: Text('REST', style: IpText.display(15, color: IpColors.neon))),
      const SizedBox(height: 14),
      _Live(builder: (now) {
        final left = SessionEngine.remainingRest(view.engine, now);
        final total = view.engine.restTotalMs <= 0 ? 1 : view.engine.restTotalMs;
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Center(child: Semantics(liveRegion: false, label: 'Rest, ${left.inSeconds} seconds left', excludeSemantics: true, child: Text(_clock(left), style: IpText.number(88, color: IpColors.ink)))),
          const SizedBox(height: 10),
          NeonProgress(value: 1 - left.inMilliseconds / total, height: 10, semanticLabel: 'Rest progress'),
        ]);
      }),
      const SizedBox(height: 24),
      Row(children: [
        Expanded(child: NeonButton(label: '+30 s', variant: NeonButtonVariant.ghost, expand: true, onPressed: controller.extendRest)),
        const SizedBox(width: 12),
        Expanded(child: NeonButton(label: 'Skip rest', expand: true, onPressed: controller.skipRest)),
      ]),
      const SizedBox(height: 24),
      if (next != null)
        GlassCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('UP NEXT', style: IpText.eyebrow(color: IpColors.neon)),
            const SizedBox(height: 8),
            Text(next.name, style: IpText.display(17, color: IpColors.ink)),
            const SizedBox(height: 4),
            Text(nextIsSameExercise ? 'Set ${view.engine.setIdx + 1} of ${next.targetSets}' : 'New exercise · ${next.targetSets} × ${next.isTimed ? 'hold' : next.repsLabel}', style: IpText.body(13.5, color: IpColors.muted)),
            if (nextIsSameExercise && next.sets.isNotEmpty) ...[
              const SizedBox(height: 4),
              Text('Last set: ${next.sets.last.weightKg == null ? 'bodyweight' : '${_trim(next.sets.last.weightKg!)} kg'} × ${next.sets.last.reps}', style: IpText.body(13, color: IpColors.muted)),
            ],
          ]),
        ),
    ]);
  }
}

class _PausedPanel extends ConsumerWidget {
  const _PausedPanel({required this.view, required this.exercise});
  final WorkoutView view;
  final SessionExercise exercise;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final controller = ref.read(workoutControllerProvider(view.session.id).notifier);
    final restLeft = view.engine.pausedDuringRest ? SessionEngine.remainingRest(view.engine, ref.read(clockProvider)()) : null;

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: GlassCard(
          neon: true,
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.pause_circle_outline, size: 48, color: IpColors.warn),
            const SizedBox(height: 12),
            Text('Paused', style: IpText.display(20, color: IpColors.ink)),
            const SizedBox(height: 8),
            Text(restLeft != null ? 'Your rest is on hold with ${_clock(restLeft)} left.' : 'Your set is on hold. The clock stopped.', textAlign: TextAlign.center, style: IpText.body(14, color: IpColors.muted)),
            const SizedBox(height: 20),
            NeonButton(label: 'Resume', size: NeonButtonSize.large, expand: true, icon: Icons.play_arrow, onPressed: controller.resume),
          ]),
        ),
      ),
    );
  }
}

class _FinishPanel extends ConsumerWidget {
  const _FinishPanel({required this.view});
  final WorkoutView view;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final controller = ref.read(workoutControllerProvider(view.session.id).notifier);
    final done = view.session.exercises.where((e) => e.sets.isNotEmpty).length;
    final skipped = view.session.exercises.where((e) => e.isSkipped).length;

    return ListView(padding: const EdgeInsets.fromLTRB(20, 24, 20, 24), children: [
      const Icon(Icons.emoji_events_outlined, size: 56, color: IpColors.neon),
      const SizedBox(height: 12),
      Center(child: Text('All sets done', style: IpText.display(24, color: IpColors.ink))),
      const SizedBox(height: 8),
      Center(child: Text('$done ${done == 1 ? 'exercise' : 'exercises'} trained · ${view.session.setsLogged} sets${skipped > 0 ? ' · $skipped skipped' : ''}', style: IpText.body(14, color: IpColors.muted))),
      const SizedBox(height: 28),
      NeonButton(label: 'Finish workout', size: NeonButtonSize.large, expand: true, icon: Icons.flag, loading: view.busy, onPressed: controller.finish),
    ]);
  }
}
