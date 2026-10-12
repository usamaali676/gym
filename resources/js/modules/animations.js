/**
 * Keyframe animation library for the 3D demo.
 *
 * An animation "spec" is plain data:
 *   { name, duration, camera, setup: {ground, anchor, props(rig, ctx)}, base: pose, frames: [[t, pose], ...] }
 * `frames` run once per loop (t from 0 to 1, first and last pose equal) and are eased with a sine curve.
 * Poses use the joint vocabulary documented in humanoid.js. Adding an exercise = adding an entry to
 * REGISTRY (or pointing a slug at an existing pattern); no other code changes.
 */
import {
    addBar, addBench, addBlock, addCable, addDumbbells, addHandles, addPad, addPullupBar, addRack, NEUTRAL,
} from './humanoid.js';

const clamp01 = (v) => Math.min(1, Math.max(0, v));
const ease = (t) => 0.5 - 0.5 * Math.cos(Math.PI * clamp01(t));

const JOINT_KEYS = new Set(['E', 'P', 'T', 'el', 'wr', 'hF', 'hA', 'hR', 'kn', 'an']);
/** "lE" / "rkn" style per-limb override of a generic joint key ("E" / "kn"). */
const overrideBase = (key) => (key.length > 1 && (key[0] === 'l' || key[0] === 'r') && JOINT_KEYS.has(key.slice(1)) ? key.slice(1) : null);

/** Every key any frame mentions, so a pose always has the same shape. */
function keysOf(spec) {
    return (spec._keys ??= [...new Set(spec.frames.flatMap(([, pose]) => Object.keys(pose)))]);
}

/** Pose at normalised loop time t (0..1). */
export function poseAt(spec, t) {
    const frames = spec.frames;
    const time = ((t % 1) + 1) % 1;
    let i = 0;
    while (i < frames.length - 2 && time > frames[i + 1][0]) i += 1;
    const [t0, a] = frames[i];
    const [t1, b] = frames[Math.min(i + 1, frames.length - 1)];
    const k = t1 === t0 ? 1 : ease((time - t0) / (t1 - t0));

    const full = (pose) => ({ ...NEUTRAL, ...spec.base, ...pose });
    const A = full(a);
    const B = full(b);
    const out = { ...NEUTRAL, ...spec.base };

    // An unset per-limb override follows the generic joint value (never snaps to 0).
    const valueIn = (pose, key) => pose[key] ?? (overrideBase(key) ? pose[overrideBase(key)] : undefined) ?? 0;
    for (const key of new Set([...Object.keys(A), ...Object.keys(B), ...keysOf(spec)])) {
        const from = valueIn(A, key);
        const to = valueIn(B, key);
        out[key] = from + (to - from) * k;
    }
    return out;
}

/** One rep: start -> peak (down), brief pause, back (up), rest. Returns frames for `spec.frames`. */
const rep = (start, peak, { down = 0.46, pause = 0.08, up = 0.34 } = {}) => {
    const t1 = down;
    const t2 = Math.min(0.97, down + pause);
    const t3 = Math.min(0.99, t2 + up);
    return [[0, start], [t1, peak], [t2, peak], [t3, start], [1, start]];
};

/** Slow breathing sway for static holds. */
const holdPose = (pose, sway = { lean: 1.2, neck: 1 }) => [[0, pose], [0.5, { ...pose, ...Object.fromEntries(Object.entries(sway).map(([k, v]) => [k, (pose[k] ?? 0) + v])) }], [1, pose]];

const spec = (name, base, setup, frames, duration = 4, camera = 'side') => ({ name, base, setup, frames, duration, camera });

// ---------------------------------------------------------------------------------------------
// Stances
// ---------------------------------------------------------------------------------------------

const STAND = { ground: 'feet' };
// Seated lifts fix the pelvis at seat height (the legs may be off the floor, e.g. a leg extension).
const SEATED_BASE = { hF: 88, kn: 92, an: 6, hA: 7, rootY: 0.56 };
const seatedSetup = (extra = {}) => ({ ground: 'none', anchor: 'pelvis', ...extra });

/** Bench the figure sits or lies on, placed from the pelvis position of the first pose. */
const benchProps = ({ kind = 'flat', angle = 0 } = {}) => (rig, ctx) => {
    const y = ctx.pelvis.y - 0.115;
    if (kind === 'flat') return [addBench(rig, { kind: 'flat', y, z: ctx.pelvis.z - 0.32, length: 1.3 })];
    if (kind === 'seat') {
        return [
            addPad(rig, { position: [0, y - 0.035, ctx.pelvis.z + 0.02], size: [0.34, 0.07, 0.42] }),
            addPad(rig, { position: [0, y + 0.34, ctx.pelvis.z - 0.2], rotationX: 0.04, size: [0.34, 0.62, 0.07] }),
        ];
    }
    return [addBench(rig, { kind: 'incline', angle, y, z: ctx.pelvis.z + 0.02, length: 1.05 })];
};

const lyingStance = (angle = 0) => ({
    base: { rootPitch: angle - 90, hF: angle ? 24 : 0, kn: 92, an: 10, hA: 9, rootZ: 0 },
    setup: { ground: 'feet', anchor: 'pelvisXZ' },
});

// ---------------------------------------------------------------------------------------------
// Pressing
// ---------------------------------------------------------------------------------------------

function press({ angle = 0, implement = 'barbell', name = 'Bench press' } = {}) {
    const st = lyingStance(angle);
    const top = { E: 88, P: 88, el: 3 };
    // Solved numerically so the bar reaches the chest with a near-vertical forearm and the elbows dip just below the bench.
    const bottom = implement === 'dumbbell'
        ? { E: 42, P: -38, el: 123 }
        : { E: 35, P: -48, el: 123 };
    return spec(name, st.base, {
        ...st.setup,
        props: (rig, ctx) => [
            ...benchProps({ kind: angle ? 'incline' : 'flat', angle })(rig, ctx),
            ...(implement === 'barbell' ? [addBar(rig)] : [addDumbbells(rig)]),
            ...(implement === 'barbell' ? [addRack(rig, { z: ctx.pelvis.z - 0.55 })] : []),
        ],
    }, rep(top, bottom, { down: 0.5, pause: 0.07, up: 0.3 }), 4.2);
}

