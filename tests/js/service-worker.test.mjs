import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8');
const ORIGIN = 'https://gym.test';

/** Load sw.js into a sandbox with just enough of the worker API to drive its fetch handler. */
function loadWorker({ scope = `${ORIGIN}/`, fetchImpl } = {}) {
    const listeners = {};
    const stores = new Map();
    const cacheFor = (name) => {
        if (!stores.has(name)) stores.set(name, new Map());
        const store = stores.get(name);
        return {
            match: async (req) => store.get(typeof req === 'string' ? req : req.url) ?? undefined,
            put: async (req, res) => { store.set(typeof req === 'string' ? req : req.url, res); },
            add: async (req) => { store.set(typeof req === 'string' ? req : req.url, new Response('offline page', { status: 200 })); },
            delete: async (req) => store.delete(typeof req === 'string' ? req : req.url),
        };
    };
    const sandbox = {
        URL, Request, Response, AbortController, setTimeout, clearTimeout, console,
        fetch: fetchImpl ?? (async () => { throw new TypeError('offline'); }),
        caches: {
            open: async (name) => cacheFor(name),
            keys: async () => [...stores.keys()],
            delete: async (name) => stores.delete(name),
        },
        self: {
            registration: { scope },
            location: new URL(`${scope}sw.js`),
            addEventListener: (type, fn) => { listeners[type] = fn; },
            skipWaiting: async () => {},
            clients: { claim: async () => {}, matchAll: async () => [], openWindow: async () => {} },
        },
    };
    vm.createContext(sandbox);
    vm.runInContext(source, sandbox);
    return { listeners, stores, sandbox };
}

/** Fire a fetch event; resolves to the response the worker supplied, or null when it left the request alone. */
async function dispatch(worker, url, { method = 'GET', mode = 'cors' } = {}) {
    const request = new Request(url, { method });
    Object.defineProperty(request, 'mode', { value: mode });
    let handled = null;
    worker.listeners.fetch({ request, respondWith: (p) => { handled = p; } });
    return handled ? await handled : null;
}

const ok = (body = 'ok') => new Response(body, { status: 200 });
const asBasic = (res) => Object.defineProperty(res, 'type', { value: 'basic' });

test('non-GET requests, API calls and sensitive paths are never intercepted', async () => {
    const worker = loadWorker({ fetchImpl: async () => asBasic(ok()) });
    for (const [url, opts] of [
        [`${ORIGIN}/api/water`, {}],
        [`${ORIGIN}/api/progress/exercises/bench`, {}],
        [`${ORIGIN}/plan/1/complete`, { method: 'POST', mode: 'navigate' }],
        [`${ORIGIN}/logout`, { method: 'POST', mode: 'navigate' }],
        [`${ORIGIN}/login`, { mode: 'navigate' }],
        [`${ORIGIN}/progress/photos/3`, {}],
        [`${ORIGIN}/admin/demos`, { mode: 'navigate' }],
        [`${ORIGIN}/r/sharetoken`, { mode: 'navigate' }],
        [`${ORIGIN}/reports/4/pdf`, { mode: 'navigate' }],
        ['https://elsewhere.example/build/assets/app.js', {}],
    ]) {
        assert.equal(await dispatch(worker, url, opts), null, `${opts.method ?? 'GET'} ${url} should be ignored`);
    }
    assert.equal(worker.stores.size, 0, 'nothing may be cached for ignored requests');
});

test('hashed build assets and the self-hosted pose model are cached and then served from cache', async () => {
    let calls = 0;
    const worker = loadWorker({ fetchImpl: async () => { calls++; return asBasic(ok('asset')); } });

    const first = await dispatch(worker, `${ORIGIN}/build/assets/app-abc.js`);
    assert.equal(await first.text(), 'asset');
    const second = await dispatch(worker, `${ORIGIN}/build/assets/app-abc.js`);
    assert.equal(await second.text(), 'asset');
    assert.equal(calls, 1, 'the second request is a cache hit');

    await dispatch(worker, `${ORIGIN}/vendor/mediapipe/models/pose_landmarker_full.task`);
    assert.equal(calls, 2);
});

