/**
 * Synthetic MediaPipe-style landmarks for tests: a 2D stick figure driven by joint angles.
 * Nothing here is a camera or a model; it lets the analyzer be exercised deterministically.
 *
 * Angle convention (degrees, "theta"): measured from straight up, positive towards the direction the
 * figure faces. vec(theta, len) = (sin theta, -cos theta) * len in image space (y grows downwards).
 */

export const ASPECT = 4 / 3;
const rad = (d) => (d * Math.PI) / 180;
export const vec = (theta, len) => [Math.sin(rad(theta)) * len, -Math.cos(rad(theta)) * len];
const add = (p, v) => [p[0] + v[0], p[1] + v[1]];

const L = { shank: 0.22, thigh: 0.24, torso: 0.28, upperArm: 0.15, forearm: 0.14, neck: 0.1 };
const IDX = {
    nose: 0, l_eye_inner: 1, l_eye: 2, l_eye_outer: 3, r_eye_inner: 4, r_eye: 5, r_eye_outer: 6, l_ear: 7, r_ear: 8, l_mouth: 9, r_mouth: 10,
    l_shoulder: 11, r_shoulder: 12, l_elbow: 13, r_elbow: 14, l_wrist: 15, r_wrist: 16, l_pinky: 17, r_pinky: 18, l_index: 19, r_index: 20, l_thumb: 21, r_thumb: 22,
    l_hip: 23, r_hip: 24, l_knee: 25, r_knee: 26, l_ankle: 27, r_ankle: 28, l_heel: 29, r_heel: 30, l_foot_index: 31, r_foot_index: 32,
};

const blank = () => Array.from({ length: 33 }, () => ({ x: 0.5, y: 0.5, z: 0, visibility: 0 }));

/** Put a point (in world units where x is already multiplied by aspect) into the landmark array. */
function put(lm, name, p, v = 0.97, aspect = ASPECT) {
    lm[IDX[name]] = { x: p[0] / aspect, y: p[1], z: 0, visibility: v };
}

/**
 * A figure seen from the side. Joint angles are absolute segment directions.
 * @param {object} o
 * @param {number} [o.shank] direction ankle->knee
 * @param {number} [o.kneeFlex] 180 - interior knee angle
 * @param {number} [o.torso] direction hip->shoulder
 * @param {number} [o.ua] direction shoulder->elbow (180 = hanging down)
 * @param {number} [o.elbow] interior elbow angle
 * @param {number} [o.elbowDir] +1 bends the forearm forwards (curl), -1 backwards (press)
 * @param {number|null} [o.fa] absolute forearm direction, overrides `elbow`
 * @param {number} [o.heelLift] metres of screen height the heel is raised
 * @param {number} [o.headDrop] degrees the head tilts down from the spine line
 */
export function sideFigure({
    facing = 1, ankleX = 0.5 * ASPECT, groundY = 0.92, aspect = ASPECT,
    shank = 6, kneeFlex = 8, torso = 2, ua = 180, elbow = 170, elbowDir = 1, fa = null,
    heelLift = 0, headDrop = 0, near = 'l', farVisibility = 0.3, pelvisY = null,
} = {}) {
    const lm = blank();
    const ankle = [ankleX, groundY - 0.02];
    const knee = add(ankle, vec(shank, L.shank));
    const hip = add(knee, vec(shank - kneeFlex, L.thigh));
    if (pelvisY !== null) hip[1] = pelvisY;
    const shoulder = add(hip, vec(torso, L.torso));
    const elbowP = add(shoulder, vec(ua, L.upperArm));
    const wrist = add(elbowP, vec(fa ?? (ua + 180 + elbowDir * elbow), L.forearm));
    const ear = add(shoulder, vec(torso * 0.5 + headDrop, 0.1));
    const nose = add(ear, [0.055, 0.012 + headDrop * 0.0015]);
    const heel = [ankle[0] - 0.035, ankle[1] + 0.035 - heelLift];
    const toe = [ankle[0] + 0.075, ankle[1] + 0.035];

    const far = near === 'l' ? 'r' : 'l';
    const mirror = (p) => (facing === 1 ? p : [aspect - p[0], p[1]]);
    const place = (side, v, dx) => {
        const nudge = (p) => mirror([p[0] + dx, p[1]]);
        put(lm, `${side}_shoulder`, nudge(shoulder), v, aspect);
        put(lm, `${side}_elbow`, nudge(elbowP), v, aspect);
        put(lm, `${side}_wrist`, nudge(wrist), v, aspect);
        put(lm, `${side}_hip`, nudge(hip), v, aspect);
        put(lm, `${side}_knee`, nudge(knee), v, aspect);
        put(lm, `${side}_ankle`, nudge(ankle), v, aspect);
        put(lm, `${side}_heel`, nudge(heel), v, aspect);
        put(lm, `${side}_foot_index`, nudge(toe), v, aspect);
        put(lm, `${side}_ear`, nudge(ear), v, aspect);
        put(lm, `${side}_index`, nudge(add(wrist, [0.03, 0])), v, aspect);
        put(lm, `${side}_pinky`, nudge(add(wrist, [0.025, 0.008])), v, aspect);
        put(lm, `${side}_thumb`, nudge(add(wrist, [0.025, -0.008])), v, aspect);
        put(lm, `${side}_eye`, nudge(add(ear, [0.03, -0.01])), v, aspect);
        put(lm, `${side}_mouth`, nudge(add(nose, [0, 0.03])), v, aspect);
    };
    place(near, 0.97, 0);
    place(far, farVisibility, 0.012);
    put(lm, 'nose', mirror(nose), 0.97, aspect);
    return lm;
}

