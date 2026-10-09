import assert from 'node:assert/strict';
import test from 'node:test';
import { HoldCounter, RepCounter } from '../../resources/js/modules/rep-counter.js';
import { gaussian, repProfile, rng } from './helpers/synthetic-pose.mjs';

const CFG = { start: 165, peak: 85, tolerance: 14, settleMs: 250, minMs: 700, maxMs: 12000, minRom: 0.45, fastMs: null, lostMs: 1200 };

/** Run a counter over `valueAt(t)` and return every event. */
function run(counter, valueAt, durationMs, { fps = 30, noise = 0, seed = 1 } = {}) {
    const rand = rng(seed);
    const events = [];
    for (let t = 0; t <= durationMs; t += 1000 / fps) {
        const v = valueAt(t);
        events.push(...counter.update(v === null ? null : v + (noise ? gaussian(rand) * noise : 0), t));
    }
    return events;
}

const lerp = (a, b, t) => a + (b - a) * t;
const types = (events, type) => events.filter((e) => e.type === type);

test('counts clean reps for a signal that falls (squat, press)', () => {
    const profile = repProfile({ reps: 5 });
    const counter = new RepCounter(CFG);
    const events = run(counter, (t) => lerp(165, 85, profile.p(t)), profile.duration);
    const reps = types(events, 'rep_end');
    assert.equal(reps.length, 5);
    assert.ok(reps.every((r) => !r.partial && r.ms > 1500 && r.ms < 2600));
    assert.equal(types(events, 'rep_start').length, 5);
    assert.equal(types(events, 'peak').length, 5);
});

test('counts reps for a signal that rises (lateral raise)', () => {
    const profile = repProfile({ reps: 4 });
    const counter = new RepCounter({ ...CFG, start: 15, peak: 85, tolerance: 12 });
    const reps = types(run(counter, (t) => lerp(15, 85, profile.p(t)), profile.duration), 'rep_end');
    assert.equal(reps.length, 4);
});

test('measurement noise does not create extra or missing reps (hysteresis)', () => {
    const profile = repProfile({ reps: 6 });
    for (const seed of [1, 2, 3, 4, 5]) {
        const counter = new RepCounter(CFG);
        const events = run(counter, (t) => lerp(165, 85, profile.p(t)), profile.duration, { noise: 2.5, seed });
        assert.equal(types(events, 'rep_end').length, 6, `seed ${seed}`);
    }
});

test('jitter around the start threshold is not a rep', () => {
    const counter = new RepCounter(CFG);
    // Wobble ±8 degrees around the start position, well inside the start zone plus margin.
    const events = run(counter, (t) => 165 - 8 * Math.abs(Math.sin(t / 130)), 6000);
    assert.equal(types(events, 'rep_end').length, 0);
    assert.equal(types(events, 'rep_start').length, 0);
});

test('hovering near the peak edge does not double count', () => {
    const profile = repProfile({ reps: 3, pauseMs: 1500 });
    const counter = new RepCounter(CFG);
    const edge = 85 + 14; // edge of the peak zone
    const events = run(counter, (t) => {
        const p = profile.p(t);
        return p >= 0.999 ? edge + 3 * Math.sin(t / 60) : lerp(165, 85, p);
    }, profile.duration);
    assert.equal(types(events, 'rep_end').length, 3);
});

test('a movement that is too fast is noise, not a rep', () => {
    const profile = repProfile({ reps: 3, downMs: 150, pauseMs: 0, upMs: 150 });
    const counter = new RepCounter(CFG);
    const events = run(counter, (t) => lerp(165, 85, profile.p(t)), profile.duration, { fps: 60 });
    assert.equal(types(events, 'rep_end').length, 0);
    assert.ok(types(events, 'noise').length >= 1);
});

test('a half rep is reported as partial; a tiny wobble is ignored', () => {
    const half = repProfile({ reps: 2, depth: 0.7 });
    const counter = new RepCounter(CFG);
    const reps = types(run(counter, (t) => lerp(165, 85, half.p(t)), half.duration), 'rep_end');
    assert.equal(reps.length, 2);
    assert.ok(reps.every((r) => r.partial === true));

    const tiny = repProfile({ reps: 2, depth: 0.25 });
    const c2 = new RepCounter(CFG);
    assert.equal(types(run(c2, (t) => lerp(165, 85, tiny.p(t)), tiny.duration), 'rep_end').length, 0);
});

