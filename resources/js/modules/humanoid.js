/**
 * A stylised humanoid built only from capsules, spheres and boxes (no external assets).
 *
 * Joints are nested THREE.Groups; a "pose" is a plain object of angles in degrees (see NEUTRAL).
 * The model faces +Z, its left side is +X. Limbs hang along -Y.
 *
 * Conventions used by the pose vocabulary:
 *   shoulder: E = elevation from hanging (0) to overhead (180); P = plane of elevation
 *             (0 = out to the side, 90 = straight forward, <0 = behind); T = axial twist.
 *   elbow:    el = flexion (0 straight). The forearm folds toward the front of the arm's frame.
 *   hip:      hF = flexion (+forward), hA = abduction (+outward), hR = axial rotation.
 *   knee:     kn = flexion (+heel toward glute).   ankle: an = dorsiflexion (+toes up).
 *   spine:    lean (+forward), sb = side bend, tw = twist.   neck: neck (+looks down).
 * Per-limb overrides are prefixed l / r (e.g. lE, rel, lkn).
 */
import {
    BoxGeometry, CapsuleGeometry, CylinderGeometry, EdgesGeometry, Group, LineBasicMaterial, LineSegments,
    Mesh, MeshBasicMaterial, MeshStandardMaterial, Object3D, Quaternion, SphereGeometry, Vector3,
} from 'three';

const rad = (d) => (d * Math.PI) / 180;
const X = new Vector3(1, 0, 0);
const Y = new Vector3(0, 1, 0);
const Z = new Vector3(0, 0, 1);

export const NEUTRAL = Object.freeze({
    lean: 0, sb: 0, tw: 0, neck: 0,
    E: 6, P: 12, T: 0, el: 10, wr: 0,
    hF: 0, hA: 4, hR: 0, kn: 2, an: 0,
    rootPitch: 0, rootX: 0, rootY: 0, rootZ: 0, rootRoll: 0, rootYaw: 0,
    shrug: 0, pelvisTilt: 0,
});

export const colors = {
    body: 0x3b5546, joint: 0x4b6a58, neon: 0x39ff14, neonDim: 0x1fa80d, metal: 0xaab9b0, plate: 0x141a17,
    pad: 0x1d2b24, frame: 0x2d3d34, floor: 0x0b100d,
};

export function bodyMaterial(color = colors.body) {
    return new MeshStandardMaterial({ color, roughness: 0.55, metalness: 0.15, emissive: 0x0c2414, emissiveIntensity: 1 });
}

