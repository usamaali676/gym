import 'package:flutter/foundation.dart' show kDebugMode;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../app/theme/app_theme.dart';
import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/api/api_error.dart';
import '../../../core/utilities/device_feedback.dart';
import '../../../core/widgets/error_banner.dart';
import '../../../core/widgets/glass_card.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/neon_progress.dart';
import '../../../core/widgets/state_views.dart';
import '../../../core/widgets/status_chip.dart';
import '../../membership/presentation/locked_view.dart';
import '../domain/posture_models.dart';
import 'posture_coach.dart';
import 'posture_providers.dart';
import 'skeleton_painter.dart';

/// One camera-coached set of one exercise.
class PostureSessionScreen extends ConsumerWidget {
  const PostureSessionScreen({super.key, required this.slug, this.workoutSessionId});

  final String slug;

  /// When the set belongs to a workout in progress.
  final int? workoutSessionId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(postureRuleProvider(slug));
    return value.when(
      loading: () => Scaffold(appBar: AppBar(title: const Text('Camera form coach')), body: const Padding(padding: EdgeInsets.all(20), child: SkeletonList())),
      error: (error, _) {
        if (error is PostureRuleUnusable) {
          return Scaffold(appBar: AppBar(title: const Text('Camera form coach')), body: EmptyState(icon: Icons.videocam_off_outlined, title: 'Not available', message: error.message));
        }
        final e = ApiError.from(error);
        return Scaffold(
          appBar: AppBar(title: const Text('Camera form coach')),
          body: e.kind == ApiErrorKind.entitlementRequired ? LockedView(entitlementKey: e.missingEntitlement) : ErrorState(error: e, onRetry: () => ref.invalidate(postureRuleProvider(slug))),
        );
      },
      data: (loaded) => _CoachStage(rule: loaded.data, workoutSessionId: workoutSessionId),
    );
  }
}

class _CoachStage extends ConsumerStatefulWidget {
  const _CoachStage({required this.rule, this.workoutSessionId});

  final PostureRule rule;
  final int? workoutSessionId;

  @override
  ConsumerState<_CoachStage> createState() => _CoachStageState();
}

class _CoachStageState extends ConsumerState<_CoachStage> with WidgetsBindingObserver {
  late final PostureCoach _coach;

