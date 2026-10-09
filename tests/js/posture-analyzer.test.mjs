import assert from 'node:assert/strict';
import test from 'node:test';
import { PostureAnalyzer } from '../../resources/js/modules/posture-analyzer.js';
import { compileRule } from '../../resources/js/modules/rule-engine.js';
import { RepCounter } from '../../resources/js/modules/rep-counter.js';
import { loadAllRules, loadRuleFile } from './helpers/rules.mjs';
import {
    ASPECT, drive, frontFigure, plankFigure, repProfile, rng, sideFigure,
} from './helpers/synthetic-pose.mjs';

const analyzerFor = (slug) => new PostureAnalyzer(loadRuleFile(slug).config, { aspect: ASPECT });
const ids = (events, type) => events.filter((e) => e.type === type);

// ---------------------------------------------------------------------------------------------
// Figures
// ---------------------------------------------------------------------------------------------

const squatFigure = (p, { facing = 1, lean = 36, heelLift = 0, near = 'l' } = {}) => sideFigure({
    facing, near, shank: 6 + 22 * p, kneeFlex: 12 + 90 * p, torso: 2 + lean * p, heelLift: heelLift * p,
});

const curlFigure = (p, { swing = 0, drift = 0, facing = 1 } = {}) => sideFigure({
    facing, shank: 2, kneeFlex: 4, torso: 2 + swing * p, ua: 180 - drift * p, elbow: 162 - 114 * p, elbowDir: 1,
});

function squatSession({ reps = 5, depth = 1, ...figure } = {}, driveOpts = {}) {
    const profile = repProfile({ reps, depth });
    const analyzer = analyzerFor('barbell-squat');
    const out = drive(analyzer, (t) => squatFigure(profile.p(t), figure), { durationMs: profile.duration, noise: 0.0015, ...driveOpts });
    return { analyzer, ...out };
}

// ---------------------------------------------------------------------------------------------
// Squat
// ---------------------------------------------------------------------------------------------

test('good squats: every rep counted and none flagged', () => {
    const { analyzer, last, events } = squatSession({ reps: 5 });
    assert.equal(last.reps, 5);
    assert.equal(last.good, 5);
    assert.equal(last.bad, 0);
    assert.equal(ids(events, 'cue').length, 0, 'no coaching cues on a clean set');
    const s = analyzer.summary(20);
    assert.equal(s.total_reps, 5);
    assert.equal(s.form_score_avg, 100);
    assert.deepEqual(s.error_counts, {});
});

test('squats seen from the left and the right of the image give the same result', () => {
    for (const facing of [1, -1]) {
        const { last } = squatSession({ reps: 4, facing });
        assert.equal(last.reps, 4, `facing ${facing}`);
        assert.equal(last.good, 4, `facing ${facing}`);
    }
});

test('the side facing the camera is chosen, whichever side that is', () => {
    const { last } = squatSession({ reps: 4, near: 'r' });
    assert.equal(last.side, 'r');
    assert.equal(last.good, 4);
});

test('shallow squats are partial reps and tell the lifter to go deeper', () => {
    const { last, events, analyzer } = squatSession({ reps: 3, depth: 0.65 });
    assert.equal(last.reps, 3);
    assert.equal(last.bad, 3);
    const rep = ids(events, 'rep')[0];
    assert.equal(rep.good, false);
    assert.ok(rep.errors.includes('range'));
    assert.match(rep.feedback, /Squat deeper/);
    assert.equal(analyzer.summary(10).error_counts.range, 3);
});

test('a tiny dip is not counted at all', () => {
    const { last } = squatSession({ reps: 3, depth: 0.2 });
    assert.equal(last.reps, 0);
});

test('leaning too far forward turns the torso red and makes the rep bad', () => {
    const { last, events, results } = squatSession({ reps: 3, lean: 82 });
    assert.equal(last.reps, 3);
    assert.equal(last.bad, 3);
    const rep = ids(events, 'rep')[0];
    assert.ok(rep.errors.includes('forward_lean'));
    assert.match(rep.feedback, /Chest up/);
    const red = results.find((r) => r.active.some((c) => c.id === 'forward_lean'));
    assert.ok(red, 'forward_lean should become active during the set');
    assert.ok(red.redBones.has('torso_l') && red.redBones.has('torso_r'));
    assert.ok(!red.redBones.has('shin_l'), 'unrelated bones stay green');
    assert.ok(ids(events, 'cue').some((c) => c.id === 'forward_lean'));
});