/** A figure facing the camera. */
export function frontFigure({
    cx = 0.5 * ASPECT, groundY = 0.92, aspect = ASPECT,
    shoulderHalf = 0.09, hipHalf = 0.06, feetHalf = 0.07, abduction = 10, elbow = 172,
    sway = 0, shrug = 0, abductionL = null, abductionR = null, handsUp = false,
} = {}) {
    const lm = blank();
    const ankleY = groundY - 0.02;
    const hipY = ankleY - 0.46;
    const shoulderBaseY = hipY - 0.28;
    const swayDx = Math.sin(rad(sway)) * 0.28;

    const sides = [
        { name: 'l', s: 1, abd: abductionL ?? abduction },
        { name: 'r', s: -1, abd: abductionR ?? abduction },
    ];
    for (const { name, s, abd } of sides) {
        const shoulder = [cx + s * shoulderHalf + swayDx, shoulderBaseY - shrug];
        const hip = [cx + s * hipHalf, hipY];
        const knee = [cx + s * (feetHalf * 0.95), ankleY - L.shank];
        const ankle = [cx + s * feetHalf, ankleY];
        const u = [s * Math.sin(rad(abd)), Math.cos(rad(abd))];
        const elbowP = [shoulder[0] + u[0] * L.upperArm, shoulder[1] + u[1] * L.upperArm];
        // Forearm: rotate the upper-arm direction by the elbow bend, towards the head.
        const bend = rad(180 - elbow);
        const rot = (a) => [u[0] * Math.cos(a) - u[1] * Math.sin(a), u[0] * Math.sin(a) + u[1] * Math.cos(a)];
        const c1 = rot(bend);
        const c2 = rot(-bend);
        const pick = c1[1] < c2[1] ? c1 : c2;
        const wrist = [elbowP[0] + pick[0] * L.forearm, elbowP[1] + pick[1] * L.forearm];
        void handsUp;
        const ear = [cx + s * 0.04 + swayDx, shoulderBaseY - L.neck];

        put(lm, `${name}_shoulder`, shoulder, 0.97, aspect);
        put(lm, `${name}_elbow`, elbowP, 0.97, aspect);
        put(lm, `${name}_wrist`, wrist, 0.97, aspect);
        put(lm, `${name}_hip`, hip, 0.97, aspect);
        put(lm, `${name}_knee`, knee, 0.97, aspect);
        put(lm, `${name}_ankle`, ankle, 0.97, aspect);
        put(lm, `${name}_heel`, [ankle[0], ankle[1] + 0.03], 0.95, aspect);
        put(lm, `${name}_foot_index`, [ankle[0] + s * 0.02, ankle[1] + 0.04], 0.95, aspect);
        put(lm, `${name}_ear`, ear, 0.95, aspect);
        put(lm, `${name}_index`, [wrist[0], wrist[1] + 0.02], 0.9, aspect);
    }
    put(lm, 'nose', [cx + swayDx, shoulderBaseY - L.neck - 0.01], 0.97, aspect);
    return lm;
}

