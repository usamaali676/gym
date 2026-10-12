import assert from 'node:assert/strict';
import test from 'node:test';
import { POINT_NAMES, SEGMENT_GROUPS } from '../../resources/js/modules/geometry.js';
import {
    AXES, COMBINE, MEASURE_TYPES, NORMS, OPS, PHASES, RELATIVE, RuleConfigError, SEVERITIES, SIDES, STATS,
    compileRule, evaluateMeasures, validateRule, violates,
} from '../../resources/js/modules/rule-engine.js';
import { loadAllRules, loadJson, loadRuleFile, mutate } from './helpers/rules.mjs';
import { ASPECT, frontFigure, plankFigure, sideFigure } from './helpers/synthetic-pose.mjs';

// ---------------------------------------------------------------------------------------------
// Validation (shared fixture with the PHP validator)
// ---------------------------------------------------------------------------------------------

const fixture = loadJson('tests/fixtures/posture-rule-invalid.json');

test('the valid base rules pass validation', () => {
    for (const slug of new Set([fixture.valid_base, ...fixture.cases.map((c) => c.base).filter(Boolean)])) {
        assert.deepEqual(validateRule(loadRuleFile(slug).config), [], slug);
    }
});

for (const c of fixture.cases) {
    test(`rejects: ${c.name}`, () => {
        const base = loadRuleFile(c.base ?? fixture.valid_base).config;
        const errors = validateRule(mutate(base, c));
        assert.ok(errors.includes(c.expect), `expected "${c.expect}" in ${JSON.stringify(errors)}`);
        assert.throws(() => compileRule(mutate(base, c)), RuleConfigError);
    });
}

test('the JS vocabulary matches the shared fixture (the PHP validator is tested against the same file)', () => {
    const vocab = loadJson('tests/fixtures/posture-vocabulary.json');
    assert.deepEqual([...POINT_NAMES].sort(), vocab.points);
    assert.deepEqual(Object.keys(SEGMENT_GROUPS).sort(), vocab.segments);
    assert.deepEqual(SIDES, vocab.sides);
    assert.deepEqual(MEASURE_TYPES, vocab.measure_types);
    assert.deepEqual(NORMS, vocab.norms);
    assert.deepEqual(AXES, vocab.axes);
    assert.deepEqual(OPS, vocab.ops);
    assert.deepEqual(PHASES, vocab.phases);
    assert.deepEqual(STATS, vocab.stats);
    assert.deepEqual(SEVERITIES, vocab.severities);
    assert.deepEqual(COMBINE, vocab.combine);
    assert.deepEqual(RELATIVE, vocab.relative);
});

test('non-objects are rejected', () => {
    assert.deepEqual(validateRule(null), ['config must be an object']);
    assert.deepEqual(validateRule([]), ['config must be an object']);
});

test('compile fills in defaults and freezes the result', () => {
    const rule = compileRule(loadRuleFile('barbell-curl').config);
    assert.equal(rule.side, 'auto');
    assert.equal(rule.minVisibility, 0.5);
    assert.equal(rule.goodScore, 75);
    assert.equal(rule.rep.kind, 'movement');
    assert.equal(rule.checks.find((c) => c.id === 'body_swing').frames, 5);
    assert.deepEqual(rule.checks.find((c) => c.id === 'body_swing').phases, ['out', 'peak', 'back']);
    assert.throws(() => { rule.rep.start = 1; }, TypeError);

    const plank = compileRule(loadRuleFile('plank').config);
    assert.equal(plank.rep.kind, 'hold');
    assert.deepEqual(plank.checks[0].phases, ['hold']);
});

// ---------------------------------------------------------------------------------------------
// Shipped rules
// ---------------------------------------------------------------------------------------------

const plan = loadJson('database/seeders/data/plan.json');
const library = loadJson('database/seeders/data/exercise-library.json').exercises;
const rules = loadAllRules();

test('every shipped rule is valid JSON for the engine, with unique check ids', () => {
    assert.equal(rules.length, 35);
    for (const r of rules) {
        assert.deepEqual(validateRule(r.config), [], r.file);
        const ids = r.config.checks.map((c) => c.id);
        assert.equal(new Set(ids).size, ids.length, `${r.file}: duplicate check ids`);
        for (const c of r.config.checks) {
            assert.ok(c.message.length <= 110, `${r.file}/${c.id}: message is too long to read mid-rep`);
            for (const g of c.segments ?? []) assert.ok(SEGMENT_GROUPS[g], `${r.file}/${c.id}: segment ${g}`);
        }
    }
});

test('every plan exercise has exactly one rule file with the tier from the plan', () => {
    const bySlug = Object.fromEntries(rules.map((r) => [r.exercise, r]));
    for (const e of plan.exercises) {
        const r = bySlug[e.slug];
        assert.ok(r, `${e.slug} has no posture rule`);
        assert.equal(r.tier, e.posture_tier, `${e.slug} tier`);
    }
    assert.equal(rules.filter((r) => r.tier === 'full').length, 18);
    assert.equal(rules.filter((r) => r.tier === 'simplified').length, 13);
    for (const h of library.filter((x) => x.kind === 'helper')) {
        assert.equal(bySlug[h.slug]?.tier, 'generic', `${h.slug} should have a generic rule`);
    }
    assert.equal(rules.filter((r) => r.tier === 'generic').length, 4);
    assert.equal(new Set(rules.map((r) => r.exercise)).size, rules.length);
});

