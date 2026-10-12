/**
 * Records what the web's posture engine (resources/js/modules) does with deterministic synthetic poses, so the Dart
 * port in mobile/lib/features/posture/domain can be checked against it frame by frame.
 *
 *   node scripts/export-posture-parity.mjs          writes mobile/test/fixtures/posture_parity.json.gz
 *   node scripts/export-posture-parity.mjs --check  fails if the committed file is out of date (CI); it compares the
 *                                                   decompressed JSON, so a different zlib build cannot cause a diff
 *
 * The file holds, per scenario: the landmarks fed in (quantised to 1e-4 so both languages read exactly the same
 * numbers), what the JavaScript engine answered for every frame, and its final session summary. It also holds the
 * list of problems validateRule() reports for a set of deliberately broken rule configs.
 *
 * The inputs are stick figures (tests/js/helpers/synthetic-pose.mjs). That is enough to prove the two engines agree;
 * it says nothing about how well a real camera and a real pose model will behave.
 */
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';
import { PostureAnalyzer } from '../resources/js/modules/posture-analyzer.js';
import { validateRule } from '../resources/js/modules/rule-engine.js';
import { loadAllRules, loadRuleFile, mutate } from '../tests/js/helpers/rules.mjs';
import { frontFigure, gaussian, plankFigure, repProfile, rng, sideFigure } from '../tests/js/helpers/synthetic-pose.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.resolve(here, '../mobile/test/fixtures/posture_parity.json.gz');

const ASPECT = 4 / 3;
const PORTRAIT = 3 / 4;

// ---------------------------------------------------------------------------------------------
// Figures (the same ones tests/js/posture-analyzer.test.mjs uses)
// ---------------------------------------------------------------------------------------------

const squatFigure = (p, { facing = 1, lean = 36, heelLift = 0, near = 'l', aspect = ASPECT } = {}) => sideFigure({
    facing, near, aspect, ankleX: 0.5 * aspect, shank: 6 + 22 * p, kneeFlex: 12 + 90 * p, torso: 2 + lean * p, heelLift: heelLift * p,
});
const curlFigure = (p, { swing = 0, drift = 0 } = {}) => sideFigure({
    shank: 2, kneeFlex: 4, torso: 2 + swing * p, ua: 180 - drift * p, elbow: 162 - 114 * p, elbowDir: 1,
});
const raiseFigure = (p, opts = {}) => frontFigure({ hipHalf: 0.09, abduction: 12 + 76 * p, ...opts });

// ---------------------------------------------------------------------------------------------
// Scenarios
// ---------------------------------------------------------------------------------------------

const squat = (reps, depth = 1, extra = {}) => {
    const profile = repProfile({ reps, depth });
    return { profile, figure: (t) => squatFigure(profile.p(t), extra) };
};

