/**
 * Turns a stream of pose landmarks into form feedback, rep counts and a session summary.
 * It is the only stateful piece of the posture pipeline and has no DOM or MediaPipe dependency,
 * so the exact same code is exercised by the Node tests with synthetic landmarks.
 *
 *   landmarks -> One-Euro smoothing -> measures -> rep counter -> checks (debounced) -> score
 */

import { SEGMENT_GROUPS, clamp, scaleLandmarks } from './geometry.js';
import { LandmarkSmoother } from './one-euro-filter.js';
import { HoldCounter, RepCounter } from './rep-counter.js';
import { compileRule, evaluateMeasures, getPoint, requiredPoints, sideVisibility, violates } from './rule-engine.js';

export const PENALTY = { major: 25, minor: 10 };
const REP_ERROR_FRACTION = 0.15;  // a frame check must be failing for this share of a rep to count against it
const CLEAR_FRAMES = 3;           // consecutive good frames before a red segment turns green again
const CUE_COOLDOWN_MS = 4000;     // the same spoken cue is not repeated more often than this
const SETTLE_MS = 600;            // after a rep ends, keep watching so lockout / return-position checks see the true end of the rep
const FLASH_MS = 1600;            // segments that caused a bad rep stay red for a moment after the rep
const MAX_LOG = 200;

const TEMPO_ERROR = { id: 'tempo', label: 'Too fast', message: 'Slow down and control the weight.', severity: 'minor', segments: [] };

export class PostureAnalyzer {
    /** @param {object} config a posture_rules.config object (raw JSON) @param {{aspect?:number}} options */
    constructor(config, { aspect = 4 / 3 } = {}) {
        this.rule = compileRule(config);
        this.aspect = aspect;
        this.smoother = new LandmarkSmoother({ minCutoff: this.rule.smoothing.minCutoff, beta: this.rule.smoothing.beta });
        this.isHold = this.rule.rep.kind === 'hold';
        this.counter = this.isHold ? new HoldCounter(this.rule.rep) : new RepCounter(this.rule.rep);
        this.frameChecks = this.rule.checks.filter((c) => c.scope === 'frame');
        this.repChecks = this.rule.checks.filter((c) => c.scope === 'rep');
        this.reset();
    }

    reset() {
        this.smoother.reset();
        this.counter.reset();
        this.side = this.rule.side === 'right' ? 'r' : 'l';
        this.sideScore = { l: 0, r: 0 };
        this.facingScore = 0;
        this.facing = 1;
        this.baselines = {};
        this.trackers = Object.fromEntries(this.rule.checks.map((c) => [c.id, { on: false, runOn: 0, runOff: 0, lastCue: -Infinity }]));
        this.acc = null;
        this.pending = null;
        this.flash = { until: 0, bones: new Set() };
        this.reps = 0;
        this.good = 0;
        this.bad = 0;
        this.repLog = [];
        this.errorCounts = {};
        this.scoreSum = 0;
        this.scoreMin = 100;
        this.frameScore = 100;
        this.frameScoreSum = 0;
        this.frameScoreCount = 0;
        this.holdMs = 0;
        this.goodHoldMs = 0;
        this.countedHoldMs = 0;
        this.firstT = null;
        this.lastT = null;
        this.lastRep = null;
        this.hasSeenPerson = false;
    }

    // -----------------------------------------------------------------------------------------