function machinePress({ name = 'Machine press', angle = 0 } = {}) {
    const base = { ...SEATED_BASE, rootPitch: -angle, lean: 0 };
    const top = { E: 82, P: 88, el: 6 };
    const bottom = { E: 55, P: 62, el: 100 };
    return spec(name, base, {
        ...seatedSetup(),
        props: (rig, ctx) => [...benchProps({ kind: 'seat' })(rig, ctx), addHandles(rig, { shape: 'bar' })],
    }, rep(top, bottom, { down: 0.45, up: 0.35 }), 3.6);
}

function pushUp({ incline = false, name = 'Push-up' } = {}) {
    const base = { rootPitch: incline ? 62 : 76, hF: -2, kn: 2, an: 0, hA: 3, lean: 0 };
    const top = { rootPitch: incline ? 62 : 76, E: 92, P: 90, el: 4 };
    const bottom = { rootPitch: incline ? 70 : 86, E: 58, P: 74, el: 98 };
    return spec(name, base, {
        ground: 'floor', anchor: 'pelvisXZ',
        props: incline ? (rig) => [addBlock(rig, { size: [0.5, 0.26, 0.34], position: [0, 0.13, 0.74] })] : undefined,
    }, rep(top, bottom, { down: 0.5, pause: 0.05, up: 0.4 }), 3.4, 'side');
}

function overheadPress({ implement = 'dumbbell', arnold = false, standing = false, name = 'Overhead press' } = {}) {
    const base = standing ? { lean: -2 } : { ...SEATED_BASE, rootPitch: -4 };
    const setup = standing
        ? { ground: 'feet', props: (rig) => [implement === 'barbell' ? addBar(rig) : addDumbbells(rig)] }
        : {
            ...seatedSetup(),
            props: (rig, ctx) => [...benchProps({ kind: 'seat' })(rig, ctx), implement === 'barbell' ? addBar(rig) : addDumbbells(rig)],
        };
    const top = { E: 172, P: 62, el: 4 };
    const bottom = arnold
        ? { E: 62, P: 82, el: 148, T: -70 }
        : { E: 82, P: 24, el: 92, T: 0 };
    const start = arnold ? { ...bottom } : bottom;
    return spec(name, base, setup, rep(start, top, { down: 0.45, pause: 0.07, up: 0.38 }).map(([t, p]) => [t, p]), arnold ? 4.6 : 4, 'front');
}

// ---------------------------------------------------------------------------------------------
// Isolation arms
// ---------------------------------------------------------------------------------------------

function lateralRaise({ mode = 'side', cable = false, name = 'Lateral raise' } = {}) {
    const base = { lean: mode === 'rear' ? 62 : 4, kn: 6 };
    const setup = { ground: 'feet', props: (rig) => [...(cable ? [addCable(rig, { anchor: [0.55, 0.25, 0.1], toBoth: false })] : []), addDumbbells(rig)] };
    let down;
    let up;
    if (mode === 'front') { down = { E: 8, P: 80, el: 8 }; up = { E: 88, P: 88, el: 6 }; }
    else if (mode === 'rear') { down = { E: 10, P: 80, el: 10 }; up = { E: 88, P: 4, el: 14 }; }
    else { down = { E: 7, P: 6, el: 12 }; up = { E: 88, P: 6, el: 14 }; }
    return spec(name, base, setup, rep(down, up, { down: 0.4, pause: 0.06, up: 0.44 }), 4, mode === 'rear' ? 'angle' : 'front');
}

function curl({ implement = 'barbell', neutral = false, incline = false, cable = false, name = 'Curl' } = {}) {
    const base = incline ? { rootPitch: -48, hF: 10, kn: 70, an: 6 } : { lean: 0 };
    const setup = incline
        ? { ground: 'feet', anchor: 'pelvisXZ', props: (rig, ctx) => [...benchProps({ kind: 'incline', angle: 90 - 48 })(rig, ctx), addDumbbells(rig)] }
        : {
            ground: 'feet',
            props: (rig) => [
                implement === 'barbell' ? addBar(rig, { length: 1.25, plate: 0.17 }) : addDumbbells(rig, { axis: neutral ? 'z' : 'x' }),
                ...(cable ? [addCable(rig, { anchor: [0, 0.2, 0.7], toBoth: false })] : []),
            ],
        };
    const down = incline ? { E: 28, P: 90, el: 6 } : { E: 6, P: 6, el: 6 };
    const up = incline ? { E: 30, P: 88, el: 140 } : { E: 14, P: 18, el: 142 };
    const wr = neutral ? { wr: 0 } : {};
    return spec(name, base, setup, rep({ ...down, ...wr }, { ...up, ...wr }, { down: 0.42, pause: 0.07, up: 0.42 }), 3.8);
}

function preacherCurl({ name = 'Preacher curl' } = {}) {
    const base = { ...SEATED_BASE, lean: 8 };
    return spec(name, base, {
        ...seatedSetup(),
        props: (rig, ctx) => [
            addPad(rig, { position: [0, ctx.pelvis.y - 0.115 - 0.035, ctx.pelvis.z + 0.02], size: [0.34, 0.07, 0.42] }),
            addPad(rig, { position: [0, ctx.pelvis.y + 0.4, ctx.pelvis.z + 0.33], rotationX: -0.75, size: [0.36, 0.5, 0.07] }),
            addBar(rig, { length: 1.1, plate: 0.15 }),
        ],
    }, rep({ E: 50, P: 90, el: 6 }, { E: 52, P: 90, el: 138 }, { down: 0.45, pause: 0.07, up: 0.4 }), 4);
}

function spiderCurl({ name = 'Spider curl' } = {}) {
    const base = { rootPitch: 40, hF: -12, kn: 3, an: 4, lean: 0 };
    return spec(name, base, {
        ground: 'feet', anchor: 'pelvisXZ',
        props: (rig, ctx) => [
            addPad(rig, { position: [0, ctx.pelvis.y + 0.14, ctx.pelvis.z + 0.28], rotationX: 0.75, size: [0.36, 0.1, 0.9] }),
            addBar(rig, { length: 1.1, plate: 0.15 }),
        ],
    }, rep({ E: 90, P: 90, el: 4 }, { E: 92, P: 90, el: 138 }, { down: 0.45, pause: 0.07, up: 0.4 }), 3.8);
}