function scenarios() {
    const list = [];

    {
        const { profile, figure } = squat(3);
        list.push({ id: 'squat_clean', rule: 'barbell-squat', aspect: ASPECT, fps: 15, durationMs: profile.duration, noise: 0.0015, figure });
    }
    {
        const { profile, figure } = squat(3, 0.65, { lean: 82, heelLift: 0.05, facing: -1, near: 'r' });
        list.push({ id: 'squat_shallow_lean_heels_mirrored', rule: 'barbell-squat', aspect: ASPECT, fps: 15, durationMs: profile.duration, noise: 0.0015, figure });
    }
    {
        const { profile, figure } = squat(3, 1, { aspect: PORTRAIT });
        list.push({ id: 'squat_portrait_frame', rule: 'barbell-squat', aspect: PORTRAIT, fps: 15, durationMs: profile.duration, noise: 0.0015, figure });
    }
    {
        const profile = repProfile({ reps: 2, restMs: 1500 });
        list.push({ id: 'squat_walks_out_and_back', rule: 'barbell-squat', aspect: ASPECT, fps: 15, durationMs: 9000, noise: 0, figure: (t) => (t < 3200 || t > 6500 ? squatFigure(profile.p(t % 4000)) : null) });
    }
    {
        const hidden = squatFigure(0);
        hidden[27] = { ...hidden[27], visibility: 0.05 };
        hidden[28] = { ...hidden[28], visibility: 0.05 };
        const standing = squatFigure(0);
        list.push({ id: 'squat_hidden_ankles_then_visible', rule: 'barbell-squat', aspect: ASPECT, fps: 15, durationMs: 4000, noise: 0, figure: (t) => (t < 2000 ? hidden : standing) });
    }
    {
        const profile = repProfile({ reps: 3, downMs: 1100, upMs: 1300 });
        list.push({ id: 'curl_swing_and_elbow_drift', rule: 'barbell-curl', aspect: ASPECT, fps: 15, durationMs: profile.duration, noise: 0.0015, figure: (t) => curlFigure(profile.p(t), { swing: 28, drift: 30 }) });
    }
    {
        const profile = repProfile({ reps: 3, downMs: 1100, upMs: 1300 });
        list.push({ id: 'dumbbell_press_with_phases', rule: 'incline-dumbbell-press', aspect: ASPECT, fps: 15, durationMs: profile.duration, noise: 0.001, figure: (t) => curlFigure(profile.p(t)) });
    }
    {
        const profile = repProfile({ reps: 3, downMs: 1100, upMs: 1300 });
        list.push({
            id: 'lateral_raise_uneven_and_shrug',
            rule: 'side-lateral-raise',
            aspect: ASPECT,
            fps: 15,
            durationMs: profile.duration,
            noise: 0.001,
            figure: (t) => {
                const p = profile.p(t);
                return frontFigure({ hipHalf: 0.09, abductionL: 12 + 76 * p, abductionR: 12 + 40 * p, shrug: 0.035 * p });
            },
        });
    }
    {
        const profile = repProfile({ reps: 8, downMs: 500, pauseMs: 0, upMs: 500, gapMs: 80, restMs: 1000 });
        list.push({ id: 'jumping_jacks_fast', rule: 'jumping-jacks', aspect: ASPECT, fps: 30, durationMs: profile.duration, noise: 0, figure: (t) => frontFigure({ hipHalf: 0.09, abduction: 20 + 140 * profile.p(t), feetHalf: 0.07 + 0.08 * profile.p(t) }) });
    }
    list.push({
        id: 'plank_sags_halfway',
        rule: 'plank',
        aspect: ASPECT,
        fps: 15,
        durationMs: 16000,
        noise: 0.0015,
        figure: (t) => (t < 1500 ? sideFigure({}) : plankFigure({ hipOffset: t > 8000 ? -0.32 : 0 })),
        flushAt: 16000,
    });
    list.push({
        id: 'plank_too_short_to_count',
        rule: 'plank',
        aspect: ASPECT,
        fps: 15,
        durationMs: 9000,
        noise: 0,
        figure: (t) => (t > 1000 && t < 4000 ? plankFigure({}) : sideFigure({})),
    });
    list.push({ id: 'nobody_in_view', rule: 'barbell-squat', aspect: ASPECT, fps: 15, durationMs: 1000, noise: 0, figure: () => null });

    // random landmarks, dropouts and teleporting joints: both engines must stay finite and still agree
    for (const slug of ['barbell-squat', 'side-lateral-raise', 'plank']) {
        const rand = rng(99);
        const frames = Array.from({ length: 70 }, (_, i) => (i % 17 === 0 ? null : Array.from({ length: 33 }, () => ({ x: rand() * 1.4 - 0.2, y: rand() * 1.4 - 0.2, z: 0, visibility: rand() }))));
        list.push({ id: `garbage_${slug.replaceAll('-', '_')}`, rule: slug, aspect: ASPECT, fps: 30, durationMs: 69 * (1000 / 30), noise: 0, figure: null, frames });
    }
    return list;
}

// ---------------------------------------------------------------------------------------------
// Recording
// ---------------------------------------------------------------------------------------------