    /**
     * @param {Array<{x:number,y:number,z?:number,visibility?:number}>|null} landmarks 33 normalised landmarks, or null when nobody is in view
     * @param {number} tMs timestamp in ms (monotonic)
     * @param {{aspect?:number}} [opts]
     */
    update(landmarks, tMs, opts = {}) {
        if (opts.aspect) this.aspect = opts.aspect;
        this.firstT ??= tMs;
        const dt = this.lastT === null ? 0 : Math.max(0, tMs - this.lastT);
        this.lastT = tMs;
        const events = [];

        if (!landmarks || landmarks.length < 33) return this.nobody(tMs, events);
        this.hasSeenPerson = true;

        const smooth = this.smoother.smooth(landmarks, tMs);
        const points = scaleLandmarks(smooth, this.aspect);
        const inRep = this.counter.phase !== 'rest';
        this.updateSide(points, inRep);
        this.updateFacing(points, inRep);

        const view = { points, minVis: this.rule.minVisibility, facing: this.facing, aspect: this.aspect };
        const { values, missing } = evaluateMeasures(this.rule, view, this.side);
        const missingRequired = this.missingRequired(view);

        this.applyRelative(values);

        // --- rep / hold state machine -------------------------------------------------------
        let counterEvents;
        if (this.isHold) {
            const inPosition = this.holdInPosition(values);
            counterEvents = this.counter.update(inPosition, tMs);
        } else {
            counterEvents = this.counter.update(values[this.rule.rep.signal] ?? null, tMs);
        }
        const phase = this.counter.phase;

        // --- checks --------------------------------------------------------------------------
        const active = this.evaluateFrameChecks(values, phase, tMs, events);
        // The frame that completes a rep still counts towards it (its value is the lockout / return position).
        const finishing = counterEvents.some((e) => e.type === 'rep_end' || e.type === 'hold_end');
        this.accumulate(values, phase !== 'rest' || finishing, active, dt);
        if (this.pending) this.updateStats(this.pending.acc, values, this.pending.p);

        for (const e of counterEvents) this.handleCounterEvent(e, tMs, events);
        if (this.pending && tMs - this.pending.tEnd >= SETTLE_MS) this.finalizePending(tMs, events);

        // --- score and bones -----------------------------------------------------------------
        const penalty = active.reduce((s, c) => s + PENALTY[c.severity], 0);
        const instant = clamp(100 - penalty, 0, 100);
        this.frameScore += 0.25 * (instant - this.frameScore);
        if (phase !== 'rest') {
            this.frameScoreSum += instant;
            this.frameScoreCount += 1;
        }

        const red = new Set();
        for (const c of active) for (const g of c.segments) for (const b of SEGMENT_GROUPS[g] ?? []) red.add(b);
        if (tMs < this.flash.until) for (const b of this.flash.bones) red.add(b);

        const status = this.status(missingRequired, phase);
        return {
            t: tMs,
            status,
            missing: missingRequired,
            hidden: [...missing],
            side: this.side,
            facing: this.facing,
            phase,
            progress: this.isHold ? 0 : clamp(this.counter.progress, 0, 1.2),
            signal: this.isHold ? null : values[this.rule.rep.signal] ?? null,
            measures: values,
            reps: this.reps,
            good: this.good,
            bad: this.bad,
            holdMs: this.isHold ? this.counter.holdMs : 0,
            score: Math.round(this.frameScore),
            active,
            redBones: red,
            events,
            lastRep: this.lastRep,
        };
    }

    nobody(tMs, events) {
        const counterEvents = this.isHold ? this.counter.update(null, tMs) : this.counter.update(null, tMs);
        for (const e of counterEvents) this.handleCounterEvent(e, tMs, events);
        if (this.pending && tMs - this.pending.tEnd >= SETTLE_MS) this.finalizePending(tMs, events);
        return {
            t: tMs, status: 'no_person', missing: [], hidden: [], side: this.side, facing: this.facing, phase: this.counter.phase,
            progress: 0, signal: null, measures: {}, reps: this.reps, good: this.good, bad: this.bad, holdMs: 0,
            score: Math.round(this.frameScore), active: [], redBones: new Set(), events, lastRep: this.lastRep,
        };
    }

    status(missingRequired, phase) {
        const midMovement = phase !== 'rest';
        if (missingRequired.length > 0 && !midMovement) return 'adjust';
        if (midMovement) return 'active';
        if (this.isHold) return 'positioning';
        return this.counter.state === 'ready' ? 'ready' : 'positioning';
    }

    // -----------------------------------------------------------------------------------------
    // Camera-side bookkeeping