test('partial, error and no-store responses are not stored', async () => {
    for (const response of [new Response('x', { status: 206 }), new Response('x', { status: 500 }), new Response('x', { status: 200, headers: { 'Cache-Control': 'no-store' } })]) {
        const worker = loadWorker({ fetchImpl: async () => asBasic(response) });
        await dispatch(worker, `${ORIGIN}/build/assets/x-1.js`);
        const cached = await (await worker.sandbox.caches.open('ip-v1-static')).match(`${ORIGIN}/build/assets/x-1.js`);
        assert.equal(cached, undefined);
    }
});

test('workout and plan pages are cached when the network works and served when it does not', async () => {
    let online = true;
    const worker = loadWorker({ fetchImpl: async () => { if (!online) throw new TypeError('offline'); return asBasic(ok('fresh workout')); } });

    await dispatch(worker, `${ORIGIN}/workout/12`, { mode: 'navigate' });
    online = false;
    const offline = await dispatch(worker, `${ORIGIN}/workout/12`, { mode: 'navigate' });
    assert.equal(await offline.text(), 'fresh workout');
});

test('pages outside the allow list are not cached and fall back to the offline page', async () => {
    let online = true;
    const worker = loadWorker({ fetchImpl: async () => { if (!online) throw new TypeError('offline'); return asBasic(ok('profile page')); } });
    await worker.listeners.install({ waitUntil: (p) => p }) ;
    await new Promise((r) => setTimeout(r, 0));

    await dispatch(worker, `${ORIGIN}/profile`, { mode: 'navigate' });
    online = false;
    const res = await dispatch(worker, `${ORIGIN}/profile`, { mode: 'navigate' });
    assert.equal(await res.text(), 'offline page');
    const pages = await worker.sandbox.caches.open('ip-v1-pages');
    assert.equal(await pages.match(`${ORIGIN}/profile`), undefined);
});

test('a redirect (session expired) is not cached and evicts the stale copy', async () => {
    const worker = loadWorker({ fetchImpl: async () => asBasic(ok('fresh')) });
    await dispatch(worker, `${ORIGIN}/dashboard`, { mode: 'navigate' });

    const redirected = asBasic(ok('login page'));
    Object.defineProperty(redirected, 'redirected', { value: true });
    worker.sandbox.fetch = async () => redirected;
    await dispatch(worker, `${ORIGIN}/dashboard`, { mode: 'navigate' });

    const pages = await worker.sandbox.caches.open('ip-v1-pages');
    assert.equal(await pages.match(`${ORIGIN}/dashboard`), undefined);
});

test('the clear-pages message drops every cached page', async () => {
    const worker = loadWorker({ fetchImpl: async () => asBasic(ok('page')) });
    await dispatch(worker, `${ORIGIN}/dashboard`, { mode: 'navigate' });
    await dispatch(worker, `${ORIGIN}/build/assets/app-1.js`);
    assert.ok(worker.stores.has('ip-v1-pages'));

    let pending;
    worker.listeners.message({ data: { type: 'clear-pages' }, waitUntil: (p) => { pending = p; } });
    await pending;

    assert.equal(worker.stores.has('ip-v1-pages'), false);
    assert.equal(worker.stores.has('ip-v1-static'), true, 'static assets are unaffected');
});

test('old cache versions are removed on activate', async () => {
    const worker = loadWorker();
    worker.stores.set('ip-v0-pages', new Map());
    worker.stores.set('ip-v1-static', new Map());
    let pending;
    worker.listeners.activate({ waitUntil: (p) => { pending = p; } });
    await pending;
    assert.deepEqual([...worker.stores.keys()], ['ip-v1-static']);
});

test('works when the app is hosted under a sub path', async () => {
    const worker = loadWorker({ scope: `${ORIGIN}/gym/`, fetchImpl: async () => asBasic(ok('asset')) });
    assert.notEqual(await dispatch(worker, `${ORIGIN}/gym/build/assets/app-1.js`), null);
    assert.equal(await dispatch(worker, `${ORIGIN}/gym/api/water`), null);
});