const quantise = (l) => [Math.round(l.x * 1e4), Math.round(l.y * 1e4), Math.round((l.visibility ?? 1) * 100)];
const dequantise = ([x, y, v]) => ({ x: x / 1e4, y: y / 1e4, z: 0, visibility: v / 100 });

function plain(event) {
    return JSON.parse(JSON.stringify(event));
}

function record(s) {
    const analyzer = new PostureAnalyzer(loadRuleFile(s.rule).config, { aspect: s.aspect });
    const rand = rng(11);
    const dt = 1000 / s.fps;
    const frames = [];
    const times = [];
    const expected = [];

    const count = s.frames ? s.frames.length : Math.floor(s.durationMs / dt) + 1;
    for (let i = 0; i < count; i += 1) {
        const t = i * dt;
        let source = s.frames ? s.frames[i] : s.figure(t);
        if (source && s.noise) {
            source = source.map((l) => ({ ...l, x: l.x + gaussian(rand) * s.noise / s.aspect, y: l.y + gaussian(rand) * s.noise }));
        }
        const row = source ? source.flatMap(quantise) : null;
        frames.push(row);
        times.push(t);

        const input = row ? Array.from({ length: 33 }, (_, k) => dequantise(row.slice(k * 3, k * 3 + 3))) : null;
        const r = analyzer.update(input, t, { aspect: s.aspect });
        expected.push(plain({
            status: r.status,
            phase: r.phase,
            reps: r.reps,
            good: r.good,
            bad: r.bad,
            side: r.side,
            facing: r.facing,
            score: r.score,
            progress: r.progress,
            signal: r.signal,
            holdMs: r.holdMs,
            missing: r.missing,
            hidden: [...r.hidden].sort(),
            active: r.active.map((c) => c.id),
            red: [...r.redBones].sort(),
            measures: r.measures,
            events: r.events,
            lastRep: r.lastRep,
        }));
    }

    const endT = times.at(-1) ?? 0;
    const flushEvents = analyzer.flush(s.flushAt ?? endT).map(plain);
    const durationSeconds = Math.max(0, endT / 1000);
    return { id: s.id, rule: s.rule, aspect: s.aspect, times, frames, expected, flushEvents, durationSeconds, summary: plain(analyzer.summary(durationSeconds)) };
}

// ---------------------------------------------------------------------------------------------
// Broken configs: the messages validateRule() gives, in order
// ---------------------------------------------------------------------------------------------