function triceps({ mode = 'pushdown', reverse = false, rope = false, name = 'Triceps' } = {}) {
    if (mode === 'pushdown') {
        const base = { lean: 10, kn: 8 };
        const setup = { ground: 'feet', props: (rig) => [addCable(rig, { anchor: [0, 2.1, 0.25], toBoth: false }), addHandles(rig, { shape: rope ? 'ball' : 'bar' })] };
        const topPose = { E: 18, P: 70, el: 100, ...(reverse ? { wr: 90 } : {}) };
        const bottomPose = { E: 10, P: 20, el: 6, ...(reverse ? { wr: 90 } : {}) };
        return spec(name, base, setup, rep(topPose, bottomPose, { down: 0.4, pause: 0.1, up: 0.4 }), 3.4);
    }
    if (mode === 'overhead') {
        const base = { lean: 18, hF: 14, kn: 14, an: 4 };
        const setup = { ground: 'feet', props: (rig) => [addCable(rig, { anchor: [0, 1.3, -0.45], toBoth: false }), addHandles(rig, { shape: 'ball' })] };
        return spec(name, base, setup, rep({ E: 150, P: 72, el: 128 }, { E: 160, P: 78, el: 8 }, { down: 0.42, up: 0.4 }), 3.8);
    }
    if (mode === 'skull') {
        const st = lyingStance(0);
        return spec(name, st.base, {
            ...st.setup,
            props: (rig, ctx) => [...benchProps({ kind: 'flat' })(rig, ctx), addBar(rig, { length: 1.2, plate: 0.16, offset: [0, 0.015, 0] })],
        }, rep({ E: 90, P: 90, el: 4 }, { E: 94, P: 90, el: 116 }, { down: 0.5, pause: 0.07, up: 0.33 }), 4);
    }
    if (mode === 'ohdb') {
        const base = { ...SEATED_BASE, rootPitch: -6 };
        return spec(name, base, { ...seatedSetup(), props: (rig, ctx) => [...benchProps({ kind: 'seat' })(rig, ctx), addDumbbells(rig)] },
            rep({ E: 152, P: 80, el: 136 }, { E: 164, P: 82, el: 10 }, { down: 0.42, up: 0.4 }), 4);
    }
    if (mode === 'kickback') {
        const base = { lean: 66, kn: 16, hF: 24, an: 4 };
        return spec(name, base, { ground: 'feet', props: (rig) => [addDumbbells(rig, { only: 'R' })] },
            rep({ rE: 62, rP: 90, rel: 90, lE: 20, lel: 20 }, { rE: 62, rP: 90, rel: 4, lE: 20, lel: 20 }, { down: 0.4, up: 0.4 }), 3.4);
    }
    // bench dips
    const base = { rootPitch: -18, hF: 38, kn: 12, an: 8, lean: 0 };
    return spec(name, base, {
        ground: 'feet', anchor: 'pelvisXZ',
        props: (rig, ctx) => [addPad(rig, { position: [0, 0.43, ctx.pelvis.z - 0.34], size: [0.5, 0.07, 0.3], legs: true })],
    }, rep({ E: 12, P: -50, el: 8 }, { E: 20, P: -50, el: 100 }, { down: 0.5, up: 0.4 }), 3.6);
}

// ---------------------------------------------------------------------------------------------
// Back
// ---------------------------------------------------------------------------------------------

function bentRow({ implement = 'barbell', name = 'Bent-over row' } = {}) {
    const base = { lean: 52, hF: 22, kn: 26, an: 6 };
    const setup = { ground: 'feet', props: (rig) => [implement === 'barbell' ? addBar(rig) : addDumbbells(rig)] };
    return spec(name, base, setup, rep({ E: 12, P: 82, el: 6 }, { E: 52, P: 82, el: 100 }, { down: 0.4, pause: 0.1, up: 0.4 }), 3.8);
}

function singleArmRow({ name = 'Single-arm dumbbell row' } = {}) {
    const base = { lean: 80, hF: 8, kn: 12, an: 4, lE: 30, lP: 70, lel: 6, rE: 12, rP: 80, rel: 6, lhF: 52, lkn: 70, rhA: 6 };
    return spec(name, base, {
        ground: 'feet',
        props: (rig, ctx) => [
            addPad(rig, { position: [0.26, ctx.pelvis.y - 0.18, ctx.pelvis.z + 0.2], size: [0.3, 0.07, 0.8], legs: true }),
            // Neutral grip: the dumbbell lies along the body, so its plates stay clear of the supporting knee.
            addDumbbells(rig, { only: 'R', axis: 'z' }),
        ],
    }, rep({ rE: 10, rP: 84, rel: 6 }, { rE: 56, rP: 82, rel: 108 }, { down: 0.42, pause: 0.1, up: 0.38 }), 3.6, 'side');
}

function seatedRow({ name = 'Seated cable row', machine = false } = {}) {
    const base = { hF: 70, kn: 24, an: 8, lean: -4, rootPitch: 0, rootY: 0.56 };
    return spec(name, base, {
        ground: 'none', anchor: 'pelvis',
        props: (rig, ctx) => [
            addPad(rig, { position: [0, ctx.pelvis.y - 0.15, ctx.pelvis.z], size: [0.4, 0.07, 0.4] }),
            ...(machine ? [addPad(rig, { position: [0, ctx.pelvis.y + 0.22, ctx.pelvis.z + 0.3], size: [0.28, 0.5, 0.07] })] : [addCable(rig, { anchor: [0, 0.35, 1.1], toBoth: true })]),
            addHandles(rig),
        ],
    }, rep({ E: 78, P: 90, el: 6, lean: 10 }, { E: 14, P: 70, el: 112, lean: -6 }, { down: 0.45, pause: 0.12, up: 0.3 }), 3.6);
}

function latPulldown({ wide = false, name = 'Lat pulldown' } = {}) {
    const base = { ...SEATED_BASE, lean: -8 };
    const spread = wide ? 52 : 34;
    return spec(name, base, {
        ...seatedSetup(),
        props: (rig, ctx) => [
            ...benchProps({ kind: 'seat' })(rig, ctx),
            addCable(rig, { anchor: [0, 2.2, ctx.pelvis.z + 0.1], toBoth: true }),
            addHandles(rig),
        ],
    }, rep({ E: 164, P: spread, el: 6 }, { E: 68, P: spread - 10, el: 100, lean: -14 }, { down: 0.45, pause: 0.1, up: 0.35 }), 3.8, 'front');
}

function straightArm({ name = 'Straight-arm pulldown' } = {}) {
    const base = { lean: 22, hF: 12, kn: 12, an: 4 };
    return spec(name, base, {
        ground: 'feet',
        props: (rig) => [addCable(rig, { anchor: [0, 2.15, 0.3], toBoth: true }), addHandles(rig)],
    }, rep({ E: 128, P: 82, el: 8 }, { E: 22, P: 84, el: 6 }, { down: 0.45, pause: 0.12, up: 0.38 }), 3.6);
}

