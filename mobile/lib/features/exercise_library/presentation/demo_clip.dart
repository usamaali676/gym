import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:video_player/video_player.dart';

import '../../../app/theme/tokens.g.dart';
import '../../../app/theme/typography.dart';
import '../../../core/widgets/neon_button.dart';
import '../../../core/widgets/neon_segments.dart';
import '../domain/exercise.dart';

/// What the clip widget needs from a video player. The real one wraps `video_player`; tests replace it, because a
/// desktop test host has no video decoder.
abstract class ClipPlayer {
  Future<void> initialize();
  Future<void> play();
  Future<void> pause();
  bool get isPlaying;
  double get aspectRatio;
  Widget view();
  Future<void> dispose();
}

typedef ClipPlayerFactory = ClipPlayer Function(String url);

/// NOT run on a device in this repository's CI: it is the thin layer over the platform video decoder. The clip is muted
/// (it has no sound track) and loops.
class PlatformClipPlayer implements ClipPlayer {
  PlatformClipPlayer(String url) : _controller = VideoPlayerController.networkUrl(Uri.parse(url), videoPlayerOptions: VideoPlayerOptions(mixWithOthers: true));

  final VideoPlayerController _controller;

  @override
  Future<void> initialize() async {
    await _controller.initialize();
    await _controller.setLooping(true);
    await _controller.setVolume(0);
  }

  @override
  Future<void> play() => _controller.play();

  @override
  Future<void> pause() => _controller.pause();

  @override
  bool get isPlaying => _controller.value.isPlaying;

  @override
  double get aspectRatio => _controller.value.aspectRatio > 0 ? _controller.value.aspectRatio : 1;

  @override
  Widget view() => VideoPlayer(_controller);

  @override
  Future<void> dispose() => _controller.dispose();
}

final clipPlayerFactoryProvider = Provider<ClipPlayerFactory>((ref) => PlatformClipPlayer.new);

/// The server's rendered demonstration clips of one exercise, if it has any. The member sees a loop of the same
/// animated figure the website shows (a rendered clip, not captured footage of a person, and not a live 3D model).
/// With no published clip the widget draws nothing at all: no placeholder, no pretend video.
class DemoClips extends ConsumerStatefulWidget {
  const DemoClips({super.key, required this.media, required this.exerciseName});

  final List<ExerciseMedia> media;
  final String exerciseName;

  /// The clips the app can play, one per camera view, in the order angle, side, front.
  static List<ExerciseMedia> playable(List<ExerciseMedia> media) {
    const order = ['angle', 'side', 'front'];
    final byView = <String, ExerciseMedia>{};
    for (final m in media) {
      if (m.kind != 'demo_video' || m.url.isEmpty || !(m.mime ?? 'video/mp4').startsWith('video/')) continue;
      byView.putIfAbsent(m.view ?? 'angle', () => m);
    }
    int rank(String v) => order.contains(v) ? order.indexOf(v) : order.length;
    final keys = byView.keys.toList()..sort((a, b) => rank(a).compareTo(rank(b)));
    return [for (final k in keys) byView[k]!];
  }

  @override
  ConsumerState<DemoClips> createState() => _DemoClipsState();
}

enum _Phase { poster, loading, playing, paused, failed }

class _DemoClipsState extends ConsumerState<DemoClips> with WidgetsBindingObserver {
  static const _viewLabels = {'angle': '45°', 'side': 'Side', 'front': 'Front'};