/** Build the skeleton, meshes and muscle patches. Returns the rig. */
export function createHumanoid() {
    const root = new Group();
    root.name = 'root';
    const mat = bodyMaterial();
    const jointMat = bodyMaterial(colors.joint);

    const add = (parent, geometry, material, position = [0, 0, 0], scale = [1, 1, 1]) => {
        const mesh = new Mesh(geometry, material);
        mesh.position.set(...position);
        mesh.scale.set(...scale);
        parent.add(mesh);
        return mesh;
    };

    const pelvis = new Group();
    pelvis.name = 'pelvis';
    pelvis.position.y = 0.99;
    root.add(pelvis);
    const pelvisMesh = add(pelvis, new CapsuleGeometry(0.115, 0.13, 6, 14), mat, [0, -0.01, 0], [1, 1, 0.82]);
    pelvisMesh.rotation.z = Math.PI / 2;

    const spine = new Group();
    spine.name = 'spine';
    pelvis.add(spine);
    add(spine, new CapsuleGeometry(0.15, 0.3, 8, 18), mat, [0, 0.27, 0], [1.1, 1, 0.74]);

    const neck = new Group();
    neck.name = 'neck';
    neck.position.set(0, 0.56, 0);
    spine.add(neck);
    add(neck, new CylinderGeometry(0.042, 0.05, 0.1, 12), mat, [0, 0.05, 0]);
    add(neck, new SphereGeometry(0.105, 20, 16), mat, [0, 0.17, 0.01], [0.95, 1.08, 1]);
    // A small "nose" box so the facing direction reads at a glance.
    add(neck, new BoxGeometry(0.03, 0.04, 0.05), jointMat, [0, 0.16, 0.115]);

    const arm = (side) => {
        const s = side === 'L' ? 1 : -1;
        const shoulder = new Group();
        shoulder.name = `shoulder${side}`;
        shoulder.position.set(s * 0.215, 0.47, 0);
        spine.add(shoulder);
        add(shoulder, new SphereGeometry(0.058, 16, 12), jointMat);
        add(shoulder, new CapsuleGeometry(0.046, 0.215, 6, 12), mat, [0, -0.155, 0]);
        const elbow = new Group();
        elbow.name = `elbow${side}`;
        elbow.position.y = -0.31;
        shoulder.add(elbow);
        add(elbow, new SphereGeometry(0.043, 14, 10), jointMat);
        add(elbow, new CapsuleGeometry(0.037, 0.195, 6, 12), mat, [0, -0.14, 0]);
        const wrist = new Group();
        wrist.name = `wrist${side}`;
        wrist.position.y = -0.275;
        elbow.add(wrist);
        add(wrist, new SphereGeometry(0.048, 12, 10), jointMat, [0, -0.03, 0], [1, 1.1, 0.8]).userData.keep = true; // the hand itself
        return { shoulder, elbow, wrist };
    };

    const leg = (side) => {
        const s = side === 'L' ? 1 : -1;
        const hip = new Group();
        hip.name = `hip${side}`;
        hip.position.set(s * 0.1, -0.045, 0);
        pelvis.add(hip);
        add(hip, new SphereGeometry(0.075, 14, 12), jointMat);
        add(hip, new CapsuleGeometry(0.07, 0.32, 8, 14), mat, [0, -0.225, 0]);
        const knee = new Group();
        knee.name = `knee${side}`;
        knee.position.y = -0.45;
        hip.add(knee);
        add(knee, new SphereGeometry(0.058, 14, 12), jointMat);
        add(knee, new CapsuleGeometry(0.052, 0.33, 8, 14), mat, [0, -0.22, 0]);
        const ankle = new Group();
        ankle.name = `ankle${side}`;
        ankle.position.y = -0.43;
        knee.add(ankle);
        add(ankle, new BoxGeometry(0.095, 0.07, 0.25), mat, [0, -0.03, 0.06]);
        // Foot contact points used for grounding.
        const toe = new Object3D();
        toe.position.set(0, -0.065, 0.18);
        const heel = new Object3D();
        heel.position.set(0, -0.065, -0.06);
        ankle.add(toe, heel);
        return { hip, knee, ankle, toe, heel };
    };

    const L = arm('L');
    const R = arm('R');
    const legL = leg('L');
    const legR = leg('R');

    const rig = {
        root, pelvis, spine, neck,
        armL: L, armR: R, legL, legR,
        patches: {},
        props: new Group(),
        materials: { body: mat, joint: jointMat },
    };
    // Props live beside the figure (not under its root) so they stay in world space when the body pitches.
    buildPatches(rig);

    return rig;
}

// ---------------------------------------------------------------------------------------------
// Muscle highlight patches
// ---------------------------------------------------------------------------------------------

const patchMaterials = () => ({
    primary: new MeshBasicMaterial({ color: colors.neon, transparent: true, opacity: 0.92 }),
    secondary: new MeshBasicMaterial({ color: colors.neonDim, transparent: true, opacity: 0.55 }),
});