test('lifting the heels is a minor error: flagged, but the rep still counts as good', () => {
    const { last, events, analyzer } = squatSession({ reps: 4, heelLift: 0.05 });
    assert.equal(last.reps, 4);
    const rep = ids(events, 'rep')[0];
    assert.ok(rep.errors.includes('heels_lifting'));
    assert.ok(rep.score < 100 && rep.score >= 75);
    assert.equal(last.good, 4);
    assert.ok(analyzer.summary(20).form_score_avg < 100);
});

test('a cue is spoken once per rep at most, never every frame', () => {
    const { events } = squatSession({ reps: 6, lean: 82 });
    const cues = ids(events, 'cue').filter((c) => c.id === 'forward_lean');
    assert.ok(cues.length >= 1 && cues.length <= 6, `${cues.length} cues for 6 reps`);
});

test('camera jitter alone does not produce phantom reps or errors', () => {
    const analyzer = analyzerFor('barbell-squat');
    const standing = squatFigure(0);
    const { last, events } = drive(analyzer, () => standing, { durationMs: 12000, noise: 0.004 });
    assert.equal(last.reps, 0);
    assert.equal(ids(events, 'cue').length, 0);
    assert.equal(last.status, 'ready');
});

// ---------------------------------------------------------------------------------------------
// Curl: relative measures (swing, elbow drift)
// ---------------------------------------------------------------------------------------------

test('strict curls are all good', () => {
    const profile = repProfile({ reps: 6, downMs: 1100, upMs: 1300 });
    const { last } = drive(analyzerFor('barbell-curl'), (t) => curlFigure(profile.p(t)), { durationMs: profile.duration, noise: 0.0015 });
    assert.equal(last.reps, 6);
    assert.equal(last.good, 6);
});

test('swinging the torso is caught relative to where the lifter started', () => {
    const profile = repProfile({ reps: 4, downMs: 1100, upMs: 1300 });
    const { last, events } = drive(analyzerFor('barbell-curl'), (t) => curlFigure(profile.p(t), { swing: 28 }), { durationMs: profile.duration, noise: 0.0015 });
    assert.equal(last.reps, 4);
    assert.equal(last.bad, 4);
    assert.ok(ids(events, 'rep')[0].errors.includes('body_swing'));
});

test('elbows drifting forward are caught', () => {
    const profile = repProfile({ reps: 4, downMs: 1100, upMs: 1300 });
    const { events } = drive(analyzerFor('barbell-curl'), (t) => curlFigure(profile.p(t), { drift: 50 }), { durationMs: profile.duration, noise: 0.0015 });
    const rep = ids(events, 'rep')[0];
    assert.ok(rep.errors.includes('elbow_drift'));
    assert.ok(rep.problems.find((p) => p.id === 'elbow_drift').message.length > 0);
});

test('a lifter who starts curling from a leaning stance is not penalised for the lean itself', () => {
    const profile = repProfile({ reps: 4, downMs: 1100, upMs: 1300 });
    const leaning = (p) => sideFigure({ shank: 2, kneeFlex: 4, torso: 14, ua: 180, elbow: 162 - 114 * p });
    const { last } = drive(analyzerFor('barbell-curl'), (t) => leaning(profile.p(t)), { durationMs: profile.duration, noise: 0.0015 });
    assert.equal(last.good, 4);
});

// ---------------------------------------------------------------------------------------------
// Front view, both sides
// ---------------------------------------------------------------------------------------------

const raiseFigure = (p, opts = {}) => frontFigure({ hipHalf: 0.09, abduction: 12 + 76 * p, ...opts });

