import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { Box3, Vector3 } from 'three';
import { animationKeys, getAnimation, hasAnimation, poseAt } from '../../resources/js/modules/animations.js';
import { applyPose, clearProps, createHumanoid, setRegions } from '../../resources/js/modules/humanoid.js';

const read = (file) => JSON.parse(fs.readFileSync(new URL(`../../database/seeders/data/${file}`, import.meta.url), 'utf8'));
const planSlugs = read('plan.json').exercises.map((e) => e.slug);
const librarySlugs = read('exercise-library.json').exercises.map((e) => e.slug);
const allSlugs = [...planSlugs, ...librarySlugs];

const KEYS = animationKeys();
const TIMES = [0, 0.1, 0.25, 0.4, 0.5, 0.6, 0.75, 0.9, 0.999];

test('every seeded exercise has a dedicated 3D animation', () => {
    const missing = allSlugs.filter((slug) => !hasAnimation(slug));
    assert.deepEqual(missing, []);
});

test('there is no animation for an exercise that does not exist', () => {
    const orphans = KEYS.filter((key) => !allSlugs.includes(key));
    assert.deepEqual(orphans, []);
});

test('unknown exercises fall back to a calm idle animation instead of failing', () => {
    const spec = getAnimation('some-future-exercise');
    assert.ok(spec.frames.length >= 2);
    assert.ok(Number.isFinite(poseAt(spec, 0.3).lean));
});

test('every loop closes: first and last keyframe poses match and times are ordered', () => {
    for (const key of KEYS) {
        const { frames } = getAnimation(key);
        assert.equal(frames[0][0], 0, `${key} starts at t=0`);
        assert.equal(frames.at(-1)[0], 1, `${key} ends at t=1`);
        for (let i = 1; i < frames.length; i += 1) assert.ok(frames[i][0] >= frames[i - 1][0], `${key} frame times ascend`);
        const a = poseAt(getAnimation(key), 0);
        const b = poseAt(getAnimation(key), 0.99999);
        for (const k of Object.keys(a)) {
            assert.ok(Math.abs(a[k] - b[k]) < 0.01, `${key}: ${k} jumps at the loop point (${a[k]} vs ${b[k]})`);
        }
    }
});

test('poses are finite and joint angles stay in a believable range', () => {
    const limits = { el: [-5, 165], kn: [-5, 165], E: [-30, 185], hF: [-60, 140], an: [-60, 60], lean: [-60, 125], neck: [-60, 60] };
    for (const key of KEYS) {
        const spec = getAnimation(key);
        for (const t of TIMES) {
            const pose = poseAt(spec, t);
            for (const [k, v] of Object.entries(pose)) {
                assert.ok(Number.isFinite(v), `${key}@${t}: ${k} is not finite`);
                const base = k.replace(/^[lr](?=[A-Z])/, '');
                if (limits[base]) assert.ok(v >= limits[base][0] && v <= limits[base][1], `${key}@${t}: ${k}=${v} outside ${limits[base]}`);
            }
        }
    }
});

test('rigs pose without NaN, props build, and feet rest on the floor for standing and seated lifts', () => {
    const rig = createHumanoid();
    const v = new Vector3();
    for (const key of KEYS) {
        const spec = getAnimation(key);
        clearProps(rig);
        applyPose(rig, poseAt(spec, 0), spec.setup);
        const props = spec.setup.props ? spec.setup.props(rig, { pelvis: rig.pelvis.getWorldPosition(new Vector3()) }).flat().filter(Boolean) : [];
        for (const t of TIMES) {
            applyPose(rig, poseAt(spec, t), spec.setup);
            props.forEach((p) => p.update?.(rig));
            rig.props.updateMatrixWorld(true);
            const box = new Box3().setFromObject(rig.root);
            assert.ok([box.min.x, box.min.y, box.min.z, box.max.x, box.max.y, box.max.z].every(Number.isFinite), `${key}@${t}: NaN in body bounds`);

            if (spec.setup.ground === 'feet') {
                const lowest = Math.min(...[rig.legL.toe, rig.legL.heel, rig.legR.toe, rig.legR.heel].map((o) => o.getWorldPosition(v).y));
                assert.ok(Math.abs(lowest) < 1e-6, `${key}@${t}: lowest foot point is ${lowest.toFixed(4)} m, expected 0`);
            }
            if (spec.setup.ground === 'hands') {
                const hands = (rig.armL.wrist.getWorldPosition(v).y + rig.armR.wrist.getWorldPosition(new Vector3()).y) / 2;
                assert.ok(Math.abs(hands - (spec.setup.handsY - 0.06)) < 1e-6, `${key}@${t}: hands are not on the bar`);
            }
            assert.ok(box.max.y < 3.2 && box.min.y > -0.05, `${key}@${t}: body leaves the scene (y ${box.min.y.toFixed(2)}..${box.max.y.toFixed(2)})`);
        }
    }
});

test('muscle highlighting shows only the requested regions', () => {
    const rig = createHumanoid();
    setRegions(rig, { primary: ['biceps'], secondary: ['forearms'] });
    const visible = Object.entries(rig.patches).filter(([, meshes]) => meshes.some((m) => m.visible)).map(([r]) => r).sort();
    assert.deepEqual(visible, ['biceps', 'forearms']);
    setRegions(rig, {});
    assert.equal(Object.values(rig.patches).flat().some((m) => m.visible), false);
});