  late List<ExerciseMedia> _clips = DemoClips.playable(widget.media);
  int _view = 0;
  ClipPlayer? _player;
  _Phase _phase = _Phase.poster;
  bool _started = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // Members who ask the system for less motion get the still picture and press play themselves.
    if (!_started && _clips.isNotEmpty && !MediaQuery.disableAnimationsOf(context)) {
      _started = true;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) unawaited(_open());
      });
    }
  }

  @override
  void didUpdateWidget(DemoClips old) {
    super.didUpdateWidget(old);
    if (old.media != widget.media) {
      _clips = DemoClips.playable(widget.media);
      _view = _view.clamp(0, _clips.isEmpty ? 0 : _clips.length - 1);
      unawaited(_open());
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) {
      if (_phase == _Phase.playing) {
        unawaited(_player?.pause());
        setState(() => _phase = _Phase.paused);
      }
    }
  }

  Future<void> _open() async {
    if (_clips.isEmpty) return;
    final previous = _player;
    _player = null;
    unawaited(previous?.dispose());
    final player = ref.read(clipPlayerFactoryProvider)(_clips[_view].url);
    _player = player;
    setState(() => _phase = _Phase.loading);
    try {
      await player.initialize();
      if (!mounted || _player != player) return;
      await player.play();
      if (!mounted || _player != player) return;
      setState(() => _phase = _Phase.playing);
    } catch (_) {
      if (!mounted || _player != player) return;
      setState(() => _phase = _Phase.failed);
    }
  }

  Future<void> _toggle() async {
    final p = _player;
    if (p == null) return;
    if (_phase == _Phase.playing) {
      await p.pause();
      if (mounted) setState(() => _phase = _Phase.paused);
    } else if (_phase == _Phase.paused) {
      await p.play();
      if (mounted) setState(() => _phase = _Phase.playing);
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    unawaited(_player?.dispose());
    super.dispose();
  }

  String? _poster(ExerciseMedia clip) {
    for (final m in widget.media) {
      if (m.kind == 'thumbnail' && m.view == clip.view && m.url.isNotEmpty) return m.url;
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    if (_clips.isEmpty) return const SizedBox.shrink();
    final clip = _clips[_view.clamp(0, _clips.length - 1)];
    final poster = _poster(clip);
    final ready = (_phase == _Phase.playing || _phase == _Phase.paused) && _player != null;

    final picture = ready
        ? AspectRatio(aspectRatio: _player!.aspectRatio, child: _player!.view())
        : AspectRatio(
            aspectRatio: 1,
            child: poster == null ? const ColoredBox(color: IpColors.bg1) : Image.network(poster, fit: BoxFit.cover, errorBuilder: (_, _, _) => const ColoredBox(color: IpColors.bg1), excludeFromSemantics: true),
          );

    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Semantics(
        label: switch (_phase) {
          _Phase.playing => 'Demonstration of ${widget.exerciseName}. Playing. Double tap to pause.',
          _Phase.paused => 'Demonstration of ${widget.exerciseName}. Paused. Double tap to play.',
          _Phase.failed => 'Demonstration of ${widget.exerciseName} could not be loaded.',
          _Phase.loading => 'Demonstration of ${widget.exerciseName} is loading.',
          _Phase.poster => 'Demonstration of ${widget.exerciseName}. Double tap to play.',
        },
        image: true,
        onTap: _phase == _Phase.failed || _phase == _Phase.loading ? null : (_phase == _Phase.poster ? _open : _toggle),
        excludeSemantics: true,
        child: GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTap: _phase == _Phase.poster ? _open : _toggle,
          child: ClipRRect(
            borderRadius: BorderRadius.circular(16),
            child: Container(
              decoration: BoxDecoration(border: Border.all(color: IpColors.line), borderRadius: BorderRadius.circular(16)),
              child: Stack(alignment: Alignment.center, children: [
                picture,
                if (_phase == _Phase.loading) const SizedBox(width: 28, height: 28, child: CircularProgressIndicator(strokeWidth: 2.5, color: IpColors.neon)),
                if (_phase == _Phase.paused || _phase == _Phase.poster) const Icon(Icons.play_circle_outline, size: 56, color: IpColors.ink),
                if (_phase == _Phase.failed)
                  Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      Text('The video could not be loaded. Check your connection.', textAlign: TextAlign.center, style: IpText.body(13.5, weight: FontWeight.w600, color: IpColors.ink)),
                      const SizedBox(height: 10),
                      NeonButton(label: 'Try again', icon: Icons.refresh, size: NeonButtonSize.small, onPressed: _open),
                    ]),
                  ),
              ]),
            ),
          ),
        ),
      ),
      const SizedBox(height: 8),
      Text('Animated demonstration (rendered figure, not a recording of a person).', textAlign: TextAlign.center, style: IpText.body(11.5, color: IpColors.muted)),
      if (_clips.length > 1) ...[
        const SizedBox(height: 10),
        NeonSegments(
          index: _view,
          labels: [for (final c in _clips) _viewLabels[c.view ?? 'angle'] ?? (c.view ?? 'angle')],
          onChanged: (i) {
            if (i == _view) return;
            setState(() => _view = i);
            unawaited(_open());
          },
        ),
      ],
    ]);
  }
}
