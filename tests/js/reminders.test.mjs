import assert from 'node:assert/strict';
import test from 'node:test';
import { dueReminders, toMinutes, zonedNow } from '../../resources/js/modules/reminders.js';

const config = { tz: 'Europe/London', workout: '18:00', creatine: '09:30', sleep: '22:15', water: null };
const withWater = { ...config, water: 90 };
const at = (date, hhmm) => ({ date, minutes: toMinutes(hhmm) });

test('toMinutes reads HH:MM', () => {
    assert.equal(toMinutes('00:00'), 0);
    assert.equal(toMinutes('18:05'), 1085);
});

test('zonedNow reports the wall clock in the given timezone, not the machine timezone', () => {
    const instant = new Date('2026-10-14T23:30:00Z');
    assert.deepEqual(zonedNow('UTC', instant), { date: '2026-10-14', minutes: 23 * 60 + 30 });
    assert.deepEqual(zonedNow('Asia/Karachi', instant), { date: '2026-10-15', minutes: 4 * 60 + 30 });
    assert.deepEqual(zonedNow('America/New_York', instant), { date: '2026-10-14', minutes: 19 * 60 + 30 });
});

test('a daily reminder is due from its time for ten minutes, once per day', () => {
    assert.deepEqual(dueReminders(config, at('2026-10-14', '17:59'), {}), []);
    assert.deepEqual(dueReminders(config, at('2026-10-14', '18:00'), {}), ['workout']);
    assert.deepEqual(dueReminders(config, at('2026-10-14', '18:09'), {}), ['workout']);
    assert.deepEqual(dueReminders(config, at('2026-10-14', '18:10'), {}), []);
    assert.deepEqual(dueReminders(config, at('2026-10-14', '18:02'), { workout: '2026-10-14' }), []);
    assert.deepEqual(dueReminders(config, at('2026-10-15', '18:02'), { workout: '2026-10-14' }), ['workout']);
});

test('reminders that are not configured never fire', () => {
    assert.deepEqual(dueReminders({ tz: 'UTC', workout: null, creatine: null, sleep: null, water: null }, at('2026-10-14', '18:00'), {}), []);
});

test('water reminders respect the interval and stay inside daytime hours', () => {
    const t0 = Date.UTC(2026, 9, 14, 12, 0);
    assert.deepEqual(dueReminders(withWater, at('2026-10-14', '12:00'), {}, t0), ['water']);
    assert.deepEqual(dueReminders(withWater, at('2026-10-14', '12:00'), { water: String(t0 - 60 * 60_000) }, t0), []);
    assert.deepEqual(dueReminders(withWater, at('2026-10-14', '12:00'), { water: String(t0 - 90 * 60_000) }, t0), ['water']);
    assert.deepEqual(dueReminders(withWater, at('2026-10-14', '07:59'), {}, t0), []);
    assert.deepEqual(dueReminders(withWater, at('2026-10-14', '21:01'), {}, t0), []);
});
