import assert from 'node:assert/strict';
import test from 'node:test';
import {
    BONES, SEGMENT_GROUPS, angleAt, inclination, isPointName, lineOffsetUp, midpoint, resolvePoint, scaleLandmarks,
} from '../../resources/js/modules/geometry.js';

const near = (a, b, eps = 1e-6) => assert.ok(Math.abs(a - b) < eps, `${a} is not within ${eps} of ${b}`);

test('angleAt measures the interior angle', () => {
    near(angleAt({ x: 1, y: 0 }, { x: 0, y: 0 }, { x: 0, y: 1 }), 90);
    near(angleAt({ x: 1, y: 0 }, { x: 0, y: 0 }, { x: -1, y: 0 }), 180);
    near(angleAt({ x: 1, y: 0 }, { x: 0, y: 0 }, { x: 1, y: 1 }), 45);
    assert.equal(angleAt({ x: 0, y: 0 }, { x: 0, y: 0 }, { x: 1, y: 1 }), null);
});

test('inclination: 0 degrees is straight up, image y grows downwards', () => {
    near(inclination({ x: 0, y: 1 }, { x: 0, y: 0 }, 'vertical'), 0);
    near(inclination({ x: 0, y: 0 }, { x: 1, y: 0 }, 'vertical'), 90);
    near(inclination({ x: 0, y: 0 }, { x: 0, y: 1 }, 'vertical'), 180);
    near(inclination({ x: 0, y: 0 }, { x: -1, y: -1 }, 'vertical'), 45);
    near(inclination({ x: 0, y: 0 }, { x: 3, y: 0 }, 'horizontal'), 0);
    near(inclination({ x: 0, y: 0 }, { x: 1, y: -1 }, 'horizontal'), 45);
    near(inclination({ x: 0, y: 0 }, { x: -1, y: 1 }, 'horizontal'), 45);
});

test('lineOffsetUp is positive above the line whichever way it points', () => {
    const a = { x: 0, y: 0 };
    const b = { x: 10, y: 0 };
    assert.ok(lineOffsetUp(a, b, { x: 5, y: -1 }) > 0);
    assert.ok(lineOffsetUp(a, b, { x: 5, y: 1 }) < 0);
    // reversed direction gives the same answer
    assert.ok(lineOffsetUp(b, a, { x: 5, y: -1 }) > 0);
    near(lineOffsetUp(a, b, { x: 5, y: -2 }), 2);
});

test('scaling x by the aspect ratio keeps angles honest on a wide frame', () => {
    // On a 2:1 frame, a 45 degree diagonal in pixels is dx = 0.25 of the width and dy = 0.5 of the height.
    const [p0, p1] = scaleLandmarks([{ x: 0, y: 0 }, { x: 0.25, y: 0.5 }], 2);
    near(inclination(p0, p1, 'vertical'), 135); // down-right diagonal, 135 degrees from "up"
    // Without scaling the same pixels would read as a steeper angle.
    const unscaled = scaleLandmarks([{ x: 0, y: 0 }, { x: 0.25, y: 0.5 }], 1);
    assert.ok(Math.abs(inclination(unscaled[0], unscaled[1], 'vertical') - 135) > 10);
});

test('point names resolve to landmark indices', () => {
    assert.deepEqual(resolvePoint('nose'), [0]);
    assert.deepEqual(resolvePoint('elbow', 'l'), [13]);
    assert.deepEqual(resolvePoint('elbow', 'r'), [14]);
    assert.deepEqual(resolvePoint('r_knee'), [26]);
    assert.deepEqual(resolvePoint('mid_hip'), [23, 24]);
    assert.equal(resolvePoint('banana'), null);
    assert.ok(isPointName('foot_index') && isPointName('l_heel') && isPointName('mid_shoulder'));
    assert.ok(!isPointName('left_knee'));
});

test('midpoint takes the weaker visibility', () => {
    const m = midpoint({ x: 0, y: 0, v: 0.9 }, { x: 2, y: 4, v: 0.4 });
    assert.deepEqual(m, { x: 1, y: 2, v: 0.4 });
});

test('segment groups only reference real bones and every bone is reachable', () => {
    const reachable = new Set();
    for (const [name, bones] of Object.entries(SEGMENT_GROUPS)) {
        for (const b of bones) {
            assert.ok(BONES[b], `${name} references unknown bone ${b}`);
            reachable.add(b);
        }
    }
    assert.deepEqual([...reachable].sort(), Object.keys(BONES).sort());
    assert.deepEqual(SEGMENT_GROUPS.upper_arm_l, ['upper_arm_l']);
    assert.ok(SEGMENT_GROUPS.leg.includes('shin_r') && SEGMENT_GROUPS.arm.includes('forearm_l'));
});
