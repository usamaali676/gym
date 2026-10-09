import test from 'node:test';
import assert from 'node:assert/strict';
import { SessionClock, Countdown, formatClock, formatCountdown } from '../../resources/js/modules/timer.js';

test('session clock counts elapsed time from timestamps, not ticks', () => {
    const clock = new SessionClock({ startedAt: 1_000 }, () => 0);
    assert.equal(clock.elapsedMs(1_000), 0);
    assert.equal(clock.elapsedMs(61_000), 60_000);
    // A tab that slept for an hour still reports the right value the instant it wakes.
    assert.equal(clock.elapsedMs(1_000 + 3_600_000), 3_600_000);
});

test('pausing stops the clock and resuming subtracts the paused time', () => {
    const clock = new SessionClock({ startedAt: 0 }, () => 0);
    clock.pause(10_000);
    assert.equal(clock.elapsedMs(50_000), 10_000);
    assert.equal(clock.isPaused, true);
    clock.resume(40_000);
    assert.equal(clock.pausedMs, 30_000);
    assert.equal(clock.elapsedMs(70_000), 70_000 - 30_000);
});

test('pause and resume are idempotent', () => {
    const clock = new SessionClock({ startedAt: 0 }, () => 0);
    clock.pause(5_000);
    clock.pause(9_000);
    clock.resume(15_000);
    clock.resume(20_000);
    assert.equal(clock.pausedMs, 10_000);
});

test('a clock survives a JSON round trip (page reload)', () => {
    const clock = new SessionClock({ startedAt: 100 }, () => 0);
    clock.pause(1_100);
    const restored = SessionClock.from(JSON.parse(JSON.stringify(clock)), () => 0);
    assert.equal(restored.elapsedMs(99_999), 1_000);
    restored.resume(2_100);
    assert.equal(restored.elapsedMs(3_100), 2_000);
});

test('countdown remaining time follows the wall clock', () => {
    const c = Countdown.fromSeconds(90, 0);
    assert.equal(c.remainingMs(0), 90_000);
    assert.equal(c.remainingMs(30_000), 60_000);
    assert.equal(c.isDone(89_999), false);
    assert.equal(c.isDone(90_000), true);
    assert.equal(c.remainingMs(500_000), 0);
});

test('a backgrounded tab still finishes the rest on time', () => {
    const c = Countdown.fromSeconds(60, 1_000);
    // No intermediate ticks at all: the browser froze the page for two minutes.
    assert.equal(c.isDone(121_000), true);
});

test('adding time extends both the end and the total', () => {
    const c = Countdown.fromSeconds(60, 0);
    c.extend(15_000, 20_000);
    assert.equal(c.remainingMs(20_000), 55_000);
    assert.equal(c.totalMs, 75_000);
    assert.ok(Math.abs(c.fractionLeft(20_000) - 55 / 75) < 1e-9);
});

test('extending a finished countdown restarts it from now', () => {
    const c = Countdown.fromSeconds(10, 0);
    c.extend(15_000, 50_000);
    assert.equal(c.remainingMs(50_000), 15_000);
});

test('freeze keeps the remaining time and thaw resumes from it', () => {
    const c = Countdown.fromSeconds(60, 0);
    c.freeze(20_000);
    assert.equal(c.remainingMs(500_000), 40_000);
    c.extend(15_000, 500_000);
    assert.equal(c.remainingMs(500_000), 55_000);
    c.thaw(600_000);
    assert.equal(c.remainingMs(600_000), 55_000);
    assert.equal(c.remainingMs(630_000), 25_000);
});

test('countdown JSON round trip keeps the state', () => {
    const c = Countdown.fromSeconds(45, 1_000);
    c.freeze(10_000);
    const r = Countdown.from(JSON.parse(JSON.stringify(c)));
    assert.equal(r.isFrozen, true);
    assert.equal(r.remainingMs(99_999), 36_000);
    assert.equal(Countdown.from(null), null);
});

test('formatting', () => {
    assert.equal(formatClock(0), '0:00');
    assert.equal(formatClock(75_000), '1:15');
    assert.equal(formatClock(3_725_000), '1:02:05');
    assert.equal(formatCountdown(1), '0:01');
    assert.equal(formatCountdown(59_001), '1:00');
    assert.equal(formatCountdown(0), '0:00');
});