function pullUp({ name = 'Pull-up' } = {}) {
    const base = { hF: -12, kn: 58, an: 16, lean: -2 };
    return spec(name, base, {
        ground: 'hands', handsY: 2.2,
        props: (rig) => [addPullupBar(rig, { y: 2.2, width: 1.0 })],
    }, rep({ E: 168, P: 38, el: 4 }, { E: 64, P: 24, el: 118, lean: -8 }, { down: 0.52, pause: 0.08, up: 0.34 }), 4.4, 'front');
}

function facePull({ name = 'Face pull' } = {}) {
    const base = { lean: -6, hF: 4, kn: 6 };
    return spec(name, base, {
        ground: 'feet',
        props: (rig) => [addCable(rig, { anchor: [0, 1.65, 0.85], toBoth: true }), addHandles(rig, { shape: 'ball' })],
    }, rep({ E: 78, P: 88, el: 8 }, { E: 70, P: 28, el: 98, T: 40 }, { down: 0.45, pause: 0.14, up: 0.32 }), 3.6, 'front');
}

function inverted({ name = 'Inverted row' } = {}) {
    const base = { rootPitch: 78, hF: 0, kn: 4, an: 0, lean: 0 };
    return spec(name, base, { ground: 'feet', anchor: 'pelvisXZ', props: (rig) => [addPullupBar(rig, { y: 1.0, width: 1.1 })] },
        rep({ E: 168, P: 90, el: 4, rootPitch: 74 }, { E: 70, P: 86, el: 104, rootPitch: 74 }, { down: 0.5, up: 0.4 }), 3.8);
}

// ---------------------------------------------------------------------------------------------
// Legs
// ---------------------------------------------------------------------------------------------

function squat({ implement = 'barbell', mode = 'squat', name = 'Squat' } = {}) {
    const top = { hF: 4, kn: 4, an: 2, lean: 4, E: 20, P: 70, el: 90 };
    const bottom = { hF: 100, kn: 112, an: 22, lean: 34, E: 24, P: 72, el: 94 };
    let held = implement === 'barbell'
        ? { E: 86, P: 4, el: 130, T: 0 }
        : implement === 'goblet'
            ? { E: 52, P: 82, el: 130 }
            : { E: 82, P: 88, el: 6 };
    if (implement === 'front') held = { E: 78, P: 80, el: 138 };
    const frames = rep({ ...top, ...held, hA: 14, hR: -8 }, { ...bottom, ...held, hA: 16, hR: -8 }, { down: 0.5, pause: 0.07, up: 0.33 });
    const setup = {
        ground: 'feet',
        props: (rig, ctx) => [
            ...(implement === 'barbell' || implement === 'front' ? [addBar(rig, { offset: [0, 0.03, implement === 'front' ? 0.05 : -0.13] }), addRack(rig, { z: -0.25 })] : []),
            ...(implement === 'goblet' ? [addDumbbells(rig, { axis: 'z' })] : []),
            ...(ctx ? [] : []),
        ],
    };
    return spec(name, { hA: 14 }, setup, frames, 4.4);
}

function splitSquat({ name = 'Split squat', walking = false } = {}) {
    const base = { lean: 6, lE: 10, rE: 10, lP: 70, rP: 70, lel: 10, rel: 10 };
    const top = { lhF: 12, lkn: 8, rhF: -14, rkn: 14, lan: 6, ran: 20 };
    const bottom = { lhF: 80, lkn: 92, rhF: -20, rkn: 106, lan: 18, ran: 40, lean: 10 };
    return spec(name, base, {
        ground: 'feet',
        props: (rig) => [addDumbbells(rig, { axis: 'z' }), ...(walking ? [] : [addPad(rig, { position: [0, 0.46, -0.78], size: [0.4, 0.07, 0.3], legs: true })])],
    }, rep(top, bottom, { down: 0.5, pause: 0.06, up: 0.38 }), 4.2);
}

function hackSquat({ name = 'Hack squat' } = {}) {
    const base = { rootPitch: -26, hF: 10, kn: 6, an: 4, lean: 0 };
    return spec(name, base, {
        ground: 'feet', anchor: 'pelvisXZ',
        props: (rig, ctx) => [addPad(rig, { position: [0, ctx.pelvis.y + 0.25, ctx.pelvis.z - 0.28], rotationX: -0.46, size: [0.45, 1.1, 0.07], follow: 'pelvis' })],
    }, rep({ hF: 8, kn: 6, an: 2 }, { hF: 92, kn: 108, an: 18 }, { down: 0.5, up: 0.4 }), 4.2);
}

function legPress({ name = 'Leg press' } = {}) {
    const base = { rootPitch: -50, hF: 60, kn: 82, an: 4, lean: 0 };
    return spec(name, base, {
        ground: 'none', anchor: 'pelvis',
        // The foot plate travels well past the figure, so look at the middle of the whole machine.
        frame: { target: [0, 0.95, 0.55], distance: 4.8 },
        props: (rig, ctx) => [
            // The seat back is tilted to match the torso (rootPitch -50), 0.42 m up the back and 0.145 m behind it.
            addPad(rig, { position: [0, ctx.pelvis.y + 0.16, ctx.pelvis.z - 0.415], rotationX: -0.873, size: [0.42, 0.9, 0.07] }),
            // The sled carries the foot plate, so it travels with the feet instead of hanging in the air.
            addPad(rig, { position: [0, ctx.pelvis.y + 0.6, ctx.pelvis.z + 0.95], rotationX: 0.75, size: [0.8, 0.5, 0.06], follow: 'ankles' }),
        ],
        camera: 'side',
    }, rep({ hF: 98, kn: 118, an: 22, rootY: 0.78, rootZ: 0 }, { hF: 28, kn: 8, an: 4, rootY: 0.78, rootZ: 0 }, { down: 0.5, up: 0.4 }).map(([t, p]) => [t, { ...p, rootY: 0.78 }]), 4, 'side');
}

function legExtension({ name = 'Leg extension' } = {}) {
    const base = { ...SEATED_BASE, rootPitch: -8, E: 14, P: 60, el: 70 };
    return spec(name, base, {
        ...seatedSetup(),
        props: (rig, ctx) => [...benchProps({ kind: 'seat' })(rig, ctx)],
    }, rep({ kn: 92, an: 6 }, { kn: 4, an: 14 }, { down: 0.4, pause: 0.12, up: 0.4 }), 3.6);
}

