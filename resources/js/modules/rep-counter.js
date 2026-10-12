/**
 * Rep counting as a small hysteresis state machine over one smoothed signal (usually a joint angle).
 *
 *   idle  -> ready  : signal rests in the start zone for `settleMs`
 *   ready -> out    : signal moves away from the start by more than the start zone plus a margin
 *   out   -> peak   : signal reaches the peak zone
 *   peak  -> back   : signal leaves the peak zone (with a margin, so jitter at the edge cannot flip it)
 *   back  -> ready  : signal returns to the start zone; the rep is complete
 *
 * Everything is expressed as progress p = (value - start) / (peak - start): 0 at the start position,
 * 1 at the peak, so the same machine counts a squat (angle falls), a lateral raise (angle rises) and a shrug.
 * A rep that is too short in time is treated as noise, a rep that never reaches the peak but travelled
 * far enough is reported as a partial rep, and the signal vanishing for too long aborts the rep.
 */

const START_MARGIN = 0.06; // how far past the start zone counts as "left the start"
const PEAK_MARGIN = 0.07;  // how far back from the peak zone counts as "left the peak"

export class RepCounter {
    /** @param {{start:number,peak:number,tolerance:number,settleMs:number,minMs:number,maxMs:number,minRom:number,fastMs:?number,lostMs:number}} cfg */
    constructor(cfg) {
        this.cfg = cfg;
        this.range = cfg.peak - cfg.start;
        this.startZone = cfg.tolerance / Math.abs(this.range);
        this.peakZone = 1 - cfg.tolerance / Math.abs(this.range);
        this.reset();
    }

    reset() {
        this.state = 'idle';
        this.progress = 0;
        this.settledSince = null;
        this.lostSince = null;
        this.rep = null;
        this.lastT = null;
    }

    /** Progress of a raw value along start -> peak. */
    progressOf(value) {
        return (value - this.cfg.start) / this.range;
    }

    /** Phase name used by form checks: rest | out | peak | back. */
    get phase() {
        return this.state === 'idle' || this.state === 'ready' ? 'rest' : this.state;
    }

    /**
     * Feed one sample. `value` is null when the signal could not be measured.
     * @returns {Array<object>} events: ready, rep_start, peak, rep_end (full or partial), noise, abort
     */
    update(value, tMs) {
        const events = [];
        this.lastT = tMs;

        if (value === null || value === undefined || Number.isNaN(value)) {
            this.lostSince ??= tMs;
            if (tMs - this.lostSince > this.cfg.lostMs && this.state !== 'idle') {
                if (this.rep) events.push({ type: 'abort', reason: 'lost', t: tMs });
                this.state = 'idle';
                this.rep = null;
                this.settledSince = null;
            }
            return events;
        }
        this.lostSince = null;

        const p = this.progressOf(value);
        this.progress = p;

        switch (this.state) {
            case 'idle':
                if (p <= this.startZone) {
                    this.settledSince ??= tMs;
                    if (tMs - this.settledSince >= this.cfg.settleMs) {
                        this.state = 'ready';
                        events.push({ type: 'ready', t: tMs });
                    }
                } else {
                    this.settledSince = null;
                }
                break;

            case 'ready':
                if (p > this.startZone + START_MARGIN) {
                    this.state = 'out';
                    this.rep = { t0: tMs, maxP: p, tPeak: null, reachedPeak: false };
                    events.push({ type: 'rep_start', t: tMs });
                }
                break;

            case 'out':
            case 'peak':
            case 'back':
                this.stepRep(p, tMs, events);
                break;

            default:
        }

        return events;
    }

    stepRep(p, tMs, events) {
        const rep = this.rep;
        rep.maxP = Math.max(rep.maxP, p);

        if (tMs - rep.t0 > this.cfg.maxMs) {
            events.push({ type: 'abort', reason: 'too_long', t: tMs });
            this.state = 'idle';
            this.rep = null;
            this.settledSince = null;
            return;
        }

        if (p >= this.peakZone) {
            if (this.state !== 'peak') {
                this.state = 'peak';
                if (!rep.reachedPeak) {
                    rep.reachedPeak = true;
                    rep.tPeak = tMs;
                    events.push({ type: 'peak', t: tMs });
                }
            }
            return;
        }

        if (this.state === 'peak' && p < this.peakZone - PEAK_MARGIN) this.state = 'back';

        if (p <= this.startZone) {
            this.finish(tMs, events);
        }
    }

    finish(tMs, events) {
        const rep = this.rep;
        const ms = tMs - rep.t0;
        this.rep = null;
        this.state = 'ready';

        if (ms < this.cfg.minMs) {
            events.push({ type: 'noise', t: tMs, ms });
            return;
        }
        if (!rep.reachedPeak && rep.maxP < this.cfg.minRom) {
            events.push({ type: 'noise', t: tMs, ms });
            return;
        }

        events.push({
            type: 'rep_end',
            t: tMs,
            t0: rep.t0,
            ms,
            partial: !rep.reachedPeak,
            rom: Math.min(1.5, rep.maxP),
            fast: this.cfg.fastMs !== null && ms < this.cfg.fastMs,
            peakAt: rep.tPeak,
        });
    }
}

/**
 * Timed holds (plank, wall sit): counts time while every in-position condition holds. A hold begins
 * after `settleMs` in position and ends after `graceMs` out of it, so one noisy frame does not reset the clock.
 */
export class HoldCounter {
    /** @param {{settleMs:number, graceMs:number, minHoldMs:number}} cfg */
    constructor(cfg) {
        this.cfg = cfg;
        this.reset();
    }

    reset() {
        this.state = 'idle';
        this.inSince = null;
        this.outSince = null;
        this.holdStart = null;
        this.lastIn = null;
        this.progress = 0;
        this.lastT = null;
    }

    get phase() {
        return this.state === 'holding' ? 'hold' : 'rest';
    }

    /** Elapsed hold time in ms for the hold in progress (0 when not holding). */
    get holdMs() {
        return this.state === 'holding' && this.lastIn !== null ? this.lastIn - this.holdStart : 0;
    }

    /** @param {boolean|null} inPosition null when the pose cannot be measured */
    update(inPosition, tMs) {
        const events = [];
        this.lastT = tMs;
        const inside = inPosition === true;
        const unknown = inPosition === null;

        if (this.state === 'idle') {
            if (inside) {
                this.inSince ??= tMs;
                if (tMs - this.inSince >= this.cfg.settleMs) {
                    this.state = 'holding';
                    this.holdStart = this.inSince;
                    this.lastIn = tMs;
                    this.outSince = null;
                    events.push({ type: 'hold_start', t: tMs });
                }
            } else {
                this.inSince = null;
            }
            return events;
        }

        // holding
        if (inside) {
            this.lastIn = tMs;
            this.outSince = null;
        } else {
            this.outSince ??= tMs;
            if (tMs - this.outSince > this.cfg.graceMs || (unknown && tMs - this.outSince > this.cfg.graceMs * 2)) {
                const ms = this.lastIn - this.holdStart;
                this.state = 'idle';
                this.inSince = null;
                this.outSince = null;
                events.push({ type: 'hold_end', t: tMs, ms, counted: ms >= this.cfg.minHoldMs });
            }
        }
        return events;
    }

    /** Close a hold in progress (the user pressed Finish). */
    flush(tMs) {
        if (this.state !== 'holding') return [];
        const ms = this.lastIn - this.holdStart;
        this.state = 'idle';
        return [{ type: 'hold_end', t: tMs, ms, counted: ms >= this.cfg.minHoldMs }];
    }
}
