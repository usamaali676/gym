/**
 * Pure rules for the guided workout (no DOM, no network), so they can be unit-tested.
 *
 * An "exercise" here is the object built by WorkoutPageData:
 *   { id, targetSets, repsMin, repsMax, restSeconds, weight, targetReps, status, sets: [{number, weight, reps}], ... }
 */

export const isFinished = (ex) => ex.status === 'skipped' || ex.status === 'done' || ex.sets.length >= ex.targetSets;

/** Where to resume: the first exercise that still has sets to do, at its next set. */
export function firstOpenPosition(exercises) {
    for (let i = 0; i < exercises.length; i += 1) {
        if (!isFinished(exercises[i])) return { idx: i, setIdx: exercises[i].sets.length };
    }
    return null;
}

/**
 * What happens after set number `setIdx` (0-based) of exercise `idx` is logged.
 *  - more sets left        -> { kind: 'next-set' }
 *  - another exercise open -> { kind: 'next-exercise' } (the next one after this, else the first still open)
 *  - nothing left          -> { kind: 'finished' }
 */
export function advance(exercises, idx, setIdx) {
    const ex = exercises[idx];
    if (setIdx + 1 < ex.targetSets) return { kind: 'next-set', idx, setIdx: setIdx + 1 };

    const order = [...exercises.keys()].filter((i) => i !== idx);
    const after = order.filter((i) => i > idx);
    const before = order.filter((i) => i < idx);
    for (const i of [...after, ...before]) {
        if (!isFinished(exercises[i])) return { kind: 'next-exercise', idx: i, setIdx: exercises[i].sets.length };
    }
    return { kind: 'finished', idx, setIdx: setIdx + 1 };
}

/** Rest after a set: what the server sent for this exercise (20 s unless the gym changed it). */
export function restSecondsFor(ex) {
    return Math.max(10, Math.round(ex.restSeconds || 20));
}

/**
 * Starting weight and the rep chips for a set.
 * Later sets start from what was just lifted, the first set from the suggested target.
 */
export function prefill(ex, setIdx) {
    const previous = ex.sets.filter((s) => s.number <= setIdx).at(-1);
    return {
        weight: previous ? previous.weight ?? null : ex.weight ?? null,
        reps: 0,
        suggestedReps: previous?.reps ?? ex.targetReps ?? ex.repsMin,
    };
}

/** Rep chips from the bottom of the range to the top (plus a couple above, for strong sets). */
export function repChoices(ex) {
    const max = ex.repsMax + 2;
    const out = [];
    for (let r = Math.max(1, ex.repsMin - 1); r <= max; r += 1) out.push(r);
    return out;
}

export function stepWeight(weight, direction, step = 2.5) {
    const base = Number(weight) || 0;
    const next = Math.round((base + direction * step) * 100) / 100;
    return next <= 0 ? (direction < 0 ? null : step) : next;
}

export function clampReps(n) {
    return Math.max(0, Math.min(500, Math.trunc(Number(n) || 0)));
}

/** "60 kg × 8, 8, 7" for the "last time" chip. */
export function describeLast(last) {
    if (!last || !last.sets?.length) return null;
    const fmt = (w) => (w === null || w === undefined ? 'BW' : `${Number(w)} kg`);
    const groups = [];
    for (const s of last.sets) {
        const g = groups.at(-1);
        if (g && g.weight === s.weight) g.reps.push(s.reps);
        else groups.push({ weight: s.weight, reps: [s.reps] });
    }
    return groups.map((g) => `${fmt(g.weight)} × ${g.reps.join(', ')}`).join(' · ');
}

export function totalVolume(exercises) {
    return exercises.reduce((sum, ex) => sum + ex.sets.reduce((s, set) => s + (Number(set.weight) || 0) * (Number(set.reps) || 0), 0), 0);
}

export function totalSets(exercises) {
    return exercises.reduce((n, ex) => n + ex.sets.length, 0);
}

/** One progress segment per exercise: 0..1 of its target sets. */
export function progressSegments(exercises) {
    return exercises.map((ex) => (ex.status === 'skipped' ? 1 : Math.min(1, ex.sets.length / Math.max(1, ex.targetSets))));
}

/** Compare two resume snapshots; the newer `savedAt` wins. */
export function pickNewestState(...states) {
    return states.filter((s) => s && typeof s.savedAt === 'number').sort((a, b) => b.savedAt - a.savedAt)[0] ?? null;
}

/** Keep a resume snapshot only if it still points inside the exercise list. */
export function sanitizeResume(state, exercises) {
    if (!state || !Number.isInteger(state.idx) || !Number.isInteger(state.setIdx)) return null;
    if (state.idx < 0 || state.idx >= exercises.length) return null;
    if (!['intro', 'work', 'rest', 'finish'].includes(state.phase)) return null;
    return state;
}
