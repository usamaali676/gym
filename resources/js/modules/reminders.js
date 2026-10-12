/**
 * In-page reminders. These are browser notifications raised by a timer while IronPulse is open in a tab or as an
 * installed app. There is no push server, so nothing is delivered when the app is closed. That limit is stated
 * in the profile settings next to the switch.
 */
const TICK_MS = 30_000;
const WATER_FROM = 8 * 60;
const WATER_TO = 21 * 60;

const read = (key) => window.safeStorage.get(key);
const write = (key, value) => window.safeStorage.set(key, value);

/** Wall-clock parts in the user's own timezone (the app's idea of "today" and "now"). */
export function zonedNow(tz, date = new Date()) {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
        timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(date).map((p) => [p.type, p.value]));
    return { date: `${parts.year}-${parts.month}-${parts.day}`, minutes: Number(parts.hour) * 60 + Number(parts.minute) };
}

export const toMinutes = (hhmm) => {
    const [h, m] = String(hhmm).split(':').map(Number);
    return h * 60 + m;
};

/**
 * Which reminders are due right now. Pure so it can be unit tested.
 * `fired` maps a reminder key to what was last sent ("YYYY-MM-DD" for daily ones, epoch ms for water).
 */
export function dueReminders(config, now, fired, nowMs = Date.now()) {
    const due = [];
    const daily = (kind, at) => {
        if (!at) return;
        const start = toMinutes(at);
        // A 10 minute grace window lets a throttled background tab still catch up, but a reminder is never replayed later in the day.
        if (now.minutes >= start && now.minutes < start + 10 && fired[kind] !== now.date) due.push(kind);
    };
    daily('workout', config.workout);
    daily('creatine', config.creatine);
    daily('sleep', config.sleep);

    if (config.water && now.minutes >= WATER_FROM && now.minutes <= WATER_TO) {
        const last = Number(fired.water || 0);
        if (nowMs - last >= config.water * 60_000) due.push('water');
    }

    return due;
}

const COPY = {
    workout: { title: "Time to train", body: "Today's session is ready when you are.", url: 'workout' },
    creatine: { title: 'Creatine', body: 'Take your daily dose.', url: 'habits' },
    sleep: { title: 'Wind down', body: 'Bedtime is close. Sleep is when you grow.', url: 'habits' },
    water: { title: 'Drink some water', body: 'A glass now keeps you on target.', url: 'habits' },
};

async function show(kind, config) {
    const copy = COPY[kind];
    const options = { body: copy.body, tag: `ip-${kind}`, icon: new URL('icons/icon-192.png', document.baseURI).href, data: { url: config.urls[copy.url] } };
    try {
        const registration = await navigator.serviceWorker?.getRegistration();
        if (registration) return await registration.showNotification(copy.title, options);
    } catch { /* fall through to the page API */ }
    new Notification(copy.title, options);
}

async function stillNeeded(kind, config) {
    if (kind === 'workout' || kind === 'sleep') return true;
    try {
        const state = await window.api(config.urls.state);
        if (kind === 'creatine') return !state.creatine.taken;
        if (kind === 'water') return !state.water.reached;
    } catch { /* offline: remind anyway */ }
    return true;
}

export function startReminders() {
    const raw = document.getElementById('reminder-config');
    if (!raw || !('Notification' in window) || Notification.permission !== 'granted') return;
    const config = JSON.parse(raw.textContent);
    const key = (kind) => `ip.rem.${kind}`;

    const tick = async () => {
        const now = zonedNow(config.tz);
        const fired = Object.fromEntries(['workout', 'creatine', 'sleep', 'water'].map((k) => [k, read(key(k))]));
        for (const kind of dueReminders(config, now, fired)) {
            // Claim the slot first so two open tabs do not both notify.
            write(key(kind), kind === 'water' ? String(Date.now()) : now.date);
            if (await stillNeeded(kind, config)) await show(kind, config);
        }
    };

    tick();
    setInterval(tick, TICK_MS);
}

/** Alpine component for the permission button on the profile page. */
export function notifyPermission() {
    return {
        supported: 'Notification' in window,
        state: 'Notification' in window ? Notification.permission : 'denied',
        async request() {
            if (!this.supported) return;
            this.state = await Notification.requestPermission();
        },
        async test() {
            if (this.state !== 'granted') return;
            const options = { body: 'Reminders are working on this device.', icon: new URL('icons/icon-192.png', document.baseURI).href };
            const registration = await navigator.serviceWorker?.getRegistration();
            if (registration) await registration.showNotification('IronPulse', options);
            else new Notification('IronPulse', options);
        },
    };
}