function buildPatches(rig) {
    const bank = {}; // region -> [meshes]
    const mats = patchMaterials();
    rig.patchMaterials = mats;

    const patch = (region, parent, geometry, position, scale = [1, 1, 1], rotation = [0, 0, 0]) => {
        const mesh = new Mesh(geometry, mats.primary);
        mesh.position.set(...position);
        mesh.scale.set(...scale);
        mesh.rotation.set(...rotation);
        mesh.visible = false;
        mesh.userData.region = region;
        parent.add(mesh);
        (bank[region] ||= []).push(mesh);
    };

    const sphere = (r) => new SphereGeometry(r, 14, 10);
    const capsule = (r, l) => new CapsuleGeometry(r, l, 4, 10);
    const box = (w, h, d) => new BoxGeometry(w, h, d);

    for (const side of ['L', 'R']) {
        const s = side === 'L' ? 1 : -1;
        const arm = rig[`arm${side}`];
        const leg = rig[`leg${side}`];

        patch('chest_upper', rig.spine, sphere(0.07), [s * 0.085, 0.4, 0.088], [1.25, 0.8, 0.5]);
        patch('chest_lower', rig.spine, sphere(0.07), [s * 0.085, 0.31, 0.095], [1.25, 0.8, 0.5]);

        patch('delt_front', arm.shoulder, sphere(0.05), [0, -0.01, 0.045], [1, 1, 0.9]);
        patch('delt_side', arm.shoulder, sphere(0.052), [s * 0.052, -0.01, 0], [0.9, 1, 1]);
        patch('delt_rear', arm.shoulder, sphere(0.05), [0, -0.01, -0.045], [1, 1, 0.9]);
        patch('biceps', arm.shoulder, capsule(0.034, 0.11), [0, -0.15, 0.036]);
        patch('triceps', arm.shoulder, capsule(0.034, 0.11), [0, -0.15, -0.036]);
        patch('forearms', arm.elbow, capsule(0.04, 0.14), [0, -0.135, 0]);

        patch('lats', rig.spine, box(0.075, 0.24, 0.05), [s * 0.115, 0.24, -0.075], [1, 1, 1], [0, 0, s * 0.12]);
        patch('traps', rig.spine, box(0.07, 0.08, 0.05), [s * 0.085, 0.53, -0.04], [1, 1, 1], [0, 0, s * -0.45]);

        patch('glutes', rig.pelvis, sphere(0.082), [s * 0.065, -0.035, -0.085], [1, 1, 0.9]);
        patch('quads', leg.hip, capsule(0.057, 0.27), [0, -0.225, 0.05]);
        patch('hamstrings', leg.hip, capsule(0.057, 0.27), [0, -0.225, -0.05]);
        patch('calves', leg.knee, capsule(0.046, 0.19), [0, -0.2, -0.04]);
    }
    patch('mid_back', rig.spine, box(0.2, 0.15, 0.03), [0, 0.34, -0.097]);
    patch('traps', rig.spine, box(0.14, 0.09, 0.04), [0, 0.52, -0.06]);
    patch('lower_back', rig.spine, box(0.15, 0.13, 0.03), [0, 0.12, -0.1]);
    patch('abs', rig.spine, box(0.13, 0.2, 0.03), [0, 0.17, 0.105]);

    rig.patches = bank;
}

/** Highlight target muscles: primary bright, secondary dim, everything else hidden. */
export function setRegions(rig, regions = {}) {
    const primary = new Set(regions.primary || []);
    const secondary = new Set(regions.secondary || []);
    for (const [region, meshes] of Object.entries(rig.patches)) {
        for (const mesh of meshes) {
            mesh.visible = primary.has(region) || secondary.has(region);
            mesh.material = primary.has(region) ? rig.patchMaterials.primary : rig.patchMaterials.secondary;
        }
    }
}

// ---------------------------------------------------------------------------------------------
// Posing
// ---------------------------------------------------------------------------------------------

const _v = new Vector3();
const _q = {
    a: new Quaternion(), b: new Quaternion(), c: new Quaternion(), d: new Quaternion(),
};

/** Rotation that swings a hanging limb out to elevation E in plane P (see header). */
function aim(out, E, P, side) {
    const p = rad(P);
    const k = new Vector3(-Math.sin(p), 0, side * Math.cos(p)).normalize();
    return out.setFromAxisAngle(k, rad(E));
}

const val = (pose, key, side) => (pose[`${side}${key}`] ?? pose[key]);

/** Apply a full pose (missing keys fall back to NEUTRAL). */
export function applyPose(rig, input, setup = {}) {
    const pose = { ...NEUTRAL, ...input };

    rig.root.rotation.set(rad(pose.rootPitch), rad(pose.rootYaw), rad(pose.rootRoll), 'YXZ');
    // `anchor: 'pelvis'` places the pelvis centre at (rootX, rootY, rootZ) in the world;
    // `anchor: 'pelvisXZ'` pins it in X/Z and lets grounding decide the height; otherwise the root itself is placed.
    const pelvisOffset = _v.set(0, rig.pelvis.position.y, 0).applyQuaternion(rig.root.quaternion);
    if (setup.anchor === 'pelvis') rig.root.position.set(pose.rootX - pelvisOffset.x, pose.rootY - pelvisOffset.y, pose.rootZ - pelvisOffset.z);
    else if (setup.anchor === 'pelvisXZ') rig.root.position.set(pose.rootX - pelvisOffset.x, 0, pose.rootZ - pelvisOffset.z);
    else rig.root.position.set(pose.rootX, pose.rootY, pose.rootZ);
    rig.pelvis.rotation.x = rad(pose.pelvisTilt);

    rig.spine.quaternion
        .setFromAxisAngle(Y, rad(pose.tw))
        .multiply(_q.a.setFromAxisAngle(X, rad(pose.lean)))
        .multiply(_q.b.setFromAxisAngle(Z, rad(pose.sb)));
    rig.neck.rotation.x = rad(pose.neck);

    for (const [side, s] of [['l', 1], ['r', -1]]) {
        const A = side === 'l' ? rig.armL : rig.armR;
        const Lg = side === 'l' ? rig.legL : rig.legR;

        aim(A.shoulder.quaternion, val(pose, 'E', side), val(pose, 'P', side), s)
            .multiply(_q.c.setFromAxisAngle(Y, rad(s * val(pose, 'T', side))));
        A.shoulder.position.y = 0.47 + pose.shrug;
        A.elbow.quaternion.setFromAxisAngle(X, rad(-val(pose, 'el', side)))
            .multiply(_q.d.setFromAxisAngle(Y, rad(s * val(pose, 'wr', side))));

        Lg.hip.quaternion
            .setFromAxisAngle(Z, rad(s * val(pose, 'hA', side)))
            .multiply(_q.a.setFromAxisAngle(X, rad(-val(pose, 'hF', side))))
            .multiply(_q.b.setFromAxisAngle(Y, rad(s * val(pose, 'hR', side))));
        Lg.knee.rotation.set(rad(val(pose, 'kn', side)), 0, 0);
        Lg.ankle.rotation.set(rad(-val(pose, 'an', side)), 0, 0);
    }

    rig.root.updateMatrixWorld(true);
    placeOnSurface(rig, setup);
}