  @override
  void initState() {
    super.initState();
    _coach = PostureCoach(
      rule: widget.rule,
      opener: ref.read(poseCaptureOpenerProvider),
      repository: ref.read(postureRepositoryProvider),
      feedback: ref.read(deviceFeedbackProvider),
      workoutSessionId: widget.workoutSessionId,
    );
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) => _coach.lifecycle(state);

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _coach.dispose();
    super.dispose();
  }

  /// Leaving mid-set or before an unsaved set is saved loses the result, so ask first.
  bool get _needsConfirm => _coach.phase == CoachPhase.live || (_coach.phase == CoachPhase.summary && _coach.saveState == SaveState.failed);

  Future<void> _leave() async {
    final leave = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(_coach.phase == CoachPhase.live ? 'Leave this set?' : 'Leave without saving?'),
        content: Text(_coach.phase == CoachPhase.live ? 'The reps counted so far will be lost.' : 'This set has not been saved yet.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Stay')),
          TextButton(onPressed: () => Navigator.pop(context, true), child: const Text('Leave')),
        ],
      ),
    );
    if (leave == true && mounted) context.pop();
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _coach,
      builder: (context, _) {
        final fullScreen = {CoachPhase.position, CoachPhase.countdown, CoachPhase.live}.contains(_coach.phase);
        return PopScope(
          canPop: !_needsConfirm,
          onPopInvokedWithResult: (didPop, _) {
            if (!didPop) _leave();
          },
          child: Scaffold(
            appBar: AppBar(title: Text(widget.rule.exercise.name)),
            body: SafeArea(
              child: switch (_coach.phase) {
                CoachPhase.intro => _Intro(rule: widget.rule, onStart: _coach.start),
                CoachPhase.loading => const _Loading(),
                CoachPhase.position || CoachPhase.countdown || CoachPhase.live => _Live(coach: _coach),
                CoachPhase.summary => _Summary(coach: _coach),
                CoachPhase.error => _Problem(message: _coach.problem ?? 'Something went wrong starting the camera.', onRetry: _coach.start),
              },
            ),
            // the stage needs every pixel; the summary and intro scroll
            resizeToAvoidBottomInset: !fullScreen,
          ),
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------------------------------- intro

class _Intro extends StatelessWidget {
  const _Intro({required this.rule, required this.onStart});

  final PostureRule rule;
  final VoidCallback onStart;

  @override
  Widget build(BuildContext context) {
    return Column(children: [
      Expanded(child: _IntroBody(rule: rule)),
      // The one action stays on screen however long the instructions are.
      Padding(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
        child: NeonButton(label: 'Start camera', icon: Icons.videocam_outlined, size: NeonButtonSize.large, expand: true, onPressed: onStart),
      ),
    ]);
  }
}

class _IntroBody extends StatelessWidget {
  const _IntroBody({required this.rule});

  final PostureRule rule;

  @override
  Widget build(BuildContext context) {
    final g = rule.guidance;
    return ListView(padding: const EdgeInsets.fromLTRB(20, 12, 20, 12), children: [
      Wrap(spacing: 6, runSpacing: 6, children: [
        StatusChip(rule.exercise.viewLabel, icon: Icons.videocam_outlined),
        StatusChip(rule.exercise.tierLabel, tone: rule.tier == 'full' ? Tone.good : Tone.neutral),
        if (rule.timed) const StatusChip('Timed hold', icon: Icons.timer_outlined),
      ]),
      const SizedBox(height: 14),
      GlassCard(
        neon: true,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(g.title.toUpperCase(), style: IpText.eyebrow(color: IpColors.neon)),
          const SizedBox(height: 10),
          for (var i = 0; i < g.steps.length; i++)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                SizedBox(width: 24, child: Text('${i + 1}', style: IpText.number(16, color: IpColors.neon))),
                Expanded(child: Text(g.steps[i], style: IpText.body(13.5, color: IpColors.ink, height: 1.4))),
              ]),
            ),
          if (g.note.isNotEmpty) Text(g.note, style: IpText.body(12.5, color: IpColors.muted, height: 1.4)),
        ]),
      ),
      if (g.tracks.isNotEmpty) ...[
        const SizedBox(height: 14),
        GlassCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('WHAT IT WATCHES', style: IpText.eyebrow(color: IpColors.neon)),
            const SizedBox(height: 10),
            Wrap(spacing: 6, runSpacing: 6, children: [for (final t in g.tracks) StatusChip(t)]),
          ]),
        ),
      ],
      const SizedBox(height: 14),
      GlassCard(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('HOW MUCH TO TRUST IT', style: IpText.eyebrow(color: IpColors.warn)),
          const SizedBox(height: 8),
          if (g.accuracy.isNotEmpty) Text(g.accuracy, style: IpText.body(13, color: IpColors.muted, height: 1.45)),
          const SizedBox(height: 8),
          Text(
            'A single phone camera sees in 2D and cannot judge depth or rotation, so treat this as a second pair of eyes, not a coach. Stop if something hurts.',
            style: IpText.body(13, color: IpColors.muted, height: 1.45),
          ),
        ]),
      ),
      const SizedBox(height: 14),
      GlassCard(
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Icon(Icons.lock_outline, size: 18, color: IpColors.neon),
          const SizedBox(width: 10),
          Expanded(child: Text('Camera pictures are analysed on your phone and are never recorded, stored or sent. Only the counts and scores of a finished set are saved.', style: IpText.body(13, color: IpColors.muted, height: 1.45))),
        ]),
      ),
    ]);
  }
}

class _Loading extends StatelessWidget {
  const _Loading();

