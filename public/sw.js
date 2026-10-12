/*
 * IronPulse service worker.
 *
 * What it does
 *  - Caches the app shell's static files at runtime (hashed build assets, fonts, icons and the self-hosted
 *    pose model) so the app and the Posture Lab start fast and keep working offline once they have been used.
 *  - Keeps a copy of a few pages (dashboard, plan, library, workout) so a workout can be resumed with no signal.
 *    Sets logged offline are queued by the page itself and sent when the connection returns.
 *  - Shows /offline for any other page that cannot be reached.
 *
 * What it never does
 *  - It never touches non-GET requests, /api, login/logout, photos, admin pages or shared report links, and it
 *    never stores a response that is not a plain 200 from this origin.
 *  - Cached pages belong to the signed-in user, so the page script asks it to drop them on logout and whenever
 *    a signed-out page loads.
 */
const VERSION = 'ip-v1';
const STATIC_CACHE = `${VERSION}-static`;
const PAGE_CACHE = `${VERSION}-pages`;

const SCOPE = new URL(self.registration.scope);
const BASE = SCOPE.pathname.endsWith('/') ? SCOPE.pathname : `${SCOPE.pathname}/`;
const OFFLINE_URL = new URL('offline', SCOPE).href;

const STATIC_PREFIXES = ['build/assets/', 'vendor/mediapipe/', 'icons/'];
const PAGE_PATTERN = /^(dashboard|plan(\/\d+)?|library(\/[\w-]+)?|workout(\/\d+)?)$/;
const NEVER_PREFIXES = ['api/', 'login', 'logout', 'register', 'forgot-password', 'reset-password', 'admin', 'progress/photos', 'r/', 'reports', 'guest', 'account'];
const NAVIGATION_TIMEOUT_MS = 8000;

/** Path relative to the app's base, without query string. */
function relPath(url) {
    return url.pathname.startsWith(BASE) ? url.pathname.slice(BASE.length) : url.pathname.slice(1);
}

function classify(request) {
    if (request.method !== 'GET') return 'ignore';
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return 'ignore';
    const path = relPath(url);
    if (NEVER_PREFIXES.some((p) => path === p.replace(/\/$/, '') || path.startsWith(p))) return 'ignore';
    if (STATIC_PREFIXES.some((p) => path.startsWith(p))) return 'static';
    if (path === 'offline') return 'ignore';
    if (request.mode === 'navigate') return PAGE_PATTERN.test(path.replace(/\/$/, '')) ? 'page' : 'navigation';
    return 'ignore';
}

function storable(response) {
    return response && response.status === 200 && response.type === 'basic' && !response.redirected
        && !/no-store/i.test(response.headers.get('Cache-Control') || '');
}

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(STATIC_CACHE);
        await cache.add(new Request(OFFLINE_URL, { cache: 'reload' })).catch(() => {});
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names.filter((n) => !n.startsWith(VERSION)).map((n) => caches.delete(n)));
        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'clear-pages') event.waitUntil(caches.delete(PAGE_CACHE));
    if (event.data?.type === 'skip-waiting') self.skipWaiting();
});

async function staticFirst(request) {
    const cache = await caches.open(STATIC_CACHE);
    const hit = await cache.match(request);
    if (hit) return hit;
    const response = await fetch(request);
    if (storable(response)) cache.put(request, response.clone());
    return response;
}

function withTimeout(promise, ms, controller) {
    const timer = setTimeout(() => controller.abort(), ms);
    return promise.finally(() => clearTimeout(timer));
}

async function pageNetworkFirst(request) {
    const cache = await caches.open(PAGE_CACHE);
    const cached = await cache.match(request);
    try {
        // With a saved copy to fall back on, do not let a stalled connection hold the page hostage.
        const controller = new AbortController();
        const response = await (cached ? withTimeout(fetch(request, { signal: controller.signal }), NAVIGATION_TIMEOUT_MS, controller) : fetch(request));
        if (storable(response)) cache.put(request, response.clone());
        // A redirect (for example to the login page after the session ended) means the saved copy is stale.
        if (response.redirected || response.status === 401 || response.status === 419) cache.delete(request);
        return response;
    } catch {
        return cached || offlineResponse();
    }
}

async function navigationNetworkOnly(request) {
    try {
        return await fetch(request);
    } catch {
        return offlineResponse();
    }
}

async function offlineResponse() {
    const cache = await caches.open(STATIC_CACHE);
    return (await cache.match(OFFLINE_URL)) || new Response('You are offline.', { status: 503, headers: { 'Content-Type': 'text/plain' } });
}

self.addEventListener('fetch', (event) => {
    const kind = classify(event.request);
    if (kind === 'ignore') return;
    if (kind === 'static') event.respondWith(staticFirst(event.request).catch(() => Response.error()));
    else if (kind === 'page') event.respondWith(pageNetworkFirst(event.request));
    else event.respondWith(navigationNetworkOnly(event.request));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || `${BASE}dashboard`, self.location.origin);
    if (target.origin !== self.location.origin) return;
    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const open = windows.find((w) => new URL(w.url).origin === target.origin);
        if (open) {
            await open.focus();
            if ('navigate' in open) await open.navigate(target.href).catch(() => {});
        } else {
            await self.clients.openWindow(target.href);
        }
    })());
});
