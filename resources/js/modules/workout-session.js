/**
 * The guided workout ("game mode"): an Alpine component.
 *
 * Design notes
 *  - Time is derived from timestamps (see timer.js), so pausing, switching tabs or reloading never loses it.
 *  - Every set is POSTed immediately and idempotently. If the network is down, sets wait in a local
 *    queue (also persisted) and are replayed in order when the connection returns.
 *  - Position and timers autosave to localStorage on every change and (debounced) to the server, so a
 *    refresh, a crash or a second device resumes exactly where you were.
 *  - Heavy or non-serialisable objects (the Three.js viewer, the metronome) live in closure variables,
 *    NOT in reactive state, so Alpine never wraps them in proxies.
 */
import { Metronome, cues, setSoundEnabled, unlock } from './audio.js';
import { Countdown, SessionClock, formatClock, formatCountdown, startTicker } from './timer.js';
import { VoiceCoach } from './voice-coach.js';
import * as logic from './workout-logic.js';

const storeKey = (id) => `ip.session.${id}`;
const queueKey = (id) => `ip.queue.${id}`;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const readJson = (key) => {
    try { return JSON.parse(window.localStorage.getItem(key)); } catch { return null; }
};
const writeJson = (key, value) => {
    try {
        if (value === null) window.localStorage.removeItem(key);
        else window.localStorage.setItem(key, JSON.stringify(value));
    } catch { /* storage may be unavailable (private mode); the server copy still works */ }
};

