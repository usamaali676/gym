import test from 'node:test';
import assert from 'node:assert/strict';
import {
    firstOpenPosition, advance, restSecondsFor, prefill, repChoices, stepWeight, clampReps,
    describeLast, totalVolume, totalSets, progressSegments, pickNewestState, sanitizeResume,
} from '../../resources/js/modules/workout-logic.js';

const ex = (over = {}) => ({ id: 1, targetSets: 3, repsMin: 8, repsMax: 12, restSeconds: 150, weight: 60, targetReps: 9, status: 'pending', sets: [], ...over });
const sets = (n, weight = 60, reps = 8) => Array.from({ length: n }, (_, i) => ({ number: i + 1, weight, reps }));

test('resume position is the first unfinished exercise at its next set', () => {
    const list = [ex({ id: 1, sets: sets(3), status: 'done' }), ex({ id: 2, sets: sets(1) }), ex({ id: 3 })];
    assert.deepEqual(firstOpenPosition(list), { idx: 1, setIdx: 1 });
    assert.equal(firstOpenPosition([ex({ sets: sets(3) })]), null);
});

test('skipped exercises are not resumed', () => {
    const list = [ex({ status: 'skipped' }), ex({ id: 2 })];
    assert.deepEqual(firstOpenPosition(list), { idx: 1, setIdx: 0 });
});

test('after a set: next set, then next exercise, then finished', () => {
    const list = [ex({ id: 1 }), ex({ id: 2 })];
    assert.deepEqual(advance(list, 0, 0), { kind: 'next-set', idx: 0, setIdx: 1 });
    assert.deepEqual(advance(list, 0, 1), { kind: 'next-set', idx: 0, setIdx: 2 });
    list[0].sets = sets(3);
    assert.deepEqual(advance(list, 0, 2), { kind: 'next-exercise', idx: 1, setIdx: 0 });
    list[1].sets = sets(3);
    assert.equal(advance(list, 1, 2).kind, 'finished');
});

test('after the last exercise it goes back to one that was jumped over', () => {
    const list = [ex({ id: 1 }), ex({ id: 2, sets: sets(3) }), ex({ id: 3, sets: sets(3) })];
    list[2].sets = sets(3);
    assert.deepEqual(advance(list, 2, 2), { kind: 'next-exercise', idx: 0, setIdx: 0 });
});

test('a single-exercise workout finishes after its last set', () => {
    const list = [ex({ sets: sets(3) })];
    assert.equal(advance(list, 0, 2).kind, 'finished');
});

test('rest uses the exercise default and never goes below 10 seconds', () => {
    assert.equal(restSecondsFor(ex({ restSeconds: 150 })), 150);
    assert.equal(restSecondsFor(ex({ restSeconds: 75 })), 75);
    assert.equal(restSecondsFor(ex({ restSeconds: 0 })), 75);
    assert.equal(restSecondsFor(ex({ restSeconds: 3 })), 10);
});

test('prefill: first set uses the suggested target, later sets repeat the last lifted weight', () => {
    const e = ex({ weight: 62.5, targetReps: 9 });
    assert.deepEqual(prefill(e, 0), { weight: 62.5, reps: 0, suggestedReps: 9 });
    e.sets = [{ number: 1, weight: 60, reps: 10 }];
    assert.deepEqual(prefill(e, 1), { weight: 60, reps: 0, suggestedReps: 10 });
});

test('prefill handles bodyweight exercises', () => {
    assert.equal(prefill(ex({ weight: null }), 0).weight, null);
});

test('rep chips cover the range with a little headroom', () => {
    assert.deepEqual(repChoices(ex({ repsMin: 8, repsMax: 10 })), [7, 8, 9, 10, 11, 12]);
    assert.deepEqual(repChoices(ex({ repsMin: 1, repsMax: 3 })), [1, 2, 3, 4, 5]);
});

test('weight stepper moves by 2.5 kg and never goes negative', () => {
    assert.equal(stepWeight(60, 1), 62.5);
    assert.equal(stepWeight(60, -1), 57.5);
    assert.equal(stepWeight(2.5, -1), null);
    assert.equal(stepWeight(null, 1), 2.5);
    assert.equal(stepWeight(null, -1), null);
    assert.equal(stepWeight(0.1, 1, 0.1), 0.2);
});

test('reps are clamped to a sane integer', () => {
    assert.equal(clampReps('8'), 8);
    assert.equal(clampReps(-3), 0);
    assert.equal(clampReps(9999), 500);
    assert.equal(clampReps('abc'), 0);
    assert.equal(clampReps(7.9), 7);
});

test('last performance is described compactly', () => {
    assert.equal(describeLast({ sets: [{ weight: 60, reps: 8 }, { weight: 60, reps: 8 }, { weight: 60, reps: 7 }] }), '60 kg × 8, 8, 7');
    assert.equal(describeLast({ sets: [{ weight: 60, reps: 8 }, { weight: 55, reps: 9 }] }), '60 kg × 8 · 55 kg × 9');
    assert.equal(describeLast({ sets: [{ weight: null, reps: 6 }] }), 'BW × 6');
    assert.equal(describeLast(null), null);
    assert.equal(describeLast({ sets: [] }), null);
});

test('volume and set totals', () => {
    const list = [ex({ sets: [{ weight: 60, reps: 8 }, { weight: 60, reps: 7 }] }), ex({ sets: [{ weight: null, reps: 10 }] })];
    assert.equal(totalVolume(list), 60 * 8 + 60 * 7);
    assert.equal(totalSets(list), 3);
});

test('progress segments', () => {
    assert.deepEqual(progressSegments([ex({ sets: sets(3) }), ex({ sets: sets(1) }), ex({ status: 'skipped' }), ex()]), [1, 1 / 3, 1, 0]);
});

test('the newest resume snapshot wins', () => {
    const a = { savedAt: 100, idx: 0 };
    const b = { savedAt: 200, idx: 1 };
    assert.equal(pickNewestState(a, b), b);
    assert.equal(pickNewestState(null, a), a);
    assert.equal(pickNewestState(null, undefined), null);
    assert.equal(pickNewestState({ idx: 1 }), null);
});

test('stale or corrupted resume snapshots are rejected', () => {
    const list = [ex(), ex()];
    assert.ok(sanitizeResume({ idx: 1, setIdx: 0, phase: 'rest' }, list));
    assert.equal(sanitizeResume({ idx: 5, setIdx: 0, phase: 'work' }, list), null);
    assert.equal(sanitizeResume({ idx: 0, setIdx: 0, phase: 'dancing' }, list), null);
    assert.equal(sanitizeResume({ idx: '1', setIdx: 0, phase: 'work' }, list), null);
    assert.equal(sanitizeResume(null, list), null);
});