  @override
  Widget build(BuildContext context) => const Center(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          CircularProgressIndicator(color: IpColors.neon),
          SizedBox(height: 16),
          Text('Starting the camera…'),
        ]),
      );
}

class _Problem extends StatelessWidget {
  const _Problem({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => EmptyState(icon: Icons.videocam_off_outlined, title: 'The camera did not start', message: message, actionLabel: 'Try again', onAction: onRetry);
}

// ---------------------------------------------------------------------------------------------------- live

class _Live extends StatelessWidget {
  const _Live({required this.coach});

  final PostureCoach coach;

  @override
  Widget build(BuildContext context) {
    final live = coach.phase == CoachPhase.live;
    return Column(children: [
      Expanded(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 4, 12, 8),
          child: Center(
            child: AspectRatio(
              aspectRatio: coach.aspect,
              child: ClipRRect(
                borderRadius: BorderRadius.circular(IpRadius.card),
                child: Stack(fit: StackFit.expand, children: [
                  const ColoredBox(color: Colors.black),
                  Center(child: coach.preview(context) ?? const SizedBox.shrink()),
                  RepaintBoundary(child: CustomPaint(painter: SkeletonPainter(coach.overlay, minVisibility: coach.rule.compiled.minVisibility))),
                  if (coach.canSwitch)
                    Positioned(
                      top: 6,
                      right: 6,
                      child: IconButton.filled(
                        tooltip: 'Switch camera',
                        style: IconButton.styleFrom(backgroundColor: const Color.fromRGBO(4, 16, 6, 0.7)),
                        onPressed: coach.switchCamera,
                        icon: const Icon(Icons.cameraswitch_outlined, color: IpColors.ink),
                      ),
                    ),
                  // Diagnostics for the first real-device runs; not shown in release builds.
                  if (kDebugMode) Positioned(left: 8, bottom: 6, child: Text('${coach.fps.round()} fps', style: IpText.body(11, color: IpColors.muted))),
                  if (coach.cue != null) Positioned(left: 10, right: 10, top: 10, child: _CueBanner(cue: coach.cue!)),
                  if (coach.phase == CoachPhase.countdown) Center(child: Text('${coach.count}', semanticsLabel: 'Starting in ${coach.count}', style: IpText.display(96, weight: FontWeight.w900, color: IpColors.neon))),
                  if (coach.paused) Center(child: Container(padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 10), decoration: BoxDecoration(color: const Color.fromRGBO(4, 16, 6, 0.8), borderRadius: BorderRadius.circular(12)), child: Text('PAUSED', style: IpText.display(18, color: IpColors.ink)))),
                ]),
              ),
            ),
          ),
        ),
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
        child: live ? _Hud(coach: coach) : _PositionPanel(coach: coach),
      ),
    ]);
  }
}

class _CueBanner extends StatelessWidget {
  const _CueBanner({required this.cue});

  final Cue cue;

  @override
  Widget build(BuildContext context) {
    final tone = switch (cue.tone) { CueTone.ok => Tone.good, CueTone.warn => Tone.warn, CueTone.bad => Tone.bad };
    return Semantics(
      liveRegion: true,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        decoration: BoxDecoration(color: const Color.fromRGBO(4, 16, 6, 0.86), border: Border.all(color: tone.border), borderRadius: BorderRadius.circular(IpRadius.control)),
        child: Text(cue.text, textAlign: TextAlign.center, style: IpText.body(15, weight: FontWeight.w600, color: tone.color)),
      ),
    );
  }
}

class _PositionPanel extends StatelessWidget {
  const _PositionPanel({required this.coach});

  final PostureCoach coach;