export default function workoutGame(config) {
    // Non-reactive handles.
    let viewer = null;
    let metronome = null;
    let voice = null;
    let stopTicker = null;
    let wakeLock = null;
    let saveTimer = null;
    let flushTimer = null;
    let lastRestTickSecond = null;
    let destroyed = false;

    return {
        cfg: config,
        exercises: config.exercises,

        // position + phase: ready | intro | work | rest | finish
        idx: 0,
        setIdx: 0,
        phase: 'ready',
        introCount: 3,
        weight: null,
        reps: 0,
        suggestedReps: null,
        workStartedAt: null,
        holdMs: 0,
        holdDone: false,
        nextPos: null,
        nextWeight: null,
        prevPos: null,
        flushing: false,
        toastTimer: null,

        clock: null,
        rest: null,
        now: Date.now(),
        paused: false,

        muted: !config.prefs.sound,
        voiceOn: !!config.prefs.voice,
        metro: { on: false, bpm: 60, beat: 0 },
        demo: { cam: 'angle', speed: 1, playing: true, supported: true, ready: false },

        ui: { tab: 'form', quit: false, jump: false, swap: false, skip: false, toastText: '', toastKind: 'info', finishing: false, swapLoading: false, swapError: '', alternatives: [], demoOpen: true },
        queue: [],
        pending: 0,
        online: typeof navigator === 'undefined' ? true : navigator.onLine,
        error: '',
        announce: '',

        // ------------------------------------------------------------------ lifecycle

        init() {
            voice = new VoiceCoach(this.voiceOn);
            setSoundEnabled(!this.muted);
            this.queue = readJson(queueKey(this.cfg.session.id)) || [];
            this.pending = this.queue.length;

            const local = readJson(storeKey(this.cfg.session.id));
            const resume = logic.sanitizeResume(logic.pickNewestState(local, this.cfg.session.resume), this.exercises);

            const open = logic.firstOpenPosition(this.exercises);
            if (resume && open) this.restore(resume);
            else if (open) this.fresh(open);
            else this.phase = 'finish';

            stopTicker = startTicker(() => this.tick());
            this.$nextTick(() => this.mountViewer());
            this.$watch('idx', () => { this.showDemo(); this.ui.tab = 'form'; });

            this.bindEvents();
            if (this.queue.length) this.flush();
        },

        destroy() {
            destroyed = true;
            stopTicker?.();
            viewer?.dispose?.();
            metronome?.stop();
            voice?.cancel();
            clearTimeout(saveTimer);
            clearTimeout(flushTimer);
            wakeLock?.release?.().catch(() => {});
        },

        bindEvents() {
            const onVisible = () => {
                if (document.hidden) this.persist(true);
                else { this.tick(); this.requestWakeLock(); }
            };
            document.addEventListener('visibilitychange', onVisible);
            window.addEventListener('pagehide', () => this.persist(true));
            window.addEventListener('online', () => { this.online = true; this.flush(); });
            window.addEventListener('offline', () => { this.online = false; });
        },

        fresh(open) {
            this.idx = open.idx;
            this.setIdx = open.setIdx;
            this.phase = 'ready';
            this.applyPrefill();
        },

        restore(state) {
            this.idx = state.idx;
            this.setIdx = state.setIdx;
            this.phase = state.phase === 'intro' ? 'ready' : state.phase;
            this.weight = state.weight ?? null;
            this.reps = state.reps ?? 0;
            this.suggestedReps = state.suggestedReps ?? null;
            this.clock = state.clock ? SessionClock.from(state.clock) : null;
            this.rest = Countdown.from(state.rest);
            this.nextPos = state.nextPos ?? null;
            this.nextWeight = state.nextWeight ?? null;
            this.prevPos = state.prevPos ?? null;
            this.paused = !!state.paused;
            if (state.metroBpm) this.metro.bpm = state.metroBpm;

            // A rest that ended while the page was closed is simply over.
            if (this.phase === 'rest' && !this.rest) this.phase = 'work';
            if (this.phase === 'work' && !this.clock) this.phase = 'ready';
            if (this.phase === 'ready') this.applyPrefill();
        },

        // ------------------------------------------------------------------ getters

        get current() { return this.exercises[this.idx]; },
        get nextExercise() { return this.nextPos ? this.exercises[this.nextPos.idx] : null; },
        get sessionLabel() { return formatClock(this.clock ? this.clock.elapsedMs(this.now) : 0); },
        get restLabel() { return this.rest ? formatCountdown(this.rest.remainingMs(this.now)) : '0:00'; },
        get restFraction() { return this.rest ? this.rest.fractionLeft(this.now) : 0; },
        get restRingOffset() { return 2 * Math.PI * 90 * (1 - this.restFraction); },
        get segments() { return logic.progressSegments(this.exercises); },
        get setSlots() { return Array.from({ length: Math.max(this.current.targetSets, this.current.sets.length) }, (_, i) => i); },
        get repChips() { return logic.repChoices(this.current); },
        get lastText() { return logic.describeLast(this.current.last); },
        get totalSetsDone() { return logic.totalSets(this.exercises); },
        get volume() { return Math.round(logic.totalVolume(this.exercises)); },
        get isLastSet() { return this.setIdx + 1 >= this.current.targetSets; },
        get weightLabel() { return this.weight === null || this.weight === undefined ? 'BW' : String(this.weight); },
        get holdLabel() { return formatClock(this.holdMs); },
        get holdTarget() { return (this.current.holdSeconds || 30) * 1000; },
        get nextTitle() {
            if (!this.nextPos) return '';
            const ex = this.exercises[this.nextPos.idx];
            return this.nextPos.idx === this.idx ? `Set ${this.nextPos.setIdx + 1} of ${ex.targetSets}` : ex.name;
        },
        get nextTarget() {
            const ex = this.nextExercise;
            if (!ex) return '';
            const reps = ex.targetReps ? `${ex.targetReps}` : `${ex.repsMin}-${ex.repsMax}`;
            const weight = this.nextWeight === null || this.nextWeight === undefined ? 'Bodyweight' : `${this.nextWeight} kg`;
            return `${weight} × ${reps}`;
        },
        get nextWeightLabel() { return this.nextWeight === null || this.nextWeight === undefined ? 'BW' : String(this.nextWeight); },
        bumpNextWeight(dir) { this.nextWeight = logic.stepWeight(this.nextWeight, dir); },

        // ------------------------------------------------------------------ flow

        /** First tap: unlock audio (browsers require a gesture), then run the 3-2-1-GO countdown. */
        async begin() {
            unlock();
            this.requestWakeLock();
            const alreadyStarted = this.clock !== null;
            if (alreadyStarted || this.totalSetsDone > 0) {
                this.startWork(true);
                voice.say(`Welcome back. ${this.current.name}, set ${this.setIdx + 1}.`);
                return;
            }

            this.phase = 'intro';
            for (const n of [3, 2, 1]) {
                if (destroyed) return;
                this.introCount = n;
                cues.tick();
                voice.say(String(n));
                await sleep(1000);
            }
            this.introCount = 'GO';
            cues.go();
            voice.say('Go!');
            await sleep(750);
            this.startWork(false);
            voice.say(`First up: ${this.current.name}. ${this.current.targetSets} sets of ${this.current.repsMin} to ${this.current.repsMax}.`, { interrupt: false });
        },

        startWork(resumed) {
            this.phase = 'work';
            this.clock ??= new SessionClock({ startedAt: Date.now() });
            if (this.clock.isPaused) this.clock.resume();
            this.paused = false;
            if (!resumed) this.applyPrefill();
            this.persist();
        },

        applyPrefill() {
            const p = logic.prefill(this.current, this.setIdx);
            this.weight = p.weight;
            this.reps = 0;
            this.suggestedReps = p.suggestedReps;
            this.workStartedAt = null;
            this.holdMs = 0;
        },

        enterExercise(idx, setIdx) {
            const changed = idx !== this.idx;
            this.idx = idx;
            this.setIdx = setIdx;
            this.applyPrefill();
            if (changed || setIdx === 0) {
                voice.say(`Next up: ${this.current.name}. ${this.current.targetSets} sets of ${this.current.repsMin} to ${this.current.repsMax}.`, { interrupt: false });
            }
        },

        // ------------------------------------------------------------------ reps & weight

        tapRep() {
            if (this.phase !== 'work' || this.paused) return;
            this.reps = logic.clampReps(this.reps + 1);
            navigator.vibrate?.(8);
            cues.toggle();
        },
        setReps(n) { this.reps = logic.clampReps(n); },
        bumpReps(dir) { this.reps = logic.clampReps(this.reps + dir); },
        bumpWeight(dir) { this.weight = logic.stepWeight(this.weight, dir); },
        clearWeight() { this.weight = null; },

        // Timed holds (planks etc.)
        toggleHold() {
            if (this.workStartedAt === null) {
                this.workStartedAt = Date.now();
                cues.tick();
            } else {
                this.holdMs = Date.now() - this.workStartedAt;
                this.workStartedAt = null;
            }
        },
        get holdRunningMs() { return this.workStartedAt === null ? this.holdMs : Math.max(0, this.now - this.workStartedAt); },
        get holdRunningLabel() { return formatClock(this.holdRunningMs); },
        get holdProgress() { return Math.min(1, this.holdRunningMs / this.holdTarget); },

        // ------------------------------------------------------------------ sets

        async completeSet() {
            if (this.phase !== 'work' || this.paused) return;
            const ex = this.current;
            const timed = ex.isTimed;

            if (timed && this.workStartedAt !== null) this.toggleHold();
            const duration = timed ? Math.round(this.holdMs / 1000) : null;
            const reps = timed ? null : logic.clampReps(this.reps);

            if ((!timed && reps < 1) || (timed && duration < 1)) {
                cues.error();
                this.toast(timed ? 'Start the timer and hold first.' : 'Count at least one rep first.', 'warn');
                return;
            }

            const number = this.setIdx + 1;
            const weight = this.weight === '' || this.weight === undefined ? null : this.weight;
            const payload = { session_exercise_id: ex.id, set_number: number, weight_kg: weight, reps, duration_seconds: duration };
            ex.sets = ex.sets.filter((s) => s.number !== number);
            ex.sets.push({ id: null, number, weight, reps, duration, isPr: false, pending: true });
            ex.sets.sort((a, b) => a.number - b.number);
            cues.setDone();
            navigator.vibrate?.([12, 30, 12]);

            this.enqueue(payload, ex.id, number);

            const next = logic.advance(this.exercises, this.idx, this.setIdx);
            this.prevPos = { idx: this.idx, setIdx: this.setIdx, weight: this.weight, reps: reps ?? 0 };

            if (next.kind === 'finished') {
                ex.status = 'done';
                this.phase = 'finish';
                this.rest = null;
                cues.fanfare();
                voice.say('Workout complete. Great work!');
                this.announce = 'All sets complete.';
            } else {
                if (next.kind === 'next-exercise') ex.status = 'done';
                this.nextPos = { idx: next.idx, setIdx: next.setIdx };
                this.nextWeight = next.idx === this.idx ? this.weight : this.exercises[next.idx].weight ?? null;
                this.startRest(ex);
            }
            this.persist();
        },

        startRest(ex) {
            const seconds = logic.restSecondsFor(ex);
            this.rest = Countdown.fromSeconds(seconds);
            this.phase = 'rest';
            lastRestTickSecond = null;
            this.announce = `Rest ${seconds} seconds.`;
        },

        endRest(auto = false) {
            if (this.phase !== 'rest') return;
            const pos = this.nextPos ?? { idx: this.idx, setIdx: this.setIdx + 1 };
            const chosenWeight = this.nextWeight;
            this.rest = null;
            this.nextPos = null;
            this.phase = 'work';
            this.enterExercise(pos.idx, pos.setIdx);
            this.weight = chosenWeight; // the weight picked during the rest (or the carried-over one)
            if (auto) {
                cues.restEnd();
                voice.say(`Rest over. Set ${this.setIdx + 1}. Go!`);
                this.notifyRestOver();
            }
            this.announce = 'Rest over.';
            this.persist();
        },

        skipRest() { this.endRest(false); },
        addRest(seconds = 15) { this.rest?.extend(seconds * 1000); this.persist(); },

        /** Undo the set that was just logged (mis-tap) and go back to it. */
        async undoLastSet() {
            if (this.phase !== 'rest' || !this.prevPos) return;
            const { idx, setIdx, weight, reps } = this.prevPos;
            const ex = this.exercises[idx];
            const number = setIdx + 1;
            const set = ex.sets.find((s) => s.number === number);
            ex.sets = ex.sets.filter((s) => s.number !== number);
            if (ex.status === 'done') ex.status = 'pending';

            const queued = this.queue.findIndex((q) => q.exId === ex.id && q.number === number);
            if (queued >= 0) { this.queue.splice(queued, 1); this.saveQueue(); }
            else if (set?.id) {
                window.api(`${this.cfg.urls.sets}/${set.id}`, { method: 'DELETE' }).catch(() => this.toast('Could not undo on the server.', 'warn'));
            }

            this.rest = null;
            this.nextPos = null;
            this.nextWeight = null;
            this.idx = idx;
            this.setIdx = setIdx;
            this.phase = 'work';
            this.weight = weight;
            this.reps = reps;
            this.prevPos = null;
            this.persist();
        },

        addExtraSet() {
            const ex = this.current;
            ex.targetSets += 1;
            ex.status = 'pending';
            this.phase = 'work';
            this.setIdx = ex.sets.length;
            this.applyPrefill();
            this.persist();
        },

        // ------------------------------------------------------------------ pause / quit / skip / jump

        togglePause() {
            if (this.phase === 'ready' || this.phase === 'intro' || this.phase === 'finish') return;
            if (!this.paused) {
                this.clock?.pause();
                this.rest?.freeze();
                this.paused = true;
                metronome?.stop();
                this.metro.on = false;
                voice.cancel();
            } else {
                this.clock?.resume();
                this.rest?.thaw();
                this.paused = false;
            }
            this.persist();
        },

        async skipExercise() {
            this.ui.skip = false;
            const ex = this.current;
            ex.status = 'skipped';
            window.api(`${this.cfg.urls.base}/exercises/${ex.id}/skip`, { method: 'POST' }).catch(() => {});
            const next = logic.advance(this.exercises, this.idx, ex.targetSets);
            if (next.kind === 'finished') { this.phase = 'finish'; this.rest = null; }
            else { this.phase = 'work'; this.rest = null; this.nextPos = null; this.enterExercise(next.idx, next.setIdx); }
            this.persist();
        },

        jumpTo(i) {
            const ex = this.exercises[i];
            if (!ex || ex.status === 'skipped' || (this.phase !== 'work' && this.phase !== 'rest')) return;
            this.ui.jump = false;
            this.rest = null;
            this.nextPos = null;
            this.phase = 'work';
            this.enterExercise(i, Math.min(ex.sets.length, ex.targetSets - 1));
            this.persist();
        },

        async saveAndExit() {
            this.persist(true);
            await this.flush();
            window.location.href = this.cfg.urls.dashboard;
        },

        async abandon() {
            try {
                await window.api(this.cfg.urls.abandon, { method: 'POST' });
                writeJson(storeKey(this.cfg.session.id), null);
                writeJson(queueKey(this.cfg.session.id), null);
                window.location.href = this.cfg.urls.plan;
            } catch (e) {
                this.error = e.message || 'Could not abandon the workout.';
            }
        },

        async finishWorkout() {
            if (this.ui.finishing) return;
            this.ui.finishing = true;
            this.error = '';
            await this.flush();

            if (this.queue.length) {
                this.error = 'Waiting for a connection to save your last sets. Keep this page open.';
                this.ui.finishing = false;
                return;
            }
            try {
                const res = await window.api(this.cfg.urls.finish, {
                    method: 'POST',
                    body: { active_seconds: Math.round((this.clock?.elapsedMs() ?? 0) / 1000) },
                });
                writeJson(storeKey(this.cfg.session.id), null);
                writeJson(queueKey(this.cfg.session.id), null);
                window.location.href = res.summary;
            } catch (e) {
                this.error = e.data?.errors?.session?.[0] || e.message || 'Could not finish the workout.';
                this.ui.finishing = false;
            }
        },

        // ------------------------------------------------------------------ sound, voice, metronome

        toggleSound() {
            this.muted = !this.muted;
            setSoundEnabled(!this.muted);
            if (!this.muted) { unlock(); cues.toggle(); }
        },
        toggleVoice() {
            this.voiceOn = !this.voiceOn;
            voice.setEnabled(this.voiceOn);
            if (this.voiceOn) voice.say('Voice coach on.');
        },
        toggleMetronome() {
            unlock();
            metronome ??= new Metronome((i, accent) => {
                this.metro.beat = accent ? 2 : 1;
                setTimeout(() => { this.metro.beat = 0; }, 100);
            });
            if (this.metro.on) { metronome.stop(); this.metro.on = false; return; }
            metronome.start(this.metro.bpm, 4);
            this.metro.on = true;
        },
        setBpm(value) {
            this.metro.bpm = Math.min(120, Math.max(30, Math.round(Number(value) || 60)));
            metronome?.setBpm(this.metro.bpm);
        },

        // ------------------------------------------------------------------ 3D demo

        async mountViewer() {
            const stage = this.$refs.stage;
            if (!stage) return;
            try {
                const mod = await import('./exercise-3d.js');
                if (destroyed) return;
                if (!mod.webglSupported()) { this.demo.supported = false; return; }
                viewer = new mod.ExerciseViewer(stage, { interactive: true });
                await this.showDemo();
            } catch {
                this.demo.supported = false;
            }
        },

        async showDemo() {
            if (!viewer) return;
            const ex = this.current;
            await viewer.setExercise({ key: ex.demoKey, regions: ex.regions });
            viewer.setSpeed(this.demo.speed);
            this.demo.cam = viewer.camName;
            this.demo.ready = true;
        },
        setCam(name) { viewer?.setCamera(name); this.demo.cam = name; },
        setSpeed(v) { this.demo.speed = v; viewer?.setSpeed(v); },
        toggleDemo() { this.demo.playing = viewer ? viewer.toggle() : false; },

        // ------------------------------------------------------------------ swap

        async openSwap() {
            this.ui.swap = true;
            this.ui.swapLoading = true;
            this.ui.swapError = '';
            this.ui.alternatives = [];
            try {
                const data = await window.api(`${this.cfg.urls.base}/exercises/${this.current.id}/alternatives`);
                this.ui.alternatives = data.alternatives;
            } catch (e) {
                this.ui.swapError = e.message || 'Could not load alternatives.';
            } finally {
                this.ui.swapLoading = false;
            }
        },

        async chooseSwap(id) {
            this.ui.swapLoading = true;
            try {
                const data = await window.api(`${this.cfg.urls.base}/exercises/${this.current.id}/swap`, { method: 'POST', body: { exercise_id: id } });
                this.exercises[this.idx] = { ...data.exercise };
                this.ui.swap = false;
                this.applyPrefill();
                await this.showDemo();
                this.toast(`Swapped to ${data.exercise.name}.`);
                this.persist();
            } catch (e) {
                this.ui.swapError = e.data?.errors?.exercise_id?.[0] || e.message || 'Could not swap.';
            } finally {
                this.ui.swapLoading = false;
            }
        },

        // ------------------------------------------------------------------ persistence & network

        snapshot() {
            return {
                idx: this.idx,
                setIdx: this.setIdx,
                phase: this.phase === 'intro' ? 'ready' : this.phase,
                weight: this.weight,
                reps: this.reps,
                suggestedReps: this.suggestedReps,
                clock: this.clock?.toJSON() ?? null,
                rest: this.rest?.toJSON() ?? null,
                nextPos: this.nextPos,
                nextWeight: this.nextWeight,
                prevPos: this.prevPos,
                paused: this.paused,
                metroBpm: this.metro.bpm,
                savedAt: Date.now(),
            };
        },

        /** Save locally now; push to the server shortly (or immediately when the page is closing). */
        persist(immediate = false) {
            if (this.phase === 'ready' || this.phase === 'intro') return;
            const state = this.snapshot();
            writeJson(storeKey(this.cfg.session.id), state);

            const push = (keepalive) => window.api(this.cfg.urls.state, { method: 'PUT', body: { state }, keepalive }).catch(() => {});
            clearTimeout(saveTimer);
            if (immediate) push(true);
            else saveTimer = setTimeout(() => push(false), 1200);
        },

        saveQueue() {
            this.pending = this.queue.length;
            writeJson(queueKey(this.cfg.session.id), this.queue.length ? this.queue : null);
        },

        enqueue(payload, exId, number) {
            this.queue = this.queue.filter((q) => !(q.exId === exId && q.number === number));
            this.queue.push({ payload, exId, number });
            this.saveQueue();
            this.flush();
        },

        /** Send queued sets in order. Network errors stop the loop and retry later; validation errors drop the item. */
        async flush() {
            if (this.flushing) return;
            this.flushing = true;
            try {
                while (this.queue.length && !destroyed) {
                    const item = this.queue[0];
                    try {
                        const res = await window.api(this.cfg.urls.sets, { method: 'POST', body: item.payload });
                        this.applyServerSet(item, res);
                        this.queue.shift();
                        this.saveQueue();
                    } catch (e) {
                        if (e.status && e.status < 500 && e.status !== 429) {
                            this.queue.shift();
                            this.saveQueue();
                            this.toast(e.data?.message || 'A set could not be saved.', 'warn');
                            if (e.status === 409) { window.location.reload(); return; }
                        } else {
                            break; // offline or server trouble: keep the queue and try again soon
                        }
                    }
                }
            } finally {
                this.flushing = false;
                if (this.queue.length && !destroyed) {
                    clearTimeout(flushTimer);
                    flushTimer = setTimeout(() => this.flush(), 4000);
                }
            }
        },

        applyServerSet(item, res) {
            const ex = this.exercises.find((e) => e.id === item.exId);
            const set = ex?.sets.find((s) => s.number === item.number);
            if (set) {
                set.id = res.set.id;
                set.isPr = res.isPr;
                set.pending = false;
            }
            if (res.isPr) {
                cues.pr();
                voice.say('New personal record!', { interrupt: false });
                this.toast(`New PR on ${ex?.name ?? 'this lift'}! +${res.xp} XP`, 'pr');
            }
        },

        // ------------------------------------------------------------------ misc

        tick() {
            this.now = Date.now();

            if (this.phase === 'rest' && this.rest && !this.paused) {
                const left = this.rest.remainingMs(this.now);
                const second = Math.ceil(left / 1000);
                if (left <= 0) this.endRest(true);
                else if (second <= 3 && second !== lastRestTickSecond) {
                    lastRestTickSecond = second;
                    cues.restTick();
                }
            }

            if (this.workStartedAt !== null && this.current.isTimed && this.now - this.workStartedAt >= this.holdTarget && !this.holdDone) {
                this.holdDone = true;
                cues.setDone();
            }
        },

        toast(text, kind = 'info') {
            this.ui.toastText = text;
            this.ui.toastKind = kind;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => { this.ui.toastText = ''; }, 3800);
        },

        async requestWakeLock() {
            try {
                if ('wakeLock' in navigator && !document.hidden) wakeLock = await navigator.wakeLock.request('screen');
            } catch { /* not allowed or not supported */ }
        },

        notifyRestOver() {
            try {
                if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
                    new Notification('Rest over', { body: `${this.current.name}, set ${this.setIdx + 1}. Go!`, tag: 'ip-rest' });
                }
            } catch { /* ignore */ }
        },

        formatClock,
    };
}