/** A plank seen from the side. `hipOffset` > 0 raises the hips above the shoulder-ankle line (fraction of torso). */
export function plankFigure({
    facing = 1, ankleX = 0.2 * ASPECT, groundY = 0.9, aspect = ASPECT, incline = 4, hipOffset = 0, headDrop = 0, near = 'l',
} = {}) {
    const lm = blank();
    const total = L.shank + L.thigh + L.torso;
    const ankle = [ankleX, groundY - 0.03];
    const along = (f) => [ankle[0] + Math.cos(rad(incline)) * total * f, ankle[1] - Math.sin(rad(incline)) * total * f];
    const shoulder = along(1);
    const hipBase = along((L.shank + L.thigh) / total);
    const hip = [hipBase[0], hipBase[1] - hipOffset * L.torso];
    const kneeBase = along(L.shank / total);
    const knee = [kneeBase[0], kneeBase[1] - hipOffset * L.torso * 0.5];
    const elbow = [shoulder[0], shoulder[1] + 0.12];
    const wrist = [shoulder[0] + 0.12, shoulder[1] + 0.12];
    const ear = [shoulder[0] + 0.08 * Math.cos(rad(headDrop)), shoulder[1] - 0.02 + 0.08 * Math.sin(rad(headDrop))];
    const nose = [ear[0] + 0.05, ear[1] + 0.02];
    const mirror = (p) => (facing === 1 ? p : [aspect - p[0], p[1]]);
    const far = near === 'l' ? 'r' : 'l';
    const place = (side, v, dx) => {
        const n = (p) => mirror([p[0] + dx, p[1]]);
        put(lm, `${side}_shoulder`, n(shoulder), v, aspect);
        put(lm, `${side}_elbow`, n(elbow), v, aspect);
        put(lm, `${side}_wrist`, n(wrist), v, aspect);
        put(lm, `${side}_hip`, n(hip), v, aspect);
        put(lm, `${side}_knee`, n(knee), v, aspect);
        put(lm, `${side}_ankle`, n(ankle), v, aspect);
        put(lm, `${side}_heel`, n([ankle[0] - 0.03, ankle[1] + 0.02]), v, aspect);
        put(lm, `${side}_foot_index`, n([ankle[0] + 0.04, ankle[1] + 0.03]), v, aspect);
        put(lm, `${side}_ear`, n(ear), v, aspect);
    };
    place(near, 0.97, 0);
    place(far, 0.3, 0.01);
    put(lm, 'nose', mirror(nose), 0.97, aspect);
    return lm;
}

// ---------------------------------------------------------------------------------------------
// Time series
// ---------------------------------------------------------------------------------------------

/** Deterministic PRNG so noisy tests are repeatable. */
export function rng(seed = 7) {
    let s = seed >>> 0;
    return () => {
        s = (s + 0x6d2b79f5) >>> 0;
        let t = s;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

export function gaussian(rand) {
    const u = Math.max(rand(), 1e-9);
    const v = rand();
    return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v);
}

export const smoothstep = (x) => 0.5 - 0.5 * Math.cos(Math.PI * Math.min(1, Math.max(0, x)));

/**
 * Progress profile of repeated reps: 0 at the start position, 1 at the peak.
 * @returns {{p:(tMs:number)=>number, duration:number, repMs:number}}
 */
export function repProfile({ reps = 5, restMs = 1200, downMs = 1300, pauseMs = 150, upMs = 1100, gapMs = 250, depth = 1 } = {}) {
    const repMs = downMs + pauseMs + upMs;
    const duration = restMs + reps * (repMs + gapMs) + 600;
    const p = (t) => {
        if (t < restMs) return 0;
        const local = (t - restMs) % (repMs + gapMs);
        const index = Math.floor((t - restMs) / (repMs + gapMs));
        if (index >= reps) return 0;
        if (local < downMs) return depth * smoothstep(local / downMs);
        if (local < downMs + pauseMs) return depth;
        if (local < repMs) return depth * (1 - smoothstep((local - downMs - pauseMs) / upMs));
        return 0;
    };
    return { p, duration, repMs };
}

/** Drive an analyzer with frames from `figureAt(tMs)`. Returns all events and the final result. */
export function drive(analyzer, figureAt, { durationMs, fps = 30, noise = 0, seed = 11, startT = 0 } = {}) {
    const rand = rng(seed);
    const dt = 1000 / fps;
    const events = [];
    const results = [];
    for (let t = 0; t <= durationMs; t += dt) {
        const lm = figureAt(t);
        const frame = lm === null ? null : lm.map((l) => ({
            ...l,
            x: l.x + (noise ? gaussian(rand) * noise / ASPECT : 0),
            y: l.y + (noise ? gaussian(rand) * noise : 0),
        }));
        const result = analyzer.update(frame, startT + t, { aspect: ASPECT });
        results.push(result);
        events.push(...result.events);
    }
    return { events, results, last: results.at(-1) };
}
