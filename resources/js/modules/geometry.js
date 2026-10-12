/**
 * Pose geometry on MediaPipe landmarks. Pure functions, no DOM, so everything here runs under Node tests.
 *
 * Coordinates: MediaPipe gives normalised image coordinates (x and y both 0..1). A degree is only
 * a degree when both axes use the same unit, so callers scale x by the video aspect ratio first
 * (see `scaleLandmarks`). y grows downwards, "up" is -y.
 */

export const LANDMARK_INDEX = {
    nose: 0,
    l_eye_inner: 1, l_eye: 2, l_eye_outer: 3,
    r_eye_inner: 4, r_eye: 5, r_eye_outer: 6,
    l_ear: 7, r_ear: 8,
    l_mouth: 9, r_mouth: 10,
    l_shoulder: 11, r_shoulder: 12,
    l_elbow: 13, r_elbow: 14,
    l_wrist: 15, r_wrist: 16,
    l_pinky: 17, r_pinky: 18,
    l_index: 19, r_index: 20,
    l_thumb: 21, r_thumb: 22,
    l_hip: 23, r_hip: 24,
    l_knee: 25, r_knee: 26,
    l_ankle: 27, r_ankle: 28,
    l_heel: 29, r_heel: 30,
    l_foot_index: 31, r_foot_index: 32,
};

export const LANDMARK_COUNT = 33;

/** Names that exist on both sides. A bare name ("elbow") means "the side facing the camera". */
export const SIDED_POINTS = [
    'eye_inner', 'eye', 'eye_outer', 'ear', 'mouth', 'shoulder', 'elbow', 'wrist',
    'pinky', 'index', 'thumb', 'hip', 'knee', 'ankle', 'heel', 'foot_index',
];

const MID_POINTS = { mid_shoulder: ['l_shoulder', 'r_shoulder'], mid_hip: ['l_hip', 'r_hip'], mid_ankle: ['l_ankle', 'r_ankle'], mid_wrist: ['l_wrist', 'r_wrist'], mid_ear: ['l_ear', 'r_ear'] };

/** Every point name a rule may use. */
export const POINT_NAMES = new Set([
    'nose',
    ...SIDED_POINTS,
    ...SIDED_POINTS.flatMap((p) => [`l_${p}`, `r_${p}`]),
    ...Object.keys(MID_POINTS),
]);

export const isPointName = (name) => typeof name === 'string' && POINT_NAMES.has(name);
export const isSidedBare = (name) => SIDED_POINTS.includes(name);

/**
 * Resolve a point name to landmark indices.
 * Returns `[i]` for one landmark or `[i, j]` for a midpoint, or null when unknown.
 * `side` is 'l' or 'r' and only matters for bare sided names.
 */
export function resolvePoint(name, side = 'l') {
    if (name === 'nose') return [LANDMARK_INDEX.nose];
    if (MID_POINTS[name]) return MID_POINTS[name].map((n) => LANDMARK_INDEX[n]);
    if (isSidedBare(name)) return [LANDMARK_INDEX[`${side}_${name}`]];
    const index = LANDMARK_INDEX[name];
    return index === undefined ? null : [index];
}

/** Does this name depend on the active side? */
export const dependsOnSide = (name) => isSidedBare(name);

// ---------------------------------------------------------------------------------------------
// Bones (for the overlay and for "which segment is wrong")
// ---------------------------------------------------------------------------------------------

export const BONES = {
    shoulders: ['l_shoulder', 'r_shoulder'],
    hips: ['l_hip', 'r_hip'],
    torso_l: ['l_shoulder', 'l_hip'],
    torso_r: ['r_shoulder', 'r_hip'],
    neck: ['mid_shoulder', 'nose'],
    upper_arm_l: ['l_shoulder', 'l_elbow'],
    upper_arm_r: ['r_shoulder', 'r_elbow'],
    forearm_l: ['l_elbow', 'l_wrist'],
    forearm_r: ['r_elbow', 'r_wrist'],
    hand_l: ['l_wrist', 'l_index'],
    hand_r: ['r_wrist', 'r_index'],
    thigh_l: ['l_hip', 'l_knee'],
    thigh_r: ['r_hip', 'r_knee'],
    shin_l: ['l_knee', 'l_ankle'],
    shin_r: ['r_knee', 'r_ankle'],
    foot_l: ['l_ankle', 'l_foot_index'],
    foot_r: ['r_ankle', 'r_foot_index'],
    heel_l: ['l_ankle', 'l_heel'],
    heel_r: ['r_ankle', 'r_heel'],
};