function legCurl({ lying = false, name = 'Leg curl' } = {}) {
    if (lying) {
        const base = { rootPitch: 90, hF: -4, kn: 4, an: 6, lean: 0, E: 70, P: 40, el: 110 };
        return spec(name, base, {
            ground: 'none', anchor: 'pelvis',
            props: (rig, ctx) => [addBench(rig, { kind: 'flat', y: ctx.pelvis.y - 0.115, z: ctx.pelvis.z + 0.3, length: 1.3 })],
        }, rep({ kn: 4, rootY: 0.55 }, { kn: 112, rootY: 0.55 }, { down: 0.45, up: 0.4 }).map(([t, p]) => [t, { ...p, rootY: 0.55 }]), 3.6);
    }
    const base = { ...SEATED_BASE, rootPitch: -10, E: 14, P: 60, el: 70 };
    return spec(name, base, { ...seatedSetup(), props: (rig, ctx) => [...benchProps({ kind: 'seat' })(rig, ctx)] },
        rep({ kn: 6, an: 6 }, { kn: 104, an: 6 }, { down: 0.4, pause: 0.12, up: 0.4 }), 3.6);
}

function hinge({ implement = 'barbell', name = 'Romanian deadlift' } = {}) {
    const hold = implement === 'barbell' ? { E: 8, P: 70, el: 4 } : { E: 6, P: 60, el: 6 };
    return spec(name, { hA: 6 }, {
        ground: 'feet',
        props: (rig) => [implement === 'barbell' ? addBar(rig) : addDumbbells(rig)],
    }, rep({ ...hold, lean: 2, hF: 0, kn: 6 }, { ...hold, lean: 74, hF: 10, kn: 28, E: 14, P: 74 }, { down: 0.52, pause: 0.08, up: 0.32 }), 4.4);
}

function bridge({ weighted = false, name = 'Glute bridge' } = {}) {
    // Floor bridge: shoulders stay down while the hips rise. Hip thrust: the upper back rests on a bench, so the torso swings from
    // sloping up toward the head to level with the floor.
    const start = weighted ? { rootPitch: -62, hF: 62, kn: 100 } : { rootPitch: -88, hF: 52, kn: 120 };
    const top = weighted ? { rootPitch: -90, hF: 2, kn: 90, lean: 4 } : { rootPitch: -106, hF: 4, kn: 88, lean: 6 };
    const base = { an: 12, hA: 10, E: 6, P: 12, lean: 0, ...start };
    return spec(name, base, {
        ground: 'feet', anchor: 'pelvisXZ',
        props: (rig, ctx) => [
            ...(weighted ? [addBench(rig, { kind: 'flat', y: 0.42, z: ctx.pelvis.z - 0.55, length: 0.5 }), addBar(rig, { offset: [0, 0.03, 0] })] : []),
        ],
    }, rep(start, top, { down: 0.4, pause: 0.12, up: 0.4 }), 3.4);
}

function calfRaise({ seated = false, single = false, name = 'Calf raise' } = {}) {
    const rise = { an: -36 };
    const base = seated ? { ...SEATED_BASE, kn: 92 } : { hA: 4, ...(single ? { lhF: 70, lkn: 100 } : {}) };
    const setup = seated
        ? { ...seatedSetup(), props: (rig, ctx) => [...benchProps({ kind: 'seat' })(rig, ctx)] }
        : { ground: 'feet', props: (rig) => (single ? [] : [addBlock(rig, { size: [0.5, 0.08, 0.3], position: [0, -0.04, 0.0] })]) };
    // Seated: the toes stay planted while the heels rise, so the knees (and the pelvis anchored to them) lift with the ankle.
    const lift = seated ? { rootY: SEATED_BASE.rootY + 0.08 } : {};
    return spec(name, base, setup, rep({ an: 6, lan: 6, ran: 6 }, { an: -38, lan: single ? 6 : -38, ran: -38, ...lift }, { down: 0.4, pause: 0.14, up: 0.4 }), 3.2, 'side');
}

function shrug({ name = 'Shrug' } = {}) {
    return spec(name, { E: 4, P: 4, el: 4 }, { ground: 'feet', props: (rig) => [addDumbbells(rig, { axis: 'z' })] },
        rep({ shrug: 0 }, { shrug: 0.075 }, { down: 0.4, pause: 0.18, up: 0.38 }), 3, 'front');
}

// ---------------------------------------------------------------------------------------------
// Chest isolation / machines
// ---------------------------------------------------------------------------------------------

function cableFly({ name = 'Cable fly', direction = 'down' } = {}) {
    const base = { lean: 14, hF: 22, hA: 4, kn: 18, an: 4, lhF: 26, rhF: -6 };
    const wide = direction === 'down' ? { E: 84, P: 10, el: 22 } : { E: 70, P: 6, el: 22 };
    const together = direction === 'down' ? { E: 40, P: 78, el: 18 } : { E: 90, P: 80, el: 18 };
    return spec(name, base, {
        ground: 'feet',
        props: (rig) => [addCable(rig, { anchor: [0.8, direction === 'down' ? 2.1 : 0.25, 0.1], toBoth: true, split: true }), addHandles(rig, { shape: 'ball' })],
    }, rep(wide, together, { down: 0.42, pause: 0.12, up: 0.4 }), 3.6, 'front');
}

function dumbbellFly({ name = 'Dumbbell fly' } = {}) {
    const st = lyingStance(0);
    return spec(name, st.base, {
        ...st.setup,
        props: (rig, ctx) => [...benchProps({ kind: 'flat' })(rig, ctx), addDumbbells(rig)],
    }, rep({ E: 90, P: 88, el: 16 }, { E: 84, P: 8, el: 30 }, { down: 0.5, up: 0.4 }), 4, 'front');
}

function pullover({ name = 'Dumbbell pullover' } = {}) {
    const st = lyingStance(0);
    return spec(name, st.base, {
        ...st.setup,
        props: (rig, ctx) => [...benchProps({ kind: 'flat' })(rig, ctx), addDumbbells(rig, { axis: 'z' })],
    }, rep({ E: 90, P: 88, el: 12 }, { E: 168, P: 88, el: 22 }, { down: 0.5, up: 0.4 }), 4, 'side');
}