function validationCases() {
    const squatCfg = loadRuleFile('barbell-squat').config;
    const plankCfg = loadRuleFile('plank').config;
    const raiseCfg = loadRuleFile('side-lateral-raise').config;
    const pushCfg = loadRuleFile('push-up').config;

    const lineOffsetMeasure = Object.entries(pushCfg.measures).find(([, m]) => m.type === 'line_offset')[0];
    const diffMeasure = Object.entries(raiseCfg.measures).find(([, m]) => m.type === 'diff')[0];

    const cases = [
        ['not an object (null)', null, null],
        ['not an object (list)', [], null],
        ['schema 2', squatCfg, { set: { schema: 2 } }],
        ['unknown side', squatCfg, { set: { side: 'middle' } }],
        ['min_visibility too low', squatCfg, { set: { min_visibility: 0.05 } }],
        ['min_visibility too high', squatCfg, { set: { min_visibility: 0.99 } }],
        ['min_visibility text', squatCfg, { set: { min_visibility: 'high' } }],
        ['good_score above 100', squatCfg, { set: { good_score: 101 } }],
        ['smoothing cutoff zero', squatCfg, { set: { smoothing: { min_cutoff: 0 } } }],
        ['smoothing negative beta', squatCfg, { set: { smoothing: { beta: -1 } } }],
        ['smoothing not an object', squatCfg, { set: { smoothing: 'smooth' } }],
        ['required empty', squatCfg, { set: { required: [] } }],
        ['required missing', squatCfg, { unset: ['required'] }],
        ['required unknown point', squatCfg, { set: { required: ['shoulder', 'elbowz'] } }],
        ['measures empty', squatCfg, { set: { measures: {} } }],
        ['measure name invalid', squatCfg, { set: { measures: { 'Bad Name': { type: 'angle', points: ['hip', 'knee', 'ankle'] } } } }],
        ['measure not an object', squatCfg, { set: { 'measures.knee': 5 } }],
        ['measure unknown type', squatCfg, { set: { 'measures.knee.type': 'curve' } }],
        ['measure bad combine', squatCfg, { set: { 'measures.knee.combine': 'sum' } }],
        ['measure bad relative', squatCfg, { set: { 'measures.knee.relative': 'percent' } }],
        ['measure bad norm', squatCfg, { set: { 'measures.knee_over_toe.norm': 'height' } }],
        ['angle with two points', squatCfg, { set: { 'measures.knee.points': ['hip', 'knee'] } }],
        ['angle unknown point', squatCfg, { set: { 'measures.knee.points': ['hip', 'knee', 'toe'] } }],
        ['inclination bad ref', squatCfg, { set: { 'measures.torso_lean.ref': 'diagonal' } }],
        ['inclination unknown from', squatCfg, { set: { 'measures.torso_lean.from': 'belly' } }],
        ['offset bad axis', squatCfg, { set: { 'measures.knee_over_toe.axis': 'z' } }],
        ['ratio num too short', raiseCfg, { set: { 'measures.neck_gap.num': ['ear'] } }],
        ['line_offset missing point', pushCfg, { unset: [`measures.${lineOffsetMeasure}.point`] }],
        ['diff references itself', raiseCfg, { set: { [`measures.${diffMeasure}.a`]: diffMeasure } }],
        ['diff references nothing', raiseCfg, { set: { [`measures.${diffMeasure}.b`]: 'nope' } }],
        ['rep not an object', squatCfg, { set: { rep: 'reps' } }],
        ['rep signal not a measure', squatCfg, { set: { 'rep.signal': 'elbow' } }],
        ['rep start equals peak', squatCfg, { set: { 'rep.start': 100, 'rep.peak': 100 } }],
        ['rep start not a number', squatCfg, { set: { 'rep.start': 'high' } }],
        ['rep tolerance zero', squatCfg, { set: { 'rep.tolerance': 0 } }],
        ['rep tolerance too large', squatCfg, { set: { 'rep.tolerance': 60 } }],
        ['rep min_ms too small', squatCfg, { set: { 'rep.min_ms': 50 } }],
        ['rep max_ms below min', squatCfg, { set: { 'rep.max_ms': 500 } }],
        ['rep min_rom out of range', squatCfg, { set: { 'rep.min_rom': 2 } }],
        ['rep settle_ms negative', squatCfg, { set: { 'rep.settle_ms': -1 } }],
        ['rep fast_ms below min', squatCfg, { set: { 'rep.fast_ms': 900 } }],
        ['rep partial_message empty', squatCfg, { set: { 'rep.partial_message': '  ' } }],
        ['rep partial_message too long', squatCfg, { set: { 'rep.partial_message': 'x'.repeat(111) } }],
        ['hold without in_position', plankCfg, { unset: ['rep.in_position'] }],
        ['hold condition unknown measure', plankCfg, { set: { 'rep.in_position.0.measure': 'nope' } }],
        ['hold condition bad op', plankCfg, { set: { 'rep.in_position.0.op': '=' } }],
        ['hold condition bad range', plankCfg, { set: { 'rep.in_position.0.op': 'inside', 'rep.in_position.0.value': [10, 5] } }],
        ['hold grace negative', plankCfg, { set: { 'rep.grace_ms': -5 } }],
        ['checks not a list', squatCfg, { set: { checks: 'none' } }],
        ['check not an object', squatCfg, { set: { 'checks.0': 7 } }],
        ['check id invalid', squatCfg, { set: { 'checks.0.id': 'Bad-Id' } }],
        ['check duplicate id', squatCfg, { set: { 'checks.1.id': 'forward_lean' } }],
        ['check label empty', squatCfg, { set: { 'checks.0.label': '' } }],
        ['check message missing', squatCfg, { unset: ['checks.0.message'] }],
        ['check unknown measure', squatCfg, { set: { 'checks.0.measure': 'nope' } }],
        ['check bad severity', squatCfg, { set: { 'checks.0.severity': 'fatal' } }],
        ['check bad op', squatCfg, { set: { 'checks.0.op': '>=' } }],
        ['check outside needs a range', squatCfg, { set: { 'checks.0.op': 'outside', 'checks.0.value': 5 } }],
        ['check value not a number', squatCfg, { set: { 'checks.0.value': 'big' } }],
        ['check bad scope', squatCfg, { set: { 'checks.0.scope': 'set' } }],
        ['check rep scope without stat', squatCfg, { unset: ['checks.3.stat'] }],
        ['check rep scope on a hold', plankCfg, { set: { 'checks.0.scope': 'rep', 'checks.0.stat': 'max' } }],
        ['check phases invalid', squatCfg, { set: { 'checks.0.phases': ['sky'] } }],
        ['check frames zero', squatCfg, { set: { 'checks.0.frames': 0 } }],
        ['check frames too many', squatCfg, { set: { 'checks.0.frames': 31 } }],
        ['check frames fractional', squatCfg, { set: { 'checks.0.frames': 2.5 } }],
        ['check segments not a list', squatCfg, { set: { 'checks.0.segments': 'torso' } }],
        ['check unknown segment', squatCfg, { set: { 'checks.0.segments': ['torso', 'tail'] } }],
    ];

    return cases.map(([name, base, edit]) => {
        const config = edit ? mutate(base, edit) : base;
        return { name, config, errors: validateRule(config) };
    });
}

