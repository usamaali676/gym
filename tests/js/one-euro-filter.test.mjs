import assert from 'node:assert/strict';
import test from 'node:test';
import { LandmarkSmoother, OneEuroFilter } from '../../resources/js/modules/one-euro-filter.js';
import { gaussian, rng } from './helpers/synthetic-pose.mjs';

const rms = (xs) => Math.sqrt(xs.reduce((s, v) => s + v * v, 0) / xs.length);

test('the filter cuts jitter on a still signal', () => {
    const rand = rng(3);
    const f = new OneEuroFilter();
    const raw = [];
    const out = [];
    for (let i = 0; i < 300; i += 1) {
        const noisy = 0.5 + gaussian(rand) * 0.004;
        raw.push(noisy - 0.5);
        out.push(f.filter(noisy, i * 33.3) - 0.5);
    }
    assert.ok(rms(out.slice(30)) < rms(raw.slice(30)) * 0.7, 'jitter at rest should drop by at least 30%');
});

test('the filter follows a fast move with little lag', () => {
    const f = new OneEuroFilter();
    let last = 0;
    let truth = 0;
    // 0.6 units per second for one second, like a limb moving through a rep
    for (let i = 0; i <= 30; i += 1) {
        truth = 0.6 * (i / 30);
        last = f.filter(truth, i * 33.3);
    }
    assert.ok(truth - last < 0.04, `lag ${truth - last} is too large`);
});

test('moving limbs stay close to the truth: the filter adds little error to a fast 0.5 Hz sweep', () => {
    // At this noise level the raw signal is already within ~4 mm of the truth, so the filter is judged on
    // how little it adds (lag), not on beating the raw signal.
    const rand = rng(5);
    const f = new OneEuroFilter();
    const rawErr = [];
    const filtErr = [];
    for (let i = 0; i < 400; i += 1) {
        const truth = 0.5 + 0.2 * Math.sin((2 * Math.PI * 0.5 * i) / 30);
        const noisy = truth + gaussian(rand) * 0.004;
        const smooth = f.filter(noisy, i * (1000 / 30));
        if (i > 30) {
            rawErr.push(noisy - truth);
            filtErr.push(smooth - truth);
        }
    }
    assert.ok(rms(filtErr) < 0.006, `filtered error ${rms(filtErr)}`);
    assert.ok(rms(filtErr) < rms(rawErr) * 1.5, `filtered ${rms(filtErr)} vs raw ${rms(rawErr)}`);
});

test('the smoother resets after a gap so a returning person does not slide in from the old position', () => {
    const s = new LandmarkSmoother({ count: 2 });
    const at = (x) => [{ x, y: 0.5, visibility: 1 }, { x, y: 0.5, visibility: 1 }];
    s.smooth(at(0.2), 0);
    s.smooth(at(0.2), 33);
    const back = s.smooth(at(0.8), 2000);
    assert.equal(back[0].x, 0.8);
});

test('visibility is smoothed but follows a real change', () => {
    const s = new LandmarkSmoother({ count: 1 });
    let v = 0;
    for (let i = 0; i < 10; i += 1) v = s.smooth([{ x: 0.5, y: 0.5, visibility: 1 }], i * 33)[0].visibility;
    assert.ok(v > 0.99);
    for (let i = 10; i < 20; i += 1) v = s.smooth([{ x: 0.5, y: 0.5, visibility: 0 }], i * 33)[0].visibility;
    assert.ok(v < 0.05);
});