function pecDeck({ reverse = false, name = 'Pec deck' } = {}) {
    const base = { ...SEATED_BASE, rootPitch: reverse ? 0 : -6, lean: reverse ? 8 : 0 };
    const open = reverse ? { E: 88, P: 82, el: 10 } : { E: 86, P: 6, el: 40 };
    const closed = reverse ? { E: 86, P: 6, el: 12 } : { E: 82, P: 86, el: 30 };
    return spec(name, base, {
        ...seatedSetup(),
        props: (rig, ctx) => [...benchProps({ kind: 'seat' })(rig, ctx), addHandles(rig, { shape: 'ball' })],
    }, rep(reverse ? open : open, reverse ? closed : closed, { down: 0.42, pause: 0.12, up: 0.4 }), 3.6, reverse ? 'angle' : 'front');
}

// ---------------------------------------------------------------------------------------------
// Core, cardio, carries
// ---------------------------------------------------------------------------------------------

function plank({ name = 'Plank' } = {}) {
    const base = { rootPitch: 84, hF: -2, kn: 2, an: 0, hA: 3, E: 90, P: 90, el: 90, lean: 0, lan: 22, ran: 22 };
    return spec(name, base, { ground: 'floor', anchor: 'pelvisXZ' }, holdPose({ rootPitch: 84 }, { rootPitch: 0.6, neck: 1 }), 5);
}

function deadBug({ name = 'Dead bug' } = {}) {
    const base = { rootPitch: -90, hF: 90, kn: 92, an: 0, E: 90, P: 88, el: 4, hA: 8 };
    return spec(name, base, { ground: 'none', anchor: 'pelvis', props: (rig, ctx) => [addPad(rig, { position: [0, ctx.pelvis.y - 0.125, ctx.pelvis.z - 0.2], size: [0.6, 0.04, 1.4] })] },
        [[0, { rootY: 0.2 }], [0.25, { rootY: 0.2, lE: 168, lP: 88, rhF: 8, rkn: 8 }], [0.5, { rootY: 0.2 }], [0.75, { rootY: 0.2, rE: 168, rP: 88, lhF: 8, lkn: 8 }], [1, { rootY: 0.2 }]], 5);
}

// The tower stands well in front of the kneeling lifter so the head never enters the weight stack outline.
function cableCrunch({ name = 'Cable crunch' } = {}) {
    const base = { hF: 4, kn: 100, hA: 10, an: -16, E: 120, P: 60, el: 125 };
    return spec(name, base, {
        ground: 'knees',
        props: (rig) => [addCable(rig, { anchor: [0, 2.1, 0.75], toBoth: false }), addHandles(rig, { shape: 'ball' })],
    }, rep({ lean: -2, hF: 4 }, { lean: 62, hF: 52, E: 80 }, { down: 0.4, pause: 0.1, up: 0.42 }), 3.4);
}

function hangingKnee({ name = 'Hanging knee raise' } = {}) {
    const base = { E: 170, P: 35, el: 4, an: -10 };
    return spec(name, base, { ground: 'hands', handsY: 2.2, props: (rig) => [addPullupBar(rig, { y: 2.2, width: 1.0 })] },
        rep({ hF: 0, kn: 12, lean: 0 }, { hF: 96, kn: 110, lean: 12 }, { down: 0.42, pause: 0.1, up: 0.42 }), 3.4);
}

function jumpingJacks({ name = 'Jumping jacks' } = {}) {
    const closed = { E: 8, P: 10, el: 6, hA: 3, kn: 8, rootY: 0 };
    const open = { E: 168, P: 6, el: 6, hA: 22, kn: 10, rootY: 0.1 };
    return spec(name, {}, { ground: 'none', anchor: undefined }, [
        [0, { ...closed }], [0.25, { ...open, rootY: 0.13 }], [0.5, { ...closed }], [0.75, { ...open, rootY: 0.13 }], [1, { ...closed }],
    ], 2, 'front');
}

function carry({ name = "Farmer's walk" } = {}) {
    const base = { E: 4, P: 4, el: 6, lean: 2 };
    return spec(name, base, { ground: 'feet', props: (rig) => [addDumbbells(rig, { axis: 'z' })] }, [
        [0, { lhF: 24, rhF: -22, lkn: 6, rkn: 36, lE: 10, rE: -4 }], [0.5, { lhF: -22, rhF: 24, lkn: 36, rkn: 6, lE: -4, rE: 10 }], [1, { lhF: 24, rhF: -22, lkn: 6, rkn: 36, lE: 10, rE: -4 }],
    ], 1.6, 'side');
}

// ---------------------------------------------------------------------------------------------
// Mobility
// ---------------------------------------------------------------------------------------------

const circle = (n, make) => Array.from({ length: n + 1 }, (_, i) => [i / n, make((i / n) * Math.PI * 2)]);

