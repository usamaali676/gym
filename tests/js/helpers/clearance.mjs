/**
 * Measures how far the stylised figure sinks into its equipment, in metres.
 *
 * Both are built from simple solids (capsules, spheres, boxes, cylinders), so overlap can be measured exactly:
 * sample points through the volume of every prop and ask each body part how deep inside it the point is.
 * Hands are skipped on purpose: they close around grips and handles.
 */
import { Box3, Vector3 } from 'three';
import { applyPose, clearProps, createHumanoid } from '../../../resources/js/modules/humanoid.js';
import { getAnimation, poseAt } from '../../../resources/js/modules/animations.js';

const SOLIDS = ['CapsuleGeometry', 'SphereGeometry', 'BoxGeometry', 'CylinderGeometry'];
const PROP_SOLIDS = ['CylinderGeometry', 'BoxGeometry', 'SphereGeometry'];
const SAMPLE_SPACING = 0.02;

/** Signed distance from a world point to a body part (negative inside). */
function distanceToPart(mesh, worldPoint, scratch) {
    const g = mesh.geometry;
    const p = mesh.worldToLocal(scratch.copy(worldPoint));
    const scale = Math.min(mesh.scale.x, mesh.scale.y, mesh.scale.z);
    let d;
    if (g.type === 'CapsuleGeometry') {
        const { radius, length } = g.parameters;
        const y = Math.max(-length / 2, Math.min(length / 2, p.y));
        d = Math.hypot(p.x, p.y - y, p.z) - radius;
    } else if (g.type === 'SphereGeometry') {
        d = p.length() - g.parameters.radius;
    } else if (g.type === 'CylinderGeometry') {
        d = Math.max(Math.hypot(p.x, p.z) - g.parameters.radiusTop, Math.abs(p.y) - g.parameters.height / 2);
    } else {
        const { width, height, depth } = g.parameters;
        const q = [Math.abs(p.x) - width / 2, Math.abs(p.y) - height / 2, Math.abs(p.z) - depth / 2];
        d = Math.hypot(Math.max(q[0], 0), Math.max(q[1], 0), Math.max(q[2], 0)) + Math.min(Math.max(q[0], q[1], q[2]), 0);
    }
    return d * scale;
}

function samplePoints(mesh) {
    const g = mesh.geometry;
    const points = [];
    const at = (x, y, z) => points.push(mesh.localToWorld(new Vector3(x, y, z)));
    if (g.type === 'BoxGeometry') {
        const { width, height, depth } = g.parameters;
        const n = [width * mesh.scale.x, height * mesh.scale.y, depth * mesh.scale.z].map((len) => Math.max(1, Math.ceil(len / SAMPLE_SPACING)));
        for (let i = 0; i <= n[0]; i++) for (let j = 0; j <= n[1]; j++) for (let k = 0; k <= n[2]; k++) at((i / n[0] - 0.5) * width, (j / n[1] - 0.5) * height, (k / n[2] - 0.5) * depth);
    } else if (g.type === 'CylinderGeometry') {
        const { radiusTop: r, height } = g.parameters;
        const rows = Math.min(40, Math.max(3, Math.round(height / 0.04)));
        for (let k = 0; k <= rows; k++) {
            for (const ring of [0, 0.5, 1]) {
                for (let a = 0; a < (ring ? 10 : 1); a++) {
                    const angle = (a / 10) * Math.PI * 2;
                    at(ring * r * Math.cos(angle), (k / rows - 0.5) * height, ring * r * Math.sin(angle));
                }
            }
        }
    } else {
        const r = g.parameters.radius * 0.9;
        at(0, 0, 0);
        for (const [x, y, z] of [[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]]) at(x * r, y * r, z * r);
    }
    return points;
}

/**
 * Deepest overlap for one animation over `steps` points of its loop.
 * `solid` is real equipment; `ghost` is outline-only equipment (weight stacks, walls).
 * @returns {{ solid: number, ghost: number }}
 */
export function deepestOverlap(key, steps = 12) {
    const rig = createHumanoid();
    const parts = [];
    rig.root.traverse((o) => {
        if (o.isMesh && !o.userData.region && !o.userData.keep && SOLIDS.includes(o.geometry.type)) parts.push(o);
    });

    const spec = getAnimation(key);
    applyPose(rig, poseAt(spec, 0), spec.setup);
    const ctx = { pelvis: rig.pelvis.getWorldPosition(new Vector3()) };
    const props = spec.setup.props ? spec.setup.props(rig, ctx).flat().filter(Boolean) : [];

    const scratch = new Vector3();
    const result = { solid: 0, ghost: 0 };
    for (let s = 0; s < steps; s++) {
        applyPose(rig, poseAt(spec, s / steps), spec.setup);
        props.forEach((p) => p.update?.(rig));
        rig.root.updateMatrixWorld(true);
        rig.props.updateMatrixWorld(true);

        const boxes = new Map(parts.map((part) => [part, new Box3().setFromObject(part)]));
        const meshes = [];
        const collect = (o) => {
            if (!o.isMesh || o.userData.keep || !PROP_SOLIDS.includes(o.geometry.type)) return;
            if (o.geometry.type === 'CylinderGeometry' && o.geometry.parameters.radiusTop < 0.008) return; // cables
            meshes.push(o);
        };
        rig.props.traverse(collect);
        for (const wrist of [rig.armL.wrist, rig.armR.wrist]) wrist.children.forEach((c) => c.traverse(collect));

        for (const mesh of meshes) {
            mesh.updateWorldMatrix(true, false);
            const kind = mesh.material?.opacity === 0 ? 'ghost' : 'solid';
            for (const point of samplePoints(mesh)) {
                for (const part of parts) {
                    if (!boxes.get(part).containsPoint(point)) continue;
                    const depth = -distanceToPart(part, point, scratch);
                    if (depth > result[kind]) result[kind] = depth;
                }
            }
        }
    }
    clearProps(rig);

    return result;
}
