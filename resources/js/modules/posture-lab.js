/**
 * Posture Lab page controller (Alpine component).
 *
 *   intro -> loading -> position -> countdown -> live -> summary
 *                \-> error (camera blocked, model missing, ...)
 *
 * Heavy handles (camera stream, MediaPipe, analyzer) live in closure variables, not in Alpine state,
 * so Alpine never wraps them in proxies and 30 fps frames do not trigger reactivity.
 */

import * as audio from './audio.js';
import { PostureAnalyzer } from './posture-analyzer.js';
import { drawGuide, drawSkeleton, fitCanvas } from './posture-overlay.js';
import {
    PROBLEMS, PoseError, PoseRunner, cameraUnavailableReason, loadLandmarker, openCamera,
} from './pose-engine.js';
import { VoiceCoach } from './voice-coach.js';

const READY_FRAMES = 24;          // ~0.8 s of a steady "ready" pose before the countdown starts
const HUD_INTERVAL_MS = 80;       // the HUD updates ~12x per second, the skeleton every frame
const CUE_SHOW_MS = 2600;

const JOINT_WORDS = {
    shoulder: 'shoulders', elbow: 'elbows', wrist: 'wrists', hip: 'hips', knee: 'knees', ankle: 'ankles', ear: 'ears', heel: 'heels', foot_index: 'feet',
};
const sideWord = (name) => name.replace(/^l_/, 'left ').replace(/^r_/, 'right ').replaceAll('_', ' ');

/** RFC 4122 v4 for browsers without crypto.randomUUID (older Safari over http on a LAN). */
function fallbackUuid() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

const NUMBER_WORDS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty'];