const MOBILITY = {
    'arm-circles': () => spec('Arm circles', { E: 90, P: 4, el: 6 }, { ground: 'feet' }, circle(8, (a) => ({ E: 88 + Math.sin(a) * 6, P: 45 + Math.cos(a) * 45, el: 4 })), 3.2, 'front'),
    'leg-swings': () => spec('Leg swings', { lE: 30, lP: 5, rE: 30, rP: 5, lel: 40, rel: 40 }, { ground: 'feet', props: (rig, ctx) => [addPad(rig, { position: [0.45, ctx.pelvis.y, 0], size: [0.06, 1.7, 0.4] })] },
        [[0, { rhF: 55 }], [0.5, { rhF: -25 }], [1, { rhF: 55 }]], 2.4, 'side'),
    'hip-circles': () => spec('Hip circles', { lE: 40, lP: 5, rE: 40, rP: 5, lel: 90, rel: 90, hA: 12, kn: 10 }, { ground: 'feet' },
        circle(8, (a) => ({ rootX: Math.sin(a) * 0.05, rootZ: Math.cos(a) * 0.05, pelvisTilt: Math.cos(a) * 6, sb: Math.sin(a) * 4 })), 3.2, 'angle'),
    'cat-cow': () => spec('Cat-cow', { rootPitch: 84, hF: 90, kn: 90, an: -20, E: 90, P: 90, el: 2, hA: 4 }, { ground: 'knees', anchor: 'pelvisXZ' },
        [[0, { lean: -18, neck: -22, pelvisTilt: 14 }], [0.5, { lean: 22, neck: 26, pelvisTilt: -16 }], [1, { lean: -18, neck: -22, pelvisTilt: 14 }]], 4.5, 'side'),
    'worlds-greatest-stretch': () => spec("World's greatest stretch", { lhF: 85, lkn: 90, rhF: -15, rkn: 20, lean: 38, lan: 14, ran: 30 }, { ground: 'feet' },
        [[0, { tw: 0, lE: 62, lP: 90, lel: 4, rE: 62, rP: 90, rel: 4 }], [0.5, { tw: 70, lE: 84, lP: 90, lel: 4, rE: 160, rP: 88, rel: 4 }], [1, { tw: 0, lE: 62, lP: 90, lel: 4, rE: 62, rP: 90, rel: 4 }]], 5, 'angle'),
    // Side-lying (roll, not pitch): the bottom arm stays reaching forward on the floor while the top arm sweeps forward -> up -> back.
    'thoracic-open-book': () => spec('Thoracic open book', { rootPitch: 0, rootRoll: 90, hF: 60, kn: 100, rE: 80, rP: 90, rel: 6, lE: 90, lP: 90, lel: 4, hA: 4 }, { ground: 'none', anchor: 'pelvis', props: (rig, ctx) => [addPad(rig, { position: [0, 0.03, ctx.pelvis.z], size: [0.7, 0.04, 1.6] })] },
        [[0, { rootY: 0.3, tw: 0, lP: 90 }], [0.5, { rootY: 0.3, tw: 45, lP: -70 }], [1, { rootY: 0.3, tw: 0, lP: 90 }]], 5, 'angle'),
    inchworm: () => spec('Inchworm', { E: 90, P: 90, el: 2 }, { ground: 'floor', anchor: 'pelvisXZ' },
        [[0, { lean: 118, hF: 0, kn: 2, rootPitch: 0, E: 168, P: 90, lE: 168 }], [0.5, { rootPitch: 76, lean: 0, E: 90, P: 90, kn: 2 }], [1, { lean: 118, hF: 0, kn: 2, rootPitch: 0, E: 168, P: 90 }]], 5, 'side'),
    'doorway-chest-stretch': () => spec('Doorway chest stretch', { E: 92, P: 4, el: 90, hF: 14, kn: 12 }, { ground: 'feet', props: (rig) => [addBlock(rig, { size: [0.08, 2.1, 0.08], position: [0.62, 1.05, 0.05] }), addBlock(rig, { size: [0.08, 2.1, 0.08], position: [-0.62, 1.05, 0.05] })] },
        holdPose({ lean: -6 }, { lean: 7 }), 4, 'front'),
    'cross-body-shoulder-stretch': () => spec('Cross-body shoulder stretch', { rE: 6, rP: 20, rel: 8, lE: 86, lP: 118, lel: 60 }, { ground: 'feet' }, holdPose({ tw: 6 }, { tw: 3 }), 4, 'front'),
    'overhead-triceps-stretch': () => spec('Overhead triceps stretch', { rE: 8, rP: 20, rel: 8, lE: 172, lP: 78, lel: 138, rhF: 0 }, { ground: 'feet' }, holdPose({ sb: -4 }, { sb: -4 }), 4, 'front'),
    'wall-biceps-stretch': () => spec('Wall biceps stretch', { rE: 86, rP: 14, rel: 2, rT: 90, lE: 8, lP: 12, lel: 8 }, { ground: 'feet', props: (rig) => [addBlock(rig, { size: [0.08, 2.1, 1.0], position: [-0.75, 1.05, 0.0], ghost: true })] }, holdPose({ tw: -24 }, { tw: -3 }), 4, 'front'),
    'childs-pose': () => spec("Child's pose", { rootPitch: 88, hF: 130, kn: 140, an: -30, E: 168, P: 90, el: 4, lean: 14, hA: 10 }, { ground: 'knees', anchor: 'pelvisXZ' }, holdPose({ lean: 14 }, { lean: 2, neck: 1 }), 5, 'side'),
    'hamstring-stretch': () => spec('Seated hamstring stretch', { rootPitch: -4, rootY: 0.15, lhF: 90, lkn: 4, rhF: 98, rkn: 160, rhA: 55, ran: 60, lean: 34, E: 70, P: 90, el: 6, an: 10 }, { ground: 'none', anchor: 'pelvis' }, holdPose({ lean: 34 }, { lean: 6 }), 4.5, 'side'),
    'standing-quad-stretch': () => spec('Standing quad stretch', { lhF: -8, lkn: 4, rhF: -6, rkn: 140, rE: 28, rP: -28, rel: 110, lE: 80, lP: 80, lel: 20 }, { ground: 'feet' }, holdPose({ lean: 0 }, { lean: 1 }), 4, 'side'),
    'hip-flexor-stretch': () => spec('Hip flexor stretch', { lhF: 84, lkn: 92, rhF: -28, rkn: 96, lan: 8, ran: -30, lean: -4, E: 70, P: 86, el: 8 }, { ground: 'knees', anchor: 'pelvisXZ' }, holdPose({ lean: -4 }, { lean: 3 }), 4.5, 'side'),
    'figure-four-glute-stretch': () => spec('Figure-four glute stretch', { rootPitch: -90, lhF: 96, lkn: 100, rhF: 82, rkn: 100, rhA: 52, rhR: 30, E: 66, P: 70, el: 60, an: 10 }, { ground: 'none', anchor: 'pelvis', props: (rig, ctx) => [addPad(rig, { position: [0, 0.03, ctx.pelvis.z - 0.2], size: [0.7, 0.04, 1.8] })] },
        holdPose({ rootY: 0.2 }, { rootY: 0.2 }), 5, 'angle'),
    'calf-wall-stretch': () => spec('Calf wall stretch', { lean: 16, lhF: 16, lkn: 36, rhF: -14, rkn: 2, ran: 14, lan: 6, E: 88, P: 88, el: 12 }, { ground: 'feet', props: (rig) => [addBlock(rig, { size: [1.2, 2.1, 0.08], position: [0, 1.05, 0.72], ghost: true })] }, holdPose({ lean: 16 }, { lean: 4 }), 4, 'side'),
    'neck-side-stretch': () => spec('Neck side stretch', { sb: 0, rE: 6, rP: 12, rel: 8, lE: 150, lP: 78, lel: 128 }, { ground: 'feet' }, holdPose({ rootRoll: 0, sb: 12 }, { sb: 2 }), 4, 'front'),
};

// ---------------------------------------------------------------------------------------------
// Registry: exercise slug -> animation
// ---------------------------------------------------------------------------------------------