  @override
  Widget build(BuildContext context) {
    final counting = coach.phase == CoachPhase.countdown;
    final needsAttention = coach.status == 'no_person' || coach.status == 'adjust';
    return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      MessageBanner(
        counting ? 'Get ready. Hold your starting position.' : (coach.statusMessage.isEmpty ? 'Looking for you…' : coach.statusMessage),
        tone: counting
            ? Tone.good
            : needsAttention
                ? Tone.warn
                : Tone.neutral,
      ),
      if (!counting && coach.positionSeconds >= 6) ...[
        const SizedBox(height: 10),
        NeonButton(label: 'Start anyway', variant: NeonButtonVariant.ghost, expand: true, onPressed: coach.startNow),
      ],
    ]);
  }
}

class _Hud extends StatelessWidget {
  const _Hud({required this.coach});

  final PostureCoach coach;

  static String _clock(int s) => '${s ~/ 60}:${(s % 60).toString().padLeft(2, '0')}';

  static String _phase(String p) => switch (p) { 'out' => 'Moving', 'peak' => 'At the peak', 'back' => 'Returning', 'hold' => 'Holding', _ => 'Ready' };

  @override
  Widget build(BuildContext context) {
    final timed = coach.timed;
    final scoreTone = coach.score >= 75 ? Tone.good : (coach.score >= 50 ? Tone.warn : Tone.bad);
    return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Row(children: [
        _Figure(label: timed ? 'HOLD' : 'REPS', value: timed ? '${coach.holdSeconds.toStringAsFixed(1)}s' : '${coach.reps}', tone: Tone.good, big: true),
        _Figure(label: timed ? 'HOLDS' : 'GOOD', value: timed ? '${coach.reps}' : '${coach.good}', tone: Tone.good),
        _Figure(label: 'NEEDS WORK', value: '${coach.bad}', tone: coach.bad > 0 ? Tone.bad : Tone.neutral),
        _Figure(label: 'FORM', value: '${coach.score}', tone: scoreTone),
      ]),
      const SizedBox(height: 10),
      Row(children: [
        StatusChip(_phase(coach.repPhase), tone: coach.repPhase == 'rest' ? Tone.neutral : Tone.good),
        const SizedBox(width: 8),
        StatusChip(_clock(coach.elapsed), icon: Icons.timer_outlined),
        const SizedBox(width: 12),
        if (!timed) Expanded(child: NeonProgress(value: coach.progress, semanticLabel: 'Range of this rep')) else const Spacer(),
      ]),
      const SizedBox(height: 12),
      Row(children: [
        Expanded(child: NeonButton(label: coach.paused ? 'Resume' : 'Pause', icon: coach.paused ? Icons.play_arrow : Icons.pause, variant: NeonButtonVariant.ghost, onPressed: coach.paused ? coach.resume : coach.pause)),
        const SizedBox(width: 10),
        Expanded(child: NeonButton(label: 'Finish', icon: Icons.flag_outlined, onPressed: coach.finish)),
      ]),
    ]);
  }
}

class _Figure extends StatelessWidget {
  const _Figure({required this.label, required this.value, required this.tone, this.big = false});

  final String label;
  final String value;
  final Tone tone;
  final bool big;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      flex: big ? 3 : 2,
      child: Semantics(
        label: '$label $value',
        excludeSemantics: true,
        child: Column(children: [
          FittedBox(fit: BoxFit.scaleDown, child: Text(value, style: IpText.number(big ? 44 : 28, color: tone.color))),
          FittedBox(fit: BoxFit.scaleDown, child: Text(label, maxLines: 1, style: IpText.body(10.5, weight: FontWeight.w600, color: IpColors.muted))),
        ]),
      ),
    );
  }
}

// ---------------------------------------------------------------------------------------------------- summary

class _Summary extends StatelessWidget {
  const _Summary({required this.coach});

  final PostureCoach coach;