// ---------------------------------------------------------------------------------------------

const rules = loadAllRules();
for (const r of rules) {
    const errors = validateRule(r.config);
    if (errors.length) throw new Error(`${r.file} is not a valid rule: ${errors.join('; ')}`);
}

const data = { generated_by: 'scripts/export-posture-parity.mjs', rules: rules.map((r) => r.file.replace(/\.json$/, '')).sort(), validation: validationCases(), scenarios: scenarios().map(record) };

for (const c of data.validation) {
    if (c.errors.length === 0) throw new Error(`validation case "${c.name}" no longer produces an error; fix the case`);
}

const lines = ['{', `"generated_by": ${JSON.stringify(data.generated_by)},`, `"rules": ${JSON.stringify(data.rules)},`, '"validation": ['];
lines.push(data.validation.map((c) => JSON.stringify(c)).join(',\n'), '],', '"scenarios": [');
lines.push(data.scenarios.map((s) => {
    const { frames, expected, ...rest } = s;
    return `{"head": ${JSON.stringify(rest)},\n"frames": [\n${frames.map((f) => JSON.stringify(f)).join(',\n')}\n],\n"expected": [\n${expected.map((e) => JSON.stringify(e)).join(',\n')}\n]}`;
}).join(',\n'), ']', '}', '');
const text = lines.join('\n');
JSON.parse(text); // must be valid JSON

if (process.argv.includes('--check')) {
    const current = fs.existsSync(OUT) ? zlib.gunzipSync(fs.readFileSync(OUT)).toString('utf8') : '';
    if (current !== text) {
        console.error('mobile/test/fixtures/posture_parity.json.gz is out of date. Run: node scripts/export-posture-parity.mjs');
        process.exit(1);
    }
    console.log('posture parity fixtures are up to date');
} else {
    fs.mkdirSync(path.dirname(OUT), { recursive: true });
    const packed = zlib.gzipSync(text, { level: 9 });
    fs.writeFileSync(OUT, packed);
    console.log(`wrote ${path.relative(process.cwd(), OUT)} (${(packed.length / 1024).toFixed(0)} KB packed, ${(text.length / 1024).toFixed(0)} KB of JSON, ${data.scenarios.length} scenarios, ${data.validation.length} validation cases)`);
}