test('fast reps are flagged when fastMs is set', () => {
    const profile = repProfile({ reps: 2, downMs: 450, pauseMs: 0, upMs: 450 });
    const counter = new RepCounter({ ...CFG, minMs: 400, fastMs: 1500 });
    const reps = types(run(counter, (t) => lerp(165, 85, profile.p(t)), profile.duration, { fps: 60 }), 'rep_end');
    assert.equal(reps.length, 2);
    assert.ok(reps.every((r) => r.fast));
});

test('the counter must see the start position before the first rep, and ignores a rep begun mid-way', () => {
    const counter = new RepCounter(CFG);
    // Starts at the bottom, rises, then does proper reps.
    const profile = repProfile({ reps: 2, restMs: 3000 });
    const events = run(counter, (t) => (t < 1500 ? 90 + t / 30 : lerp(165, 85, profile.p(t))), profile.duration);
    assert.equal(types(events, 'rep_end').length, 2);
    assert.equal(types(events, 'ready').length, 1);
});

test('losing the signal mid-rep aborts it instead of counting garbage', () => {
    const counter = new RepCounter(CFG);
    const events = run(counter, (t) => (t < 4000 ? lerp(165, 85, Math.min(1, t / 3000)) : null), 8000);
    assert.equal(types(events, 'rep_end').length, 0);
    assert.equal(types(events, 'abort').length, 1);
    assert.equal(counter.state, 'idle');
});

test('a short dropout inside a rep does not abort it', () => {
    const profile = repProfile({ reps: 1 });
    const counter = new RepCounter(CFG);
    const events = run(counter, (t) => (t > 2400 && t < 2800 ? null : lerp(165, 85, profile.p(t))), profile.duration);
    assert.equal(types(events, 'rep_end').length, 1);
});

test('a rep that never finishes times out', () => {
    const counter = new RepCounter({ ...CFG, maxMs: 4000 });
    const events = run(counter, (t) => (t < 1000 ? 165 : 120), 9000);
    assert.equal(types(events, 'rep_end').length, 0);
    assert.equal(types(events, 'abort').length, 1);
});

test('phases follow the rep', () => {
    const profile = repProfile({ reps: 1 });
    const counter = new RepCounter(CFG);
    const seen = new Set();
    for (let t = 0; t <= profile.duration; t += 33) {
        counter.update(lerp(165, 85, profile.p(t)), t);
        seen.add(counter.phase);
    }
    assert.deepEqual([...seen].sort(), ['back', 'out', 'peak', 'rest']);
});

// ---------------------------------------------------------------------------------------------

test('hold counter times a hold and tolerates a flicker', () => {
    const counter = new HoldCounter({ settleMs: 500, graceMs: 800, minHoldMs: 3000 });
    const events = [];
    for (let t = 0; t <= 14000; t += 33) {
        // in position 1s..9s, one 300 ms flicker at 5s, back out afterwards
        const inside = t >= 1000 && t <= 9000 && !(t > 5000 && t < 5300);
        events.push(...counter.update(inside, t));
    }
    const end = events.find((e) => e.type === 'hold_end');
    assert.ok(end, 'hold should end after leaving position');
    assert.ok(end.counted);
    assert.ok(end.ms > 7500 && end.ms < 9000, `hold ms ${end.ms}`);
    assert.equal(events.filter((e) => e.type === 'hold_start').length, 1);
});

test('short holds are not counted and flush closes a hold in progress', () => {
    const short = new HoldCounter({ settleMs: 300, graceMs: 500, minHoldMs: 3000 });
    const events = [];
    for (let t = 0; t <= 6000; t += 33) events.push(...short.update(t > 500 && t < 2000, t));
    assert.equal(events.find((e) => e.type === 'hold_end')?.counted, false);

    const long = new HoldCounter({ settleMs: 300, graceMs: 500, minHoldMs: 3000 });
    for (let t = 0; t <= 6000; t += 33) long.update(t > 500, t);
    const [flushed] = long.flush(6033);
    assert.equal(flushed.type, 'hold_end');
    assert.ok(flushed.counted && flushed.ms > 5000);
});

test('unknown pose does not start a hold', () => {
    const counter = new HoldCounter({ settleMs: 300, graceMs: 500, minHoldMs: 3000 });
    const events = [];
    for (let t = 0; t <= 3000; t += 33) events.push(...counter.update(null, t));
    assert.equal(events.length, 0);
});