/** Names a rule can use in `segments`. Each expands to a list of bone ids. */
export const SEGMENT_GROUPS = (() => {
    const base = {
        torso: ['torso_l', 'torso_r', 'shoulders', 'hips'],
        shoulders: ['shoulders'],
        hips: ['hips'],
        neck: ['neck'],
        upper_arm: ['upper_arm_l', 'upper_arm_r'],
        forearm: ['forearm_l', 'forearm_r'],
        hand: ['hand_l', 'hand_r'],
        thigh: ['thigh_l', 'thigh_r'],
        shin: ['shin_l', 'shin_r'],
        foot: ['foot_l', 'foot_r', 'heel_l', 'heel_r'],
    };
    base.arm = [...base.upper_arm, ...base.forearm, ...base.hand];
    base.leg = [...base.thigh, ...base.shin, ...base.foot];
    base.body = Object.keys(BONES);
    const groups = { ...base };
    for (const [name, bones] of Object.entries(base)) {
        for (const side of ['l', 'r']) {
            const scoped = bones.filter((b) => b.endsWith(`_${side}`));
            if (scoped.length) groups[`${name}_${side}`] = scoped;
        }
    }
    return groups;
})();

export const isSegmentGroup = (name) => Object.prototype.hasOwnProperty.call(SEGMENT_GROUPS, name);

/** Bones drawn as the skeleton, in draw order. */
export const DRAW_ORDER = [
    'shoulders', 'hips', 'torso_l', 'torso_r', 'neck',
    'thigh_l', 'thigh_r', 'shin_l', 'shin_r', 'foot_l', 'foot_r', 'heel_l', 'heel_r',
    'upper_arm_l', 'upper_arm_r', 'forearm_l', 'forearm_r', 'hand_l', 'hand_r',
];

// ---------------------------------------------------------------------------------------------
// Vector maths on {x, y}
// ---------------------------------------------------------------------------------------------

export const deg = (rad) => (rad * 180) / Math.PI;
export const rad = (d) => (d * Math.PI) / 180;
export const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
export const midpoint = (a, b) => ({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2, v: Math.min(a.v ?? 1, b.v ?? 1) });

/** Interior angle at `b` (degrees, 0..180). Null if a segment has zero length. */
export function angleAt(a, b, c) {
    const v1x = a.x - b.x;
    const v1y = a.y - b.y;
    const v2x = c.x - b.x;
    const v2y = c.y - b.y;
    const n1 = Math.hypot(v1x, v1y);
    const n2 = Math.hypot(v2x, v2y);
    if (n1 < 1e-9 || n2 < 1e-9) return null;
    const cos = (v1x * v2x + v1y * v2y) / (n1 * n2);
    return deg(Math.acos(Math.min(1, Math.max(-1, cos))));
}

/**
 * Angle between the vector from->to and a reference axis, in degrees.
 * 'vertical': 0 when `to` is straight above `from`, 90 horizontal, 180 straight below.
 * 'horizontal': the acute angle between the line and the horizontal, 0..90.
 */
export function inclination(from, to, ref = 'vertical') {
    const dx = to.x - from.x;
    const dy = to.y - from.y;
    if (Math.hypot(dx, dy) < 1e-9) return null;
    if (ref === 'vertical') return deg(Math.atan2(Math.abs(dx), -dy));
    const a = deg(Math.atan2(Math.abs(dy), Math.abs(dx)));
    return a;
}

/** Signed perpendicular distance of `p` from the line a->b; positive when p is above the line (smaller y). */
export function lineOffsetUp(a, b, p) {
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const len = Math.hypot(dx, dy);
    if (len < 1e-9) return null;
    // 2D cross product gives the signed distance; image y points down so flip the sign for "up".
    const cross = (dx * (p.y - a.y) - dy * (p.x - a.x)) / len;
    // Orient by the direction of travel so that "above" means smaller y whichever way the line points.
    return dx >= 0 ? -cross : cross;
}

/** Scale x by the video aspect ratio so that distances and angles are isotropic. */
export function scaleLandmarks(landmarks, aspect) {
    return landmarks.map((l) => ({ x: l.x * aspect, y: l.y, z: (l.z ?? 0) * aspect, v: l.visibility ?? l.v ?? 1 }));
}

export const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