    updateSide(points, inRep) {
        const { l, r } = sideVisibility(this.rule, points);
        this.sideScore.l += 0.1 * (l - this.sideScore.l);
        this.sideScore.r += 0.1 * (r - this.sideScore.r);
        if (this.rule.side === 'left') this.side = 'l';
        else if (this.rule.side === 'right') this.side = 'r';
        else if (this.rule.side === 'auto' && !inRep) {
            const diff = this.sideScore.l - this.sideScore.r;
            if (diff > 0.08) this.side = 'l';
            else if (diff < -0.08) this.side = 'r';
        }
    }

    /** Which way the person faces in a side-on view: +1 = towards the right of the image. */
    updateFacing(points, inRep) {
        if (inRep) return;
        const vis = (p) => p && p.v >= this.rule.minVisibility;
        const nose = points[0];
        const hips = [points[23], points[24]].filter(vis);
        let sign = 0;
        if (vis(nose) && hips.length) {
            const hipX = hips.reduce((s, p) => s + p.x, 0) / hips.length;
            sign = Math.sign(nose.x - hipX);
        } else {
            const toe = points[this.side === 'l' ? 31 : 32];
            const heel = points[this.side === 'l' ? 29 : 30];
            if (vis(toe) && vis(heel)) sign = Math.sign(toe.x - heel.x);
        }
        if (sign !== 0) {
            this.facingScore += 0.1 * (sign - this.facingScore);
            this.facing = this.facingScore >= 0 ? 1 : -1;
        }
    }

    missingRequired(view) {
        const names = requiredPoints(this.rule, this.rule.side === 'both' ? 'both' : this.side);
        const out = [];
        for (const n of names) {
            if (!getPoint(view, n, this.side)) out.push(n);
        }
        return out;
    }

    // -----------------------------------------------------------------------------------------
    // Measures

    /** Replace relative measures with their change from the baseline captured at the start position. */
    applyRelative(values) {
        const settled = this.isHold ? this.counter.state === 'idle' : this.counter.state === 'ready';
        for (const [name, def] of Object.entries(this.rule.measures)) {
            if (!def.relative) continue;
            const raw = values[name];
            if (raw === null || raw === undefined) continue;
            let base = this.baselines[name] ?? null;
            if (base === null) {
                base = raw;
            } else if (settled) {
                base += 0.1 * (raw - base);
            }
            this.baselines[name] = base;
            if (def.relative === 'delta') values[name] = raw - base;
            else values[name] = Math.abs(base) < 1e-6 ? null : raw / base;
        }
    }

    holdInPosition(values) {
        const results = this.rule.rep.inPosition.map((c) => {
            const v = values[c.measure];
            return v === null || v === undefined ? null : violates(v, c.op, c.value);
        });
        if (results.some((r) => r === null)) return null;
        return results.every(Boolean);
    }

    // -----------------------------------------------------------------------------------------
    // Checks

    evaluateFrameChecks(values, phase, tMs, events) {
        const active = [];
        for (const c of this.frameChecks) {
            const t = this.trackers[c.id];
            const value = values[c.measure];

            if (!c.phases.includes(phase)) {
                t.on = false;
                t.runOn = 0;
                t.runOff = 0;
                continue;
            }
            if (value !== null && value !== undefined) {
                if (violates(value, c.op, c.value, c.abs)) {
                    t.runOn += 1;
                    t.runOff = 0;
                } else {
                    t.runOff += 1;
                    t.runOn = 0;
                }
            }
            if (t.runOn >= c.frames) t.on = true;
            else if (t.runOff >= CLEAR_FRAMES) t.on = false;

            if (t.on) {
                active.push(c);
                if (t.runOn === c.frames && tMs - t.lastCue > CUE_COOLDOWN_MS) {
                    t.lastCue = tMs;
                    events.push({ type: 'cue', id: c.id, message: c.message, label: c.label, severity: c.severity });
                }
            }
        }
        return active;
    }

