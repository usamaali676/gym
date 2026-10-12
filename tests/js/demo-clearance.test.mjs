import assert from 'node:assert/strict';
import test from 'node:test';
import { MeshStandardMaterial, Vector3 } from 'three';
import { addBar, addPad, applyPose, createHumanoid } from '../../resources/js/modules/humanoid.js';
import { animationKeys, getAnimation, poseAt } from '../../resources/js/modules/animations.js';
import { deepestOverlap } from './helpers/clearance.mjs';

/** Resting contact (hips on a seat, a hand ball against the head) is fine; sinking into equipment is not. */
const LIMIT = 0.012;
const LIMIT_GHOST = 0.015;
// The rope handle is held against the head here, so the hand reaches into the head by design.
const ALLOWED = { 'cable-crunch': 0.02 };

test('no exercise demo sinks the figure into its equipment', () => {
    const offenders = [];
    for (const key of animationKeys()) {
        const { solid, ghost } = deepestOverlap(key);
        if (solid > (ALLOWED[key] ?? LIMIT)) offenders.push(`${key}: ${(solid * 100).toFixed(1)} cm into solid equipment`);
        if (ghost > LIMIT_GHOST) offenders.push(`${key}: ${(ghost * 100).toFixed(1)} cm through an outline`);
    }
    assert.deepEqual(offenders, [], `Figure overlaps equipment:\n${offenders.join('\n')}`);
});

test('the overlap measure itself detects a pad pushed through the torso', () => {
    // Guards the guard: a hack squat whose back pad does not move with the lifter must be reported.
    const spec = getAnimation('hack-squat');
    const original = spec.setup.props;
    const rig = createHumanoid();
    applyPose(rig, poseAt(spec, 0), spec.setup);
    const handles = original(rig, { pelvis: rig.pelvis.getWorldPosition(new Vector3()) }).flat();
    assert.ok(handles.length > 0);
    // Deep in the squat the pelvis has dropped, so a pad frozen at its start position would cut the body.
    applyPose(rig, poseAt(spec, 0.5), spec.setup);
    const frozen = handles[0].object.position.clone();
    handles[0].update(rig);
    assert.ok(handles[0].object.position.y < frozen.y - 0.1, 'the pad follows the pelvis down');
});

test('a pad can follow the pelvis or the ankles and keeps its offset', () => {
    const rig = createHumanoid();
    applyPose(rig, { hF: 10 }, { ground: 'none', anchor: 'pelvis' });
    const start = rig.pelvis.getWorldPosition(new Vector3());
    const pad = addPad(rig, { position: [0, start.y + 0.25, start.z - 0.28], follow: 'pelvis' });

    applyPose(rig, { hF: 10, rootY: 0.4, rootZ: 0.2 }, { ground: 'none', anchor: 'pelvis' });
    pad.update(rig);
    const now = rig.pelvis.getWorldPosition(new Vector3());

    assert.ok(Math.abs(pad.object.position.y - (now.y + 0.25)) < 1e-6);
    assert.ok(Math.abs(pad.object.position.z - (now.z - 0.28)) < 1e-6);
});

test('a bench pad can stand on legs that reach the floor', () => {
    const rig = createHumanoid();
    addPad(rig, { position: [0, 0.46, 0], size: [0.4, 0.07, 0.3], legs: true });

    const posts = rig.props.children.filter((c) => c.geometry.type === 'BoxGeometry' && c.geometry.parameters.width === 0.04);
    assert.equal(posts.length, 4);
    for (const post of posts) {
        assert.ok(Math.abs(post.position.y - post.geometry.parameters.height / 2) < 1e-9, 'each post starts on the floor');
        assert.ok(Math.abs(post.position.y + post.geometry.parameters.height / 2 - (0.46 - 0.035)) < 1e-9, 'and ends at the pad underside');
    }
});

test('barbell plates are translucent so the lifter stays visible through them', () => {
    const rig = createHumanoid();
    const bar = addBar(rig);
    const plates = bar.object.children.filter((c) => c.geometry.type === 'CylinderGeometry' && c.geometry.parameters.radiusTop > 0.05);

    assert.ok(plates.length >= 4);
    for (const plate of plates) {
        assert.ok(plate.material instanceof MeshStandardMaterial);
        assert.equal(plate.material.transparent, true);
        assert.ok(plate.material.opacity < 0.5);
    }
});