  @override
  Widget build(BuildContext context) {
    final s = coach.summary!;
    final rule = coach.rule;
    final errors = s.errorCounts.entries.toList()..sort((a, b) => b.value.compareTo(a.value));
    final timed = rule.timed;
    return ListView(padding: const EdgeInsets.fromLTRB(20, 12, 20, 28), children: [
      GlassCard(
        neon: true,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('SET COMPLETE', style: IpText.eyebrow(color: IpColors.neon)),
          const SizedBox(height: 12),
          Row(children: [
            _Figure(label: timed ? 'HOLD' : 'REPS', value: timed ? '${s.holdSeconds ?? 0}s' : '${s.totalReps}', tone: Tone.good, big: true),
            _Figure(label: timed ? 'HOLDS' : 'GOOD', value: timed ? '${s.totalReps}' : '${s.goodReps}', tone: Tone.good),
            _Figure(label: 'NEEDS WORK', value: '${s.badReps}', tone: s.badReps > 0 ? Tone.bad : Tone.neutral),
          ]),
          const SizedBox(height: 14),
          Text('Form score ${s.formScoreAvg} (lowest ${s.formScoreMin})', style: IpText.body(14, weight: FontWeight.w600, color: IpColors.ink)),
          const SizedBox(height: 8),
          NeonProgress(value: s.formScoreAvg / 100, semanticLabel: 'Average form score'),
        ]),
      ),
      const SizedBox(height: 14),
      if (errors.isNotEmpty)
        GlassCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('WHAT TO WORK ON', style: IpText.eyebrow(color: IpColors.warn)),
            const SizedBox(height: 10),
            for (final e in errors)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Expanded(child: Text(rule.errorLabel(e.key), style: IpText.body(14.5, weight: FontWeight.w600, color: IpColors.ink))),
                    StatusChip('${e.value}×', tone: Tone.warn),
                  ]),
                  if (rule.errorAdvice(e.key).isNotEmpty) Text(rule.errorAdvice(e.key), style: IpText.body(13, color: IpColors.muted, height: 1.4)),
                ]),
              ),
          ]),
        )
      else if (s.hasResult)
        GlassCard(child: Row(children: [const Icon(Icons.check_circle_outline, color: IpColors.neon), const SizedBox(width: 10), Expanded(child: Text('No form mistakes were flagged.', style: IpText.body(14, color: IpColors.ink)))])),
      const SizedBox(height: 14),
      _SaveStatus(coach: coach),
      const SizedBox(height: 16),
      NeonButton(label: 'Another set', icon: Icons.replay, size: NeonButtonSize.large, expand: true, onPressed: coach.again),
      const SizedBox(height: 10),
      NeonButton(label: 'Done', variant: NeonButtonVariant.ghost, expand: true, onPressed: () => context.pop()),
    ]);
  }
}

class _SaveStatus extends StatelessWidget {
  const _SaveStatus({required this.coach});

  final PostureCoach coach;

  @override
  Widget build(BuildContext context) {
    switch (coach.saveState) {
      case SaveState.idle:
        return const SizedBox.shrink();
      case SaveState.saving:
        return const Row(children: [SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: IpColors.neon)), SizedBox(width: 10), Text('Saving…')]);
      case SaveState.empty:
        return const MessageBanner('Nothing was counted, so nothing was saved.', tone: Tone.warn);
      case SaveState.failed:
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          MessageBanner(coach.saveError, tone: Tone.warn),
          const SizedBox(height: 10),
          NeonButton(label: 'Try saving again', variant: NeonButtonVariant.ghost, expand: true, onPressed: coach.save),
        ]);
      case SaveState.saved:
        final r = coach.saved;
        return GlassCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              const Icon(Icons.cloud_done_outlined, color: IpColors.neon, size: 20),
              const SizedBox(width: 10),
              Expanded(child: Text(r != null && r.xpEarned > 0 ? 'Saved. +${r.xpEarned} XP · Level ${r.level}' : 'Saved to your history.', style: IpText.body(14.5, weight: FontWeight.w600, color: IpColors.ink))),
            ]),
            if (r != null && r.badges.isNotEmpty) ...[
              const SizedBox(height: 10),
              Wrap(spacing: 6, runSpacing: 6, children: [for (final b in r.badges) StatusChip('Badge: $b', tone: Tone.good, icon: Icons.military_tech_outlined)]),
            ],
          ]),
        );
    }
  }
}