    /** Collect per-rep (or per-hold) statistics: frames spent in each failing check and extremes of the measures rep checks read. */
    accumulate(values, inSet, active, dt) {
        if (!inSet) return;
        this.acc ??= this.newAcc();
        const acc = this.acc;
        acc.frames += 1;
        acc.ms += dt;
        for (const c of active) acc.checkFrames[c.id] = (acc.checkFrames[c.id] ?? 0) + 1;

        if (this.isHold) {
            this.holdMs = this.counter.holdMs;
            if (!active.some((c) => c.severity === 'major')) {
                acc.goodMs += dt;
            }
            return;
        }

        this.updateStats(acc, values, this.counter.progress);
    }

    /** Track min / max / value-at-peak of every measure a rep-scope check reads. */
    updateStats(acc, values, p) {
        for (const c of this.repChecks) {
            const v = values[c.measure];
            if (v === null || v === undefined) continue;
            const s = (acc.stats[c.measure] ??= { min: v, max: v, peak: v, peakP: p });
            s.min = Math.min(s.min, v);
            s.max = Math.max(s.max, v);
            if (p >= s.peakP) {
                s.peakP = p;
                s.peak = v;
            }
        }
    }

    newAcc() {
        return { frames: 0, ms: 0, goodMs: 0, checkFrames: {}, stats: {} };
    }

    // -----------------------------------------------------------------------------------------
    // Counter events -> rep records

    handleCounterEvent(e, tMs, events) {
        switch (e.type) {
            case 'ready':
                events.push({ type: 'ready' });
                break;
            case 'rep_start':
            case 'hold_start':
                this.finalizePending(tMs, events);
                this.acc = this.newAcc();
                for (const t of Object.values(this.trackers)) t.lastCue = Math.min(t.lastCue, tMs - CUE_COOLDOWN_MS);
                break;
            case 'peak':
                events.push({ type: 'peak' });
                break;
            case 'rep_end':
                // Judge the rep a moment later so a slow lockout still counts towards it.
                this.finalizePending(tMs, events);
                this.pending = { e, acc: this.acc ?? this.newAcc(), tEnd: tMs, p: -Infinity };
                this.acc = null;
                break;
            case 'hold_end':
                this.finishHold(e, tMs, events);
                this.acc = null;
                break;
            case 'abort':
                this.acc = null;
                events.push({ type: 'abort', reason: e.reason });
                break;
            case 'noise':
                this.acc = null;
                break;
            default:
        }
    }

    /** Errors for one rep or hold: frame checks that were failing for long enough, plus rep-scope checks and built-ins. */
    collectErrors(e, includeStats, acc) {
        const errors = [];

        for (const c of this.frameChecks) {
            const frames = acc.checkFrames[c.id] ?? 0;
            if (frames >= c.frames && frames / Math.max(1, acc.frames) >= REP_ERROR_FRACTION) errors.push(c);
        }
        if (includeStats) {
            for (const c of this.repChecks) {
                const s = acc.stats[c.measure];
                if (!s) continue;
                const value = c.stat === 'range' ? s.max - s.min : s[c.stat];
                if (violates(value, c.op, c.value, c.abs)) errors.push(c);
            }
            if (e.partial) errors.push({ id: 'range', label: 'Incomplete range', message: this.rule.rep.partialMessage, severity: 'major', segments: [] });
            if (e.fast) errors.push(TEMPO_ERROR);
        }
        return errors;
    }

    finalizePending(tMs, events) {
        if (!this.pending) return;
        const { e, acc } = this.pending;
        this.pending = null;
        this.finishRep(e, acc, tMs, events);
    }