/** Keep the figure on the floor (or hanging from a bar) after posing. */
function placeOnSurface(rig, setup) {
    if (setup.ground === 'none') return;

    if (setup.ground === 'hands') {
        const y = (w) => w.getWorldPosition(_v).y;
        const hands = (y(rig.armL.wrist) + y(rig.armR.wrist)) / 2;
        const handsZ = (rig.armL.wrist.getWorldPosition(_v).z + rig.armR.wrist.getWorldPosition(_v).z) / 2;
        rig.root.position.y += (setup.handsY ?? 2.2) - 0.06 - hands;
        rig.root.position.z += 0.05 - handsZ;
        rig.root.updateMatrixWorld(true);
        return;
    }

    let lowest = Infinity;
    for (const leg of [rig.legL, rig.legR]) {
        for (const p of [leg.toe, leg.heel]) lowest = Math.min(lowest, p.getWorldPosition(_v).y);
    }
    // Bodies lying on the floor (planks, push-ups) rest on the hands too.
    if (setup.ground === 'floor') {
        for (const w of [rig.armL.wrist, rig.armR.wrist]) lowest = Math.min(lowest, w.getWorldPosition(_v).y - 0.05);
    }
    if (setup.ground === 'knees') {
        for (const k of [rig.legL.knee, rig.legR.knee]) lowest = Math.min(lowest, k.getWorldPosition(_v).y - 0.06);
        // Quadruped and child's pose rest on the hands as well; raised arms (cable crunch) never reach this low.
        for (const w of [rig.armL.wrist, rig.armR.wrist]) lowest = Math.min(lowest, w.getWorldPosition(_v).y - 0.05);
    }
    if (lowest !== Infinity) {
        rig.root.position.y -= lowest;
        rig.root.updateMatrixWorld(true);
    }
}

/** World position of an arm joint (used by cables and bars to follow the hands). */
export function worldOf(object, target = new Vector3()) {
    return object.getWorldPosition(target);
}

export function disposeObject(object) {
    object.traverse((child) => {
        child.geometry?.dispose?.();
        const materials = Array.isArray(child.material) ? child.material : child.material ? [child.material] : [];
        materials.forEach((m) => m.dispose?.());
    });
}

// ---------------------------------------------------------------------------------------------
// Props (equipment). Each builder returns { object, update(rig) } and is added to rig.props.
// ---------------------------------------------------------------------------------------------

const metalMat = () => new MeshStandardMaterial({ color: colors.metal, roughness: 0.3, metalness: 0.85 });
const plateMat = () => new MeshStandardMaterial({ color: colors.plate, roughness: 0.6, metalness: 0.3, emissive: 0x0a2a10, emissiveIntensity: 0.5 });
const padMat = () => new MeshStandardMaterial({ color: colors.pad, roughness: 0.8, metalness: 0.05 });
const frameMat = () => new MeshStandardMaterial({ color: colors.frame, roughness: 0.5, metalness: 0.6 });

function neonEdges(mesh, color = colors.neonDim, opacity = 0.7) {
    const edges = new LineSegments(new EdgesGeometry(mesh.geometry), new LineBasicMaterial({ color, transparent: true, opacity }));
    mesh.add(edges);
    return mesh;
}