test('lateral raises are counted from the front view', () => {
    const profile = repProfile({ reps: 6, downMs: 1100, upMs: 1300 });
    const analyzer = analyzerFor('side-lateral-raise');
    const { last } = drive(analyzer, (t) => raiseFigure(profile.p(t)), { durationMs: profile.duration, noise: 0.0015 });
    assert.equal(last.reps, 6);
    assert.equal(last.good, 6);
});

test('raising uneven arms is flagged', () => {
    const profile = repProfile({ reps: 4, downMs: 1100, upMs: 1300 });
    const { events } = drive(analyzerFor('side-lateral-raise'), (t) => {
        const p = profile.p(t);
        return frontFigure({ hipHalf: 0.09, abductionL: 12 + 76 * p, abductionR: 12 + 30 * p });
    }, { durationMs: profile.duration, noise: 0.0015 });
    // The left arm drives the count in "both" mode only on average; what matters is that the error is reported.
    assert.ok(ids(events, 'cue').some((c) => c.id === 'uneven') || ids(events, 'rep').some((r) => r.errors.includes('uneven')));
});

test('shrugging the shoulders during a raise is flagged', () => {
    const profile = repProfile({ reps: 4, downMs: 1100, upMs: 1300 });
    const { events } = drive(analyzerFor('side-lateral-raise'), (t) => raiseFigure(profile.p(t), { shrug: 0.035 * profile.p(t) }), { durationMs: profile.duration, noise: 0.001 });
    assert.ok(ids(events, 'rep').some((r) => r.errors.includes('shrugging')));
});

test('jumping jacks count at a fast cadence', () => {
    const profile = repProfile({ reps: 8, downMs: 500, pauseMs: 0, upMs: 500, gapMs: 80, restMs: 1000 });
    const figure = (p) => frontFigure({ hipHalf: 0.09, abduction: 20 + 140 * p, feetHalf: 0.07 + 0.08 * p });
    const { last } = drive(analyzerFor('jumping-jacks'), (t) => figure(profile.p(t)), { durationMs: profile.duration, fps: 30 });
    assert.equal(last.reps, 8);
});

// ---------------------------------------------------------------------------------------------
// Plank (timed hold)
// ---------------------------------------------------------------------------------------------

test('a plank is timed from the moment the body is in position, and sagging hips are flagged', () => {
    const analyzer = analyzerFor('plank');
    const { events, last } = drive(analyzer, (t) => {
        if (t < 1500) return sideFigure({}); // standing
        const sag = t > 8000 ? -0.32 : 0;
        return plankFigure({ hipOffset: sag });
    }, { durationMs: 16000, noise: 0.0015 });
    assert.ok(last.phase === 'hold' || last.holdMs > 0);
    analyzer.flush(16000);
    const s = analyzer.summary(16);
    assert.ok(s.hold_seconds >= 12 && s.hold_seconds <= 15, `hold ${s.hold_seconds}s`);
    assert.equal(s.total_reps, 1);
    assert.ok(s.error_counts.hips_sagging >= 1);
    assert.equal(s.good_reps, 0, 'a major form fault for half the hold means the hold is not "good"');
    assert.ok(ids(events, 'cue').some((c) => c.id === 'hips_sagging'));
});

test('a clean plank is a good hold; leaving early is not counted', () => {
    const clean = analyzerFor('plank');
    drive(clean, (t) => (t < 1000 ? sideFigure({}) : plankFigure({})), { durationMs: 14000, noise: 0.001 });
    clean.flush(14000);
    assert.equal(clean.summary(14).good_reps, 1);

    const short = analyzerFor('plank');
    drive(short, (t) => (t > 1000 && t < 4000 ? plankFigure({}) : sideFigure({})), { durationMs: 9000 });
    assert.equal(short.summary(9).total_reps, 0);
});

// ---------------------------------------------------------------------------------------------
// Visibility, absence, robustness
// ---------------------------------------------------------------------------------------------

test('nobody in view', () => {
    const { last } = drive(analyzerFor('barbell-squat'), () => null, { durationMs: 1000 });
    assert.equal(last.status, 'no_person');
    assert.equal(last.reps, 0);
});