    finishRep(e, acc, tMs, events) {
        const errors = this.collectErrors(e, true, acc);
        const score = clamp(100 - errors.reduce((s, c) => s + PENALTY[c.severity], 0), 0, 100);
        const good = !errors.some((c) => c.severity === 'major') && score >= this.rule.goodScore;

        this.reps += 1;
        if (good) this.good += 1;
        else this.bad += 1;
        this.scoreSum += score;
        this.scoreMin = Math.min(this.scoreMin, score);
        for (const c of errors) this.errorCounts[c.id] = (this.errorCounts[c.id] ?? 0) + 1;

        const entry = {
            n: this.reps,
            ms: Math.round(e.ms),
            rom: Math.round(e.rom * 100) / 100,
            score,
            good,
            partial: e.partial,
            errors: errors.map((c) => c.id),
        };
        if (this.repLog.length < MAX_LOG) this.repLog.push(entry);

        this.lastRep = {
            ...entry,
            feedback: good ? 'Good rep' : (errors.find((c) => c.severity === 'major') ?? errors[0])?.message ?? 'Check your form',
            problems: errors.map((c) => ({ id: c.id, label: c.label, message: c.message, severity: c.severity })),
        };

        if (!good) {
            this.flash = { until: tMs + FLASH_MS, bones: new Set(errors.flatMap((c) => c.segments.flatMap((g) => SEGMENT_GROUPS[g] ?? []))) };
        }
        events.push({ type: 'rep', ...this.lastRep, reps: this.reps, goodReps: this.good });
    }

    finishHold(e, tMs, events) {
        const acc = this.acc ?? this.newAcc();
        if (!e.counted) {
            events.push({ type: 'hold_short', ms: e.ms });
            return;
        }
        const errors = this.collectErrors(e, false, acc);
        const score = clamp(100 - errors.reduce((s, c) => s + PENALTY[c.severity], 0), 0, 100);
        const good = !errors.some((c) => c.severity === 'major') && score >= this.rule.goodScore;

        this.reps += 1;
        if (good) this.good += 1;
        else this.bad += 1;
        this.scoreSum += score;
        this.scoreMin = Math.min(this.scoreMin, score);
        this.countedHoldMs += e.ms;
        this.goodHoldMs += Math.min(acc.goodMs, e.ms);
        for (const c of errors) this.errorCounts[c.id] = (this.errorCounts[c.id] ?? 0) + 1;

        const entry = { n: this.reps, ms: Math.round(e.ms), score, good, partial: false, errors: errors.map((c) => c.id), hold: true };
        if (this.repLog.length < MAX_LOG) this.repLog.push(entry);
        this.lastRep = {
            ...entry,
            feedback: good ? 'Solid hold' : (errors.find((c) => c.severity === 'major') ?? errors[0])?.message ?? 'Check your form',
            problems: errors.map((c) => ({ id: c.id, label: c.label, message: c.message, severity: c.severity })),
        };
        events.push({ type: 'rep', ...this.lastRep, reps: this.reps, goodReps: this.good });
    }

    // -----------------------------------------------------------------------------------------

    /** Close anything in progress (the user pressed Finish). */
    flush(tMs) {
        const events = [];
        this.finalizePending(tMs, events);
        if (this.isHold) for (const e of this.counter.flush(tMs)) this.handleCounterEvent(e, tMs, events);
        return events;
    }

    /** The payload the server stores. Never includes images or landmarks. */
    summary(durationSeconds) {
        this.finalizePending(this.lastT ?? 0, []);
        const avgFromReps = this.reps > 0 ? this.scoreSum / this.reps : null;
        const avgFromFrames = this.frameScoreCount > 0 ? this.frameScoreSum / this.frameScoreCount : 0;
        return {
            duration_seconds: Math.max(0, Math.round(durationSeconds)),
            total_reps: this.reps,
            good_reps: this.good,
            bad_reps: this.bad,
            hold_seconds: this.isHold ? Math.round(this.countedHoldMs / 1000) : null,
            form_score_avg: Math.round(avgFromReps ?? avgFromFrames),
            form_score_min: this.reps > 0 ? this.scoreMin : Math.round(avgFromFrames),
            error_counts: { ...this.errorCounts },
            rep_log: this.repLog.map((r) => ({ ...r })),
        };
    }
}