function cylinderAlong(axis, radius, length, material) {
    const mesh = new Mesh(new CylinderGeometry(radius, radius, length, 14), material);
    if (axis === 'x') mesh.rotation.z = Math.PI / 2;
    if (axis === 'z') mesh.rotation.x = Math.PI / 2;
    return mesh;
}

function barbellObject(length = 1.55, plate = 0.2) {
    const g = new Group();
    g.add(cylinderAlong('x', 0.013, length, metalMat()));
    for (const s of [-1, 1]) {
        for (const [i, w] of [[0, 0.04], [1, 0.03]]) {
            const p = cylinderAlong('x', plate - i * 0.04, w, plateMat());
            p.position.x = s * (length / 2 - 0.1 - i * 0.05);
            g.add(p);
        }
    }
    return g;
}

function dumbbellObject(axis = 'x') {
    const g = new Group();
    g.add(cylinderAlong(axis, 0.014, 0.16, metalMat()));
    for (const s of [-1, 1]) {
        const p = cylinderAlong(axis, 0.062, 0.035, plateMat());
        p.position[axis] = s * 0.09;
        g.add(p);
    }
    return g;
}

/** Bar held across both hands (barbell / EZ bar). */
export function addBar(rig, { length = 1.55, plate = 0.2, offset = [0, 0, 0] } = {}) {
    const object = barbellObject(length, plate);
    rig.props.add(object);
    const a = new Vector3();
    const b = new Vector3();
    return {
        object,
        update() {
            rig.armL.wrist.getWorldPosition(a);
            rig.armR.wrist.getWorldPosition(b);
            object.position.copy(a).add(b).multiplyScalar(0.5);
            object.position.x += offset[0];
            object.position.y += offset[1] - 0.03;
            object.position.z += offset[2];
            // Keep the bar horizontal and perpendicular to the body (along X).
            object.rotation.set(0, 0, 0);
            const dx = a.x - b.x;
            const dy = a.y - b.y;
            const dz = a.z - b.z;
            object.rotation.z = Math.atan2(dy, dx);
            object.rotation.y = -Math.atan2(dz, Math.hypot(dx, dy));
        },
    };
}

/** One dumbbell in each hand, following the forearm. axis: 'x' (palms forward) or 'z' (neutral grip). */
export function addDumbbells(rig, { axis = 'x', only = null } = {}) {
    const items = [];
    for (const side of ['L', 'R']) {
        if (only && only !== side) continue;
        const object = dumbbellObject(axis);
        object.position.y = -0.045;
        rig[`arm${side}`].wrist.add(object);
        items.push(object);
    }
    return { object: null, update() {}, items };
}

/** A single handle (cable attachments, machine grips). */
export function addHandles(rig, { shape = 'bar', only = null } = {}) {
    for (const side of ['L', 'R']) {
        if (only && only !== side) continue;
        const handle = shape === 'bar'
            ? cylinderAlong('x', 0.015, 0.14, metalMat())
            : new Mesh(new SphereGeometry(0.032, 10, 8), metalMat());
        handle.position.y = -0.045;
        rig[`arm${side}`].wrist.add(handle);
    }
    return { object: null, update() {} };
}

/** A cable running from a fixed anchor to the hands, with a pulley and weight stack. */
export function addCable(rig, { anchor = [0, 2.1, 0.3], toBoth = true, stack = true } = {}) {
    const group = new Group();
    rig.props.add(group);

    // The weight stack is drawn as a ghost outline so it never hides the figure.
    const stackMesh = new Mesh(new BoxGeometry(0.34, 1.7, 0.12), new MeshBasicMaterial({ transparent: true, opacity: 0, depthWrite: false }));
    stackMesh.position.set(anchor[0], 1.0, anchor[2] + 0.18);
    neonEdges(stackMesh, colors.neonDim, 0.35);
    if (stack) group.add(stackMesh);
    const pulley = new Mesh(new CylinderGeometry(0.06, 0.06, 0.03, 16), metalMat());
    pulley.rotation.z = Math.PI / 2;
    pulley.position.set(anchor[0], anchor[1], anchor[2]);
    group.add(pulley);

    const lines = [];
    const ends = toBoth ? ['L', 'R'] : ['R'];
    for (const side of ends) {
        const geometry = new CylinderGeometry(0.004, 0.004, 1, 6);
        const line = new Mesh(geometry, new MeshBasicMaterial({ color: 0xc9d6cf }));
        group.add(line);
        lines.push({ side, line });
    }

    const from = new Vector3(...anchor);
    const to = new Vector3();
    const dir = new Vector3();
    const up = new Vector3(0, 1, 0);
    return {
        object: group,
        update() {
            for (const { side, line } of lines) {
                rig[`arm${side}`].wrist.getWorldPosition(to);
                dir.subVectors(to, from);
                const len = dir.length();
                line.position.copy(from).addScaledVector(dir, 0.5);
                line.scale.set(1, Math.max(0.001, len), 1);
                line.quaternion.setFromUnitVectors(up, dir.normalize());
            }
        },
    };
}

