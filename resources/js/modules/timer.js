/**
 * Timers that survive tab switches, throttled background tabs and reloads.
 *
 * Nothing here counts ticks. Every value is derived from epoch timestamps, so a timer is always
 * correct the moment you look at it, however long the tab was asleep. A `setInterval` is only ever
 * used to repaint the UI, never to keep time.
 */

/** Counts up from `startedAt` and can be paused (pauses are subtracted). */
export class SessionClock {
    constructor({ startedAt, pausedMs = 0, pausedAt = null } = {}, nowFn = Date.now) {
        this.nowFn = nowFn;
        this.startedAt = startedAt ?? nowFn();
        this.pausedMs = pausedMs;
        this.pausedAt = pausedAt;
    }

    get isPaused() {
        return this.pausedAt !== null;
    }

    elapsedMs(t = this.nowFn()) {
        const end = this.pausedAt ?? t;
        return Math.max(0, end - this.startedAt - this.pausedMs);
    }

    pause(t = this.nowFn()) {
        if (this.pausedAt === null) this.pausedAt = t;
    }

    resume(t = this.nowFn()) {
        if (this.pausedAt !== null) {
            this.pausedMs += Math.max(0, t - this.pausedAt);
            this.pausedAt = null;
        }
    }

    toJSON() {
        return { startedAt: this.startedAt, pausedMs: this.pausedMs, pausedAt: this.pausedAt };
    }

    static from(json, nowFn = Date.now) {
        return new SessionClock(json || {}, nowFn);
    }
}

/** Counts down to `endAt`. Can be frozen (paused), extended and serialised. */
export class Countdown {
    constructor({ endAt = null, totalMs = 0, frozenMs = null } = {}) {
        this.endAt = endAt;
        this.totalMs = totalMs;
        this.frozenMs = frozenMs;
    }

    static fromSeconds(seconds, t = Date.now()) {
        const ms = Math.max(0, Math.round(seconds * 1000));
        return new Countdown({ endAt: t + ms, totalMs: ms });
    }

    get isFrozen() {
        return this.frozenMs !== null;
    }

    remainingMs(t = Date.now()) {
        if (this.frozenMs !== null) return this.frozenMs;
        return Math.max(0, (this.endAt ?? t) - t);
    }

    isDone(t = Date.now()) {
        return this.remainingMs(t) <= 0;
    }

    /** Fraction of the original time still remaining, 0..1. */
    fractionLeft(t = Date.now()) {
        return this.totalMs > 0 ? Math.min(1, this.remainingMs(t) / this.totalMs) : 0;
    }

    extend(ms, t = Date.now()) {
        this.totalMs += ms;
        if (this.frozenMs !== null) this.frozenMs += ms;
        else this.endAt = Math.max(t, this.endAt ?? t) + ms;
    }

    freeze(t = Date.now()) {
        if (this.frozenMs === null) this.frozenMs = this.remainingMs(t);
    }

    thaw(t = Date.now()) {
        if (this.frozenMs !== null) {
            this.endAt = t + this.frozenMs;
            this.frozenMs = null;
        }
    }

    toJSON() {
        return { endAt: this.endAt, totalMs: this.totalMs, frozenMs: this.frozenMs };
    }

    static from(json) {
        return json ? new Countdown(json) : null;
    }
}

/** 75 -> "1:15", 3725 -> "1:02:05". */
export function formatClock(ms) {
    const total = Math.max(0, Math.floor(ms / 1000));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const mm = h > 0 ? String(m).padStart(2, '0') : String(m);
    return `${h > 0 ? `${h}:` : ''}${mm}:${String(s).padStart(2, '0')}`;
}

/** Countdown label rounds *up* so "0:01" shows until the very last moment. */
export function formatCountdown(ms) {
    return formatClock(Math.ceil(Math.max(0, ms) / 1000) * 1000);
}

/**
 * Repaint callback on an interval; also fires immediately when the tab becomes visible again so
 * the UI snaps to the right value after the browser throttled background timers.
 * Returns a stop function.
 */
export function startTicker(callback, intervalMs = 250, doc = typeof document !== 'undefined' ? document : null) {
    const id = setInterval(callback, intervalMs);
    const onVisible = () => { if (!doc.hidden) callback(); };
    doc?.addEventListener('visibilitychange', onVisible);
    return () => {
        clearInterval(id);
        doc?.removeEventListener('visibilitychange', onVisible);
    };
}