export default function postureLab(config) {
    // ----- non-reactive handles -----
    let video = null;
    let overlay = null;
    let camera = null;
    let landmarker = null;
    let runner = null;
    let analyzer = null;
    let voice = null;
    let resizeObserver = null;
    let lastHud = 0;
    let readyFrames = 0;
    let cueTimer = null;
    let countdownTimer = null;
    let liveStartedAt = 0;      // performance.now() when the set began
    let liveStartedIso = null;
    let pausedAt = 0;
    let pausedTotal = 0;
    let lastLandmarks = null;
    let lastFrameSize = { width: 4, height: 3 };
    let lastResult = null;
    let positionTimer = null;
    let clientUuid = null;

    const rule = config.rule;
    const timed = !!config.exercise.timed;
    const stored = (key, fallback) => window.safeStorage?.get(`ip.posture.${key}`, fallback) ?? fallback;
    const storeSet = (key, value) => window.safeStorage?.set(`ip.posture.${key}`, value);

    return {
        config,
        phase: 'intro',
        problem: null,
        loadingText: '',
        status: 'no_person',
        statusMessage: '',
        count: 3,
        reps: 0,
        good: 0,
        bad: 0,
        holdSeconds: 0,
        score: 100,
        progress: 0,
        repPhase: 'rest',
        cue: null,
        lastRep: null,
        elapsed: 0,
        fps: 0,
        delegate: '',
        paused: false,
        positionSeconds: 0,
        summary: null,
        saveState: 'idle',
        saveError: '',
        xp: null,
        level: null,
        // preferences
        voiceOn: stored('voice', config.prefs.voice ? '1' : '0') === '1',
        soundOn: stored('sound', config.prefs.sound ? '1' : '0') === '1',
        skeletonOn: stored('skeleton', '1') === '1',
        facing: stored('facing', 'user'),
        mirror: stored('mirror', '1') === '1',

        // ------------------------------------------------------------------------------------
        init() {
            video = this.$refs.video;
            overlay = this.$refs.overlay;
            voice = new VoiceCoach(this.voiceOn);
            audio.setSoundEnabled(this.soundOn);

            resizeObserver = new ResizeObserver(() => this.redraw());
            resizeObserver.observe(overlay);

            window.addEventListener('pagehide', () => this.teardown());
            document.addEventListener('visibilitychange', () => {
                if (document.hidden && this.phase === 'live' && !this.paused) this.pause();
            });

            if (!config.assets.ready) this.problem = { code: 'assets', message: PROBLEMS.assets };
            const blocked = cameraUnavailableReason();
            if (blocked) this.problem = { code: blocked, message: PROBLEMS[blocked] };
        },

        destroy() {
            this.teardown();
        },

        get canStart() {
            return !this.problem || !['assets', 'insecure', 'unsupported'].includes(this.problem.code);
        },

        get isTimed() {
            return timed;
        },

        // ------------------------------------------------------------------------------------
        // Starting
        // ------------------------------------------------------------------------------------

        async start() {
            if (!this.canStart) return;
            audio.unlock();
            this.problem = null;
            this.phase = 'loading';
            this.loadingText = 'Starting the camera…';

            try {
                // Camera first: the permission prompt is the slow, user-driven step. Model loads in parallel.
                const cameraPromise = openCamera(video, { facing: this.facing });
                const modelPromise = landmarker
                    ? Promise.resolve({ landmarker, delegate: this.delegate })
                    : loadLandmarker({ wasm: config.assets.wasm, model: config.assets.model, onStatus: (t) => { this.loadingText = t; } });
                const [cam, model] = await Promise.all([cameraPromise, modelPromise]);

                camera = cam;
                landmarker = model.landmarker;
                this.delegate = model.delegate;
                // Selfie view for the front camera feels natural; a back camera should not be mirrored.
                if (camera.facing === 'environment') this.mirror = false;

                lastFrameSize = { width: camera.width || 4, height: camera.height || 3 };
                this.$refs.stage.style.aspectRatio = `${lastFrameSize.width} / ${lastFrameSize.height}`;

                analyzer = new PostureAnalyzer(rule.config, { aspect: lastFrameSize.width / lastFrameSize.height });
                runner = new PoseRunner(video, landmarker, (frame) => this.onFrame(frame));
                readyFrames = 0;
                this.resetHud();
                this.phase = 'position';
                this.positionSeconds = 0;
                this.$nextTick(() => this.$refs.stage.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' }));
                positionTimer = setInterval(() => { if (this.phase === 'position') this.positionSeconds += 1; }, 1000);
                runner.start();
            } catch (error) {
                this.fail(error);
            }
        },

        fail(error) {
            this.teardown({ keepModel: true });
            const code = error instanceof PoseError ? error.code : 'unknown';
            this.problem = { code, message: error instanceof PoseError ? error.message : PROBLEMS.unknown };
            this.phase = 'error';
            if (!(error instanceof PoseError)) console.error('Posture Lab failed to start:', error);
        },

        async switchCamera() {
            this.facing = this.facing === 'user' ? 'environment' : 'user';
            storeSet('facing', this.facing);
            this.mirror = this.facing === 'user';
            if (['position', 'countdown', 'live'].includes(this.phase)) {
                const wasLive = this.phase === 'live';
                runner?.stop();
                camera?.stop();
                try {
                    camera = await openCamera(video, { facing: this.facing });
                    lastFrameSize = { width: camera.width || 4, height: camera.height || 3 };
                    this.$refs.stage.style.aspectRatio = `${lastFrameSize.width} / ${lastFrameSize.height}`;
                    runner.start();
                    if (!wasLive) readyFrames = 0;
                } catch (error) {
                    this.fail(error);
                }
            }
        },

        // ------------------------------------------------------------------------------------
        // Frames
        // ------------------------------------------------------------------------------------

        onFrame({ landmarks, t, width, height }) {
            if (!analyzer) return;
            lastLandmarks = landmarks;
            if (width && height) lastFrameSize = { width, height };
            const aspect = lastFrameSize.width / lastFrameSize.height;

            // While paused the skeleton stays live but nothing is counted.
            const result = this.paused ? lastResult : analyzer.update(landmarks, t, { aspect });
            if (!result) return;
            lastResult = result;

            this.draw(landmarks, result);
            this.handleEvents(result);

            if (this.phase === 'position') this.watchReadiness(result);
            if (this.phase === 'countdown' && ['no_person', 'adjust'].includes(result.status)) this.cancelCountdown();

            if (t - lastHud >= HUD_INTERVAL_MS) {
                lastHud = t;
                this.applyHud(result, t);
            }
        },

        draw(landmarks, result) {
            const { ctx, width, height } = fitCanvas(overlay);
            if (this.phase === 'position' && (!landmarks || result.status !== 'ready')) {
                drawGuide(ctx, { width, height });
                if (landmarks && this.skeletonOn) {
                    drawSkeleton(ctx, landmarks, { width, height, mirror: this.mirror, red: new Set(), minVis: rule.config.min_visibility ?? 0.5 });
                }
                return;
            }
            if (!this.skeletonOn) {
                ctx.clearRect(0, 0, width, height);
                return;
            }
            drawSkeleton(ctx, landmarks, {
                width, height, mirror: this.mirror, red: result.redBones, minVis: rule.config.min_visibility ?? 0.5,
            });
        },

        redraw() {
            if (!overlay || !lastResult) return;
            this.draw(lastLandmarks, lastResult);
        },

        applyHud(result, t) {
            this.status = result.status;
            this.statusMessage = this.messageFor(result);
            this.reps = result.reps;
            this.good = result.good;
            this.bad = result.bad;
            this.score = result.score;
            this.progress = Math.round(Math.min(1, Math.max(0, result.progress)) * 100) / 100;
            this.repPhase = result.phase;
            this.holdSeconds = Math.round(result.holdMs / 100) / 10;
            this.fps = Math.round(runner?.fps ?? 0);
            if (this.phase === 'live') this.elapsed = Math.max(0, Math.round((t - liveStartedAt - pausedTotal) / 1000));
            if (result.lastRep) this.lastRep = result.lastRep;
        },

        messageFor(result) {
            switch (result.status) {
                case 'no_person': return "I can't see you. Step into the frame so your whole body is visible.";
                case 'adjust': {
                    const parts = [...new Set(result.missing.map((m) => (m.match(/^[lr]_/) ? sideWord(m) : (JOINT_WORDS[m] ?? m))))];
                    return `Move so I can see your ${parts.slice(0, 3).join(', ')}. ${rule.camera_view === 'side' ? 'Turn side-on to the camera.' : 'Step back from the camera.'}`;
                }
                case 'positioning': return timed ? 'Get into position and hold still.' : 'Stand in your starting position.';
                case 'ready': return 'Ready. Start your first rep.';
                default: return '';
            }
        },

        // ------------------------------------------------------------------------------------
        // Positioning and countdown
        // ------------------------------------------------------------------------------------

        watchReadiness(result) {
            const ok = timed ? result.status === 'active' : (result.status === 'ready' || result.status === 'active');
            readyFrames = ok ? readyFrames + 1 : 0;
            if (readyFrames >= (timed ? 2 : READY_FRAMES)) this.beginSet(timed);
        },

        /** Skip the wait: start the countdown even if the camera has not seen the start position. */
        startNow() {
            if (this.phase === 'position') this.beginSet(false);
        },

        beginSet(immediate) {
            clearInterval(positionTimer);
            if (immediate) {
                this.goLive(false);
                return;
            }
            this.phase = 'countdown';
            this.count = 3;
            audio.cues.tick();
            voice.say('3', { interrupt: true });
            countdownTimer = setInterval(() => {
                this.count -= 1;
                if (this.count > 0) {
                    audio.cues.tick();
                    voice.say(String(this.count));
                } else {
                    clearInterval(countdownTimer);
                    this.goLive(true);
                }
            }, 1000);
        },

        cancelCountdown() {
            clearInterval(countdownTimer);
            this.phase = 'position';
            readyFrames = 0;
            this.positionSeconds = 0;
            positionTimer = setInterval(() => { if (this.phase === 'position') this.positionSeconds += 1; }, 1000);
        },

        goLive(resetAnalyzer) {
            if (resetAnalyzer) analyzer.reset();
            audio.cues.go();
            voice.say('Go');
            this.phase = 'live';
            this.reps = 0;
            this.good = 0;
            this.bad = 0;
            this.lastRep = null;
            this.elapsed = 0;
            this.paused = false;
            pausedTotal = 0;
            liveStartedAt = performance.now();
            liveStartedIso = new Date().toISOString();
        },

        // ------------------------------------------------------------------------------------
        // Live set
        // ------------------------------------------------------------------------------------

        handleEvents(result) {
            if (this.phase !== 'live') return;
            for (const e of result.events) {
                if (e.type === 'rep') {
                    if (e.good) {
                        this.showCue(e.hold ? `Solid hold, ${Math.round(e.ms / 1000)} s` : `Rep ${e.reps}: good`, 'ok');
                        audio.cues.setDone();
                        voice.say(e.hold ? 'Nice hold' : (NUMBER_WORDS[e.reps] ?? String(e.reps)), { interrupt: true });
                    } else {
                        this.showCue(e.feedback, 'bad');
                        audio.cues.error();
                        voice.say(e.feedback, { interrupt: true });
                    }
                } else if (e.type === 'cue') {
                    this.showCue(e.message, e.severity === 'major' ? 'bad' : 'warn');
                    if (e.severity === 'major') audio.cues.error();
                    voice.say(e.message, { interrupt: false });
                } else if (e.type === 'abort') {
                    this.showCue('Lost sight of you, that rep was not counted.', 'warn');
                } else if (e.type === 'hold_short') {
                    this.showCue('Hold for at least a few seconds to count.', 'warn');
                }
            }
        },

        showCue(text, tone) {
            this.cue = { text, tone, at: Date.now() };
            clearTimeout(cueTimer);
            cueTimer = setTimeout(() => { this.cue = null; }, CUE_SHOW_MS);
        },

        pause() {
            if (this.phase !== 'live' || this.paused) return;
            this.paused = true;
            pausedAt = performance.now();
        },

        resume() {
            if (!this.paused) return;
            pausedTotal += performance.now() - pausedAt;
            this.paused = false;
        },

        /** Throw away this set and get back into position. */
        restart() {
            analyzer?.reset();
            this.resetHud();
            this.phase = 'position';
            readyFrames = 0;
            this.positionSeconds = 0;
            clearInterval(positionTimer);
            positionTimer = setInterval(() => { if (this.phase === 'position') this.positionSeconds += 1; }, 1000);
            if (!runner?.running) runner?.start();
        },

        async finish() {
            if (this.phase !== 'live') return;
            const now = performance.now();
            if (this.paused) pausedTotal += now - pausedAt;
            analyzer.flush(now);
            const seconds = (now - liveStartedAt - pausedTotal) / 1000;
            this.summary = analyzer.summary(seconds);
            this.paused = false;

            runner?.stop();
            camera?.stop();
            camera = null;
            clearTimeout(cueTimer);
            this.cue = null;
            this.phase = 'summary';
            voice.cancel();
            audio.cues.fanfare();

            await this.save();
        },

        get hasResult() {
            return !!this.summary && (this.summary.total_reps > 0 || (this.summary.hold_seconds ?? 0) > 0);
        },

        async save() {
            if (!this.hasResult) {
                this.saveState = 'empty';
                return;
            }
            this.saveState = 'saving';
            this.saveError = '';
            clientUuid ??= crypto.randomUUID?.() ?? fallbackUuid();
            const payload = {
                client_uuid: clientUuid,
                exercise_id: config.exercise.id,
                posture_rule_id: rule.id,
                workout_session_id: config.workoutSessionId ?? null,
                rule_version: rule.version,
                camera_view: rule.camera_view,
                started_at: liveStartedIso,
                avg_fps: runner ? Math.round(runner.fps * 10) / 10 : null,
                model_variant: config.assets.variant,
                ...this.summary,
                duration_seconds: Math.max(1, this.summary.duration_seconds),
            };
            try {
                const res = await window.api(config.urls.save, { method: 'POST', body: payload });
                this.xp = res.xp;
                this.level = res.level;
                this.saveState = 'saved';
            } catch (error) {
                this.saveState = 'failed';
                this.saveError = error.status === 422
                    ? 'The server rejected this set. It is shown here but was not saved.'
                    : 'Could not reach the server. Your set is shown here; try saving again.';
            }
        },

        again() {
            this.summary = null;
            clientUuid = null;
            this.saveState = 'idle';
            this.xp = null;
            this.start();
        },

        // ------------------------------------------------------------------------------------
        // Preferences
        // ------------------------------------------------------------------------------------

        toggleVoice() {
            this.voiceOn = !this.voiceOn;
            voice.setEnabled(this.voiceOn);
            storeSet('voice', this.voiceOn ? '1' : '0');
        },

        toggleSound() {
            this.soundOn = !this.soundOn;
            audio.setSoundEnabled(this.soundOn);
            storeSet('sound', this.soundOn ? '1' : '0');
        },

        toggleSkeleton() {
            this.skeletonOn = !this.skeletonOn;
            storeSet('skeleton', this.skeletonOn ? '1' : '0');
            this.redraw();
        },

        toggleMirror() {
            this.mirror = !this.mirror;
            storeSet('mirror', this.mirror ? '1' : '0');
            this.redraw();
        },

        async toggleFullscreen() {
            const el = this.$refs.stage;
            try {
                if (document.fullscreenElement) await document.exitFullscreen();
                else await el.requestFullscreen?.();
            } catch { /* not allowed or unsupported: ignore */ }
        },

        // ------------------------------------------------------------------------------------
        // Helpers
        // ------------------------------------------------------------------------------------

        resetHud() {
            this.status = 'no_person';
            this.statusMessage = '';
            this.reps = 0;
            this.good = 0;
            this.bad = 0;
            this.score = 100;
            this.progress = 0;
            this.repPhase = 'rest';
            this.cue = null;
            this.lastRep = null;
            this.elapsed = 0;
            this.holdSeconds = 0;
            lastResult = null;
        },

        /** Labels for the summary's error list, taken from the rule itself. */
        errorLabel(id) {
            if (id === 'range') return 'Incomplete range';
            if (id === 'tempo') return 'Too fast';
            return rule.config.checks?.find((c) => c.id === id)?.label ?? id;
        },

        errorAdvice(id) {
            if (id === 'range') return rule.config.rep.partial_message ?? 'Go through the full range of motion.';
            if (id === 'tempo') return 'Slow down and control the weight.';
            return rule.config.checks?.find((c) => c.id === id)?.message ?? '';
        },

        get errorList() {
            if (!this.summary) return [];
            return Object.entries(this.summary.error_counts).sort((a, b) => b[1] - a[1]).map(([id, n]) => ({ id, n, label: this.errorLabel(id), advice: this.errorAdvice(id) }));
        },

        get phaseLabel() {
            return { rest: 'Ready', out: 'Moving', peak: 'At the peak', back: 'Returning', hold: 'Holding' }[this.repPhase] ?? '';
        },

        get clock() {
            const m = Math.floor(this.elapsed / 60);
            return `${m}:${String(this.elapsed % 60).padStart(2, '0')}`;
        },

        teardown({ keepModel = false } = {}) {
            clearInterval(positionTimer);
            clearInterval(countdownTimer);
            clearTimeout(cueTimer);
            runner?.stop();
            camera?.stop();
            camera = null;
            if (!keepModel) {
                runner?.close();
                landmarker = null;
            }
            runner = null;
            voice?.cancel();
            resizeObserver?.disconnect();
        },
    };
}