test('rules carry honest guidance: camera steps, what is tracked, and an accuracy note', () => {
    for (const r of rules) {
        assert.ok(['side', 'front', 'three_quarter'].includes(r.camera_view), r.file);
        assert.ok(r.guidance.camera.steps.length >= 3, r.file);
        assert.ok(r.guidance.tracks.length >= 2, r.file);
        assert.ok(r.guidance.accuracy.length > 60, r.file);
    }
    const machine = rules.find((r) => r.exercise === 'leg-press');
    assert.match(machine.guidance.accuracy, /hide parts of your body/);
});

test('machine and cable rules are intentionally smaller than the free-weight rules', () => {
    const avg = (tier) => {
        const set = rules.filter((r) => r.tier === tier);
        return set.reduce((s, r) => s + r.config.checks.length, 0) / set.length;
    };
    assert.ok(avg('simplified') < avg('full'));
});

// ---------------------------------------------------------------------------------------------
// Measures on synthetic landmarks
// ---------------------------------------------------------------------------------------------

function viewFrom(landmarks, facing = 1) {
    return {
        points: landmarks.map((l) => ({ x: l.x * ASPECT, y: l.y, v: l.visibility })),
        minVis: 0.5,
        facing,
        aspect: ASPECT,
    };
}

test('elbow, knee and torso measures read the angles the figure was built with', () => {
    const rule = compileRule({
        schema: 1,
        required: ['shoulder', 'hip'],
        measures: {
            elbow: { type: 'angle', points: ['shoulder', 'elbow', 'wrist'] },
            knee: { type: 'angle', points: ['hip', 'knee', 'ankle'] },
            torso_lean: { type: 'inclination', from: 'hip', to: 'shoulder', ref: 'vertical' },
        },
        rep: { signal: 'elbow', start: 160, peak: 50, tolerance: 14 },
    });
    const fig = sideFigure({ elbow: 120, kneeFlex: 70, torso: 25, shank: 10 });
    const { values } = evaluateMeasures(rule, viewFrom(fig), 'l');
    assert.ok(Math.abs(values.elbow - 120) < 0.5, `elbow ${values.elbow}`);
    assert.ok(Math.abs(values.knee - 110) < 0.5, `knee ${values.knee}`);
    assert.ok(Math.abs(values.torso_lean - 25) < 0.5, `torso ${values.torso_lean}`);
});

test('hidden landmarks give null, never a made-up number', () => {
    const rule = compileRule({
        schema: 1, required: ['shoulder'],
        measures: { elbow: { type: 'angle', points: ['shoulder', 'elbow', 'wrist'] } },
        rep: { signal: 'elbow', start: 160, peak: 50, tolerance: 14 },
    });
    const fig = sideFigure({});
    fig[15] = { ...fig[15], visibility: 0.1 }; // l_wrist
    const { values, missing } = evaluateMeasures(rule, viewFrom(fig), 'l');
    assert.equal(values.elbow, null);
    assert.ok(missing.has('wrist'));
});

test('the forward axis flips with the direction the person faces', () => {
    const rule = compileRule({
        schema: 1, required: ['knee'],
        measures: { knee_over_toe: { type: 'offset', from: 'foot_index', to: 'knee', axis: 'forward', norm: 'shank' } },
        rep: { signal: 'knee_over_toe', start: -0.3, peak: 0.6, tolerance: 0.1 },
    });
    const right = evaluateMeasures(rule, viewFrom(sideFigure({ facing: 1, shank: 28, kneeFlex: 110 }), 1), 'l').values.knee_over_toe;
    const left = evaluateMeasures(rule, viewFrom(sideFigure({ facing: -1, shank: 28, kneeFlex: 110 }), -1), 'l').values.knee_over_toe;
    assert.ok(right > 0, `knee should be past the toes, got ${right}`);
    assert.ok(Math.abs(right - left) < 0.02, `mirrored figure should read the same: ${right} vs ${left}`);
});

test('line_offset tells hips sagging from piking', () => {
    const rule = compileRule(loadRuleFile('plank').config);
    const read = (hipOffset) => evaluateMeasures(rule, viewFrom(plankFigure({ hipOffset })), 'l').values.hip_line;
    assert.ok(read(-0.3) < -0.14, `sag ${read(-0.3)}`);
    assert.ok(Math.abs(read(0)) < 0.03, `straight ${read(0)}`);
    assert.ok(read(0.3) > 0.16, `pike ${read(0.3)}`);
});

test('side "both" combines left and right measures and diff measures compare the sides', () => {
    const rule = compileRule(loadRuleFile('side-lateral-raise').config);
    const even = evaluateMeasures(rule, viewFrom(frontFigure({ abduction: 60, hipHalf: 0.09 })), 'l').values;
    assert.ok(Math.abs(even.abduction - 60) < 1, `abduction ${even.abduction}`);
    assert.ok(even.arm_unevenness < 1);
    const uneven = evaluateMeasures(rule, viewFrom(frontFigure({ abductionL: 80, abductionR: 40, hipHalf: 0.09 })), 'l').values;
    assert.ok(Math.abs(uneven.abduction - 60) < 1.5);
    assert.ok(Math.abs(uneven.arm_unevenness - 40) < 1.5, `unevenness ${uneven.arm_unevenness}`);
});

test('violates handles every operator and ignores unknown values', () => {
    assert.equal(violates(5, '<', 10), true);
    assert.equal(violates(15, '<', 10), false);
    assert.equal(violates(15, '>', 10), true);
    assert.equal(violates(-15, '>', 10, true), true);
    assert.equal(violates(5, 'outside', [6, 9]), true);
    assert.equal(violates(7, 'outside', [6, 9]), false);
    assert.equal(violates(7, 'inside', [6, 9]), true);
    assert.equal(violates(null, '<', 10), false);
    assert.equal(violates(Number.NaN, '>', 1), false);
});