const REGISTRY = {
    // --- plan: Monday / Thursday push ---
    'flat-barbell-bench-press': () => press({ implement: 'barbell', name: 'Flat barbell bench press' }),
    'incline-dumbbell-press': () => press({ angle: 30, implement: 'dumbbell', name: 'Incline dumbbell press' }),
    'cable-lower-chest-fly': () => cableFly({ direction: 'down', name: 'Cable lower chest fly' }),
    'seated-db-overhead-press': () => overheadPress({ implement: 'dumbbell', name: 'Seated dumbbell overhead press' }),
    'side-lateral-raise': () => lateralRaise({ mode: 'side', name: 'Side lateral raise' }),
    'skull-crushers': () => triceps({ mode: 'skull', name: 'Skull crushers' }),
    'rope-tricep-pushdown': () => triceps({ mode: 'pushdown', rope: true, name: 'Rope triceps pushdown' }),
    'arnold-press': () => overheadPress({ implement: 'dumbbell', arnold: true, name: 'Arnold press' }),
    'reverse-pec-deck-rear-delts': () => pecDeck({ reverse: true, name: 'Reverse pec deck' }),
    'incline-bench-press': () => press({ angle: 30, implement: 'barbell', name: 'Incline bench press' }),
    'butterfly-machine': () => pecDeck({ name: 'Butterfly machine' }),
    'cable-overhead-tricep-extension': () => triceps({ mode: 'overhead', name: 'Cable overhead triceps extension' }),
    'reverse-grip-tricep-pushdown': () => triceps({ mode: 'pushdown', reverse: true, name: 'Reverse-grip pushdown' }),
    // --- plan: pull ---
    'barbell-row': () => bentRow({ implement: 'barbell', name: 'Barbell row' }),
    'lat-pulldown': () => latPulldown({ name: 'Lat pulldown' }),
    'seated-cable-row': () => seatedRow({ name: 'Seated cable row' }),
    'straight-arm-pulldown': () => straightArm(),
    'face-pull': () => facePull(),
    'barbell-curl': () => curl({ implement: 'barbell', name: 'Barbell curl' }),
    'dumbbell-hammer-curl': () => curl({ implement: 'dumbbell', neutral: true, name: 'Hammer curl' }),
    'pull-ups-assisted-pull-ups': () => pullUp(),
    'single-arm-dumbbell-row': () => singleArmRow(),
    'shrugs-db-or-barbell': () => shrug(),
    'spider-curl': () => spiderCurl(),
    'preacher-curl': () => preacherCurl(),
    // --- plan: legs ---
    'barbell-squat': () => squat({ implement: 'barbell', name: 'Barbell squat' }),
    'leg-press': () => legPress(),
    'romanian-deadlift': () => hinge({ implement: 'barbell' }),
    'hamstring-curl': () => legCurl({ name: 'Hamstring curl' }),
    'leg-extension': () => legExtension(),
    'standing-calf-raise': () => calfRaise({ name: 'Standing calf raise' }),
    // --- alternatives ---
    'dumbbell-bench-press': () => press({ implement: 'dumbbell', name: 'Dumbbell bench press' }),
    'machine-chest-press': () => machinePress({ name: 'Machine chest press' }),
    'incline-push-up': () => pushUp({ incline: true, name: 'Incline push-up' }),
    'dumbbell-fly': () => dumbbellFly(),
    'machine-shoulder-press': () => machinePress({ name: 'Machine shoulder press' }),
    'dumbbell-front-raise': () => lateralRaise({ mode: 'front', name: 'Dumbbell front raise' }),
    'cable-lateral-raise': () => lateralRaise({ mode: 'side', cable: true, name: 'Cable lateral raise' }),
    'bent-over-dumbbell-reverse-fly': () => lateralRaise({ mode: 'rear', name: 'Bent-over reverse fly' }),
    'overhead-dumbbell-tricep-extension': () => triceps({ mode: 'ohdb', name: 'Overhead dumbbell extension' }),
    'bench-dips': () => triceps({ mode: 'dips', name: 'Bench dips' }),
    'dumbbell-kickback': () => triceps({ mode: 'kickback', name: 'Triceps kickback' }),
    'chest-supported-dumbbell-row': () => bentRow({ implement: 'dumbbell', name: 'Chest-supported row' }),
    'machine-row': () => seatedRow({ machine: true, name: 'Machine row' }),
    'inverted-row': () => inverted(),
    'dumbbell-pullover': () => pullover(),
    'dumbbell-curl': () => curl({ implement: 'dumbbell', name: 'Dumbbell curl' }),
    'incline-dumbbell-curl': () => curl({ implement: 'dumbbell', incline: true, name: 'Incline dumbbell curl' }),
    'cable-curl': () => curl({ implement: 'dumbbell', cable: true, name: 'Cable curl' }),
    'goblet-squat': () => squat({ implement: 'goblet', name: 'Goblet squat' }),
    'bulgarian-split-squat': () => splitSquat({ name: 'Bulgarian split squat' }),
    'walking-lunge': () => splitSquat({ walking: true, name: 'Walking lunge' }),
    'hack-squat': () => hackSquat(),
    'dumbbell-romanian-deadlift': () => hinge({ implement: 'dumbbell', name: 'Dumbbell Romanian deadlift' }),
    'glute-bridge': () => bridge(),
    'hip-thrust': () => bridge({ weighted: true, name: 'Hip thrust' }),
    'lying-leg-curl': () => legCurl({ lying: true, name: 'Lying leg curl' }),
    'seated-calf-raise': () => calfRaise({ seated: true, name: 'Seated calf raise' }),
    'single-leg-calf-raise': () => calfRaise({ single: true, name: 'Single-leg calf raise' }),
    'farmers-walk': () => carry(),
    'hanging-knee-raise': () => hangingKnee(),
    'cable-crunch': () => cableCrunch(),
    'dead-bug': () => deadBug(),
    // --- helpers ---
    'bodyweight-squat': () => squat({ implement: 'body', name: 'Bodyweight squat' }),
    'push-up': () => pushUp({ name: 'Push-up' }),
    plank: () => plank(),
    'jumping-jacks': () => jumpingJacks(),
    ...Object.fromEntries(Object.entries(MOBILITY)),
};

/** Fallback for any exercise without a dedicated animation: a calm standing breathe. */
const idle = () => spec('Exercise', {}, { ground: 'feet' }, holdPose({ lean: 0 }, { lean: 1.5, neck: 1 }), 4, 'angle');

export function getAnimation(key) {
    return (REGISTRY[key] ?? idle)();
}

export const hasAnimation = (key) => key in REGISTRY;
export const animationKeys = () => Object.keys(REGISTRY);