test('a hidden required joint asks for a camera adjustment and names the joint', () => {
    const fig = squatFigure(0);
    fig[27] = { ...fig[27], visibility: 0.05 }; // l_ankle
    fig[28] = { ...fig[28], visibility: 0.05 };
    const { last } = drive(analyzerFor('barbell-squat'), () => fig, { durationMs: 1500 });
    assert.equal(last.status, 'adjust');
    assert.ok(last.missing.includes('l_ankle') || last.missing.includes('r_ankle'));
});

test('walking out of frame mid-rep aborts it instead of counting it', () => {
    const profile = repProfile({ reps: 2, restMs: 1500 });
    const analyzer = analyzerFor('barbell-squat');
    const { last, events } = drive(analyzer, (t) => (t < 3200 ? squatFigure(profile.p(t)) : null), { durationMs: 9000 });
    assert.equal(last.reps, 0);
    assert.ok(ids(events, 'abort').length >= 1);
    assert.equal(last.status, 'no_person');
});

test('the person stepping back into frame is tracked again', () => {
    const profile = repProfile({ reps: 3, restMs: 1200 });
    const analyzer = analyzerFor('barbell-squat');
    const { last } = drive(analyzer, (t) => ((t > 2500 && t < 4500) ? null : squatFigure(profile.p(t))), { durationMs: profile.duration + 2000 });
    assert.ok(last.reps >= 1, 'later reps should still be counted');
});

test('reset clears a session', () => {
    const { analyzer } = squatSession({ reps: 3 });
    analyzer.reset();
    const s = analyzer.summary(0);
    assert.equal(s.total_reps, 0);
    assert.deepEqual(s.rep_log, []);
});

test('the summary is small, serialisable and consistent', () => {
    const { analyzer } = squatSession({ reps: 5, lean: 82 });
    const s = analyzer.summary(33.4);
    assert.equal(s.good_reps + s.bad_reps, s.total_reps);
    assert.equal(s.duration_seconds, 33);
    assert.equal(s.rep_log.length, 5);
    assert.ok(s.form_score_min <= s.form_score_avg && s.form_score_avg <= 100 && s.form_score_min >= 0);
    assert.ok(JSON.stringify(s).length < 2000);
    assert.equal(s.hold_seconds, null);
    assert.doesNotMatch(JSON.stringify(s), /landmark|image|frame/i);
});

test('every shipped rule survives garbage input: random landmarks, dropouts and teleporting joints', () => {
    for (const r of loadAllRules()) {
        const analyzer = new PostureAnalyzer(r.config, { aspect: ASPECT });
        const rand = rng(99);
        for (let i = 0; i < 240; i += 1) {
            const lm = i % 17 === 0 ? null : Array.from({ length: 33 }, () => ({ x: rand() * 1.4 - 0.2, y: rand() * 1.4 - 0.2, z: rand() - 0.5, visibility: rand() }));
            const res = analyzer.update(lm, i * 33);
            assert.ok(Number.isFinite(res.score), `${r.file}: score`);
            assert.ok(Number.isFinite(res.progress), `${r.file}: progress`);
            for (const v of Object.values(res.measures)) assert.ok(v === null || Number.isFinite(v), `${r.file}: measure ${v}`);
        }
        analyzer.flush(8000);
        const s = analyzer.summary(8);
        assert.ok(s.form_score_avg >= 0 && s.form_score_avg <= 100, r.file);
    }
});

test('the rep counter config of every movement rule counts clean reps at a normal pace', () => {
    for (const r of loadAllRules()) {
        const rule = compileRule(r.config);
        if (rule.rep.kind !== 'movement') continue;
        const profile = repProfile({ reps: 4 });
        const counter = new RepCounter(rule.rep);
        let reps = 0;
        for (let t = 0; t <= profile.duration; t += 33) {
            const v = rule.rep.start + (rule.rep.peak - rule.rep.start) * profile.p(t);
            reps += counter.update(v, t).filter((e) => e.type === 'rep_end' && !e.partial).length;
        }
        assert.equal(reps, 4, `${r.file} counted ${reps} reps`);
    }
});