/** Bench: flat / incline / upright, optionally with a preacher pad. Positions are in world space. */
export function addBench(rig, { kind = 'flat', angle = 0, y = 0.4, z = 0, length = 1.25 } = {}) {
    const group = new Group();
    rig.props.add(group);
    const seat = neonEdges(new Mesh(new BoxGeometry(0.3, 0.07, 0.45), padMat()));
    const back = neonEdges(new Mesh(new BoxGeometry(0.3, 0.07, length), padMat()));

    if (kind === 'flat') {
        back.position.set(0, y - 0.035, z);
        group.add(back);
    } else {
        // The backrest pivots at the seat and rises toward the head end (-Z) by `angle` degrees.
        seat.position.set(0, y - 0.035, z + 0.15);
        group.add(seat);
        const pivot = new Group();
        pivot.position.set(0, y - 0.035, z - 0.08);
        pivot.rotation.x = rad(angle);
        back.position.set(0, 0, -length / 2);
        pivot.add(back);
        group.add(pivot);
    }

    for (const sx of [-0.12, 0.12]) {
        for (const sz of [-0.45, 0.45]) {
            const leg = new Mesh(new BoxGeometry(0.04, y - 0.05, 0.04), frameMat());
            leg.position.set(sx, (y - 0.05) / 2, z + sz);
            group.add(leg);
        }
    }
    return { object: group, update() {} };
}

export function addPad(rig, { position, rotationX = 0, size = [0.34, 0.07, 0.5] }) {
    const pad = neonEdges(new Mesh(new BoxGeometry(...size), padMat()));
    pad.position.set(...position);
    pad.rotation.x = rotationX;
    rig.props.add(pad);
    return { object: pad, update() {} };
}

/** `ghost` draws only the neon outline, for walls and doorframes that would otherwise hide the figure. */
export function addBlock(rig, { size, position, rotation = [0, 0, 0], ghost = false, material = ghost ? new MeshBasicMaterial({ transparent: true, opacity: 0, depthWrite: false }) : frameMat() }) {
    const mesh = neonEdges(new Mesh(new BoxGeometry(...size), material));
    mesh.position.set(...position);
    mesh.rotation.set(...rotation);
    rig.props.add(mesh);
    return { object: mesh, update() {} };
}

export function addPullupBar(rig, { y = 2.2, width = 1.0 } = {}) {
    const group = new Group();
    const bar = cylinderAlong('x', 0.02, width, metalMat());
    bar.position.set(0, y, 0.05);
    group.add(bar);
    for (const s of [-1, 1]) {
        const post = new Mesh(new BoxGeometry(0.05, y + 0.1, 0.05), frameMat());
        post.position.set(s * (width / 2 + 0.03), (y + 0.1) / 2 - 0.05, 0.05);
        group.add(post);
    }
    rig.props.add(group);
    return { object: group, update() {} };
}

export function addRack(rig, { z = 0, height = 1.7 } = {}) {
    const group = new Group();
    for (const s of [-1, 1]) {
        const post = neonEdges(new Mesh(new BoxGeometry(0.06, height, 0.06), frameMat()), colors.neonDim, 0.4);
        post.position.set(s * 0.55, height / 2, z);
        group.add(post);
        const hook = new Mesh(new BoxGeometry(0.06, 0.03, 0.14), metalMat());
        hook.position.set(s * 0.55, height * 0.78, z + 0.06);
        group.add(hook);
    }
    rig.props.add(group);
    return { object: group, update() {} };
}

export function clearProps(rig) {
    for (const child of [...rig.props.children]) {
        rig.props.remove(child);
        disposeObject(child);
    }
    // Props held in the hands (dumbbells, handles) are children of the wrists; the hand mesh is kept.
    for (const wrist of [rig.armL.wrist, rig.armR.wrist]) {
        for (const child of [...wrist.children]) {
            if (child.userData.keep) continue;
            wrist.remove(child);
            disposeObject(child);
        }
    }
}
