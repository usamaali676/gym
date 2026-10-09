import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';
import { initEffects } from './modules/effects';
import bodyForm from './modules/body-form';

window.Alpine = Alpine;
Alpine.plugin(focus);
Alpine.data('bodyForm', bodyForm);

/** Per-user preference helpers: storage can throw (private mode, blocked cookies), so always guard. */
window.safeStorage = {
    get(key, fallback = null) {
        try { const v = window.localStorage.getItem(key); return v === null ? fallback : v; } catch { return fallback; }
    },
    set(key, value) {
        try { window.localStorage.setItem(key, value); } catch { /* ignore */ }
    },
};

/** Small fetch wrapper for the same-origin JSON API (session cookie + CSRF header). */
window.api = async function api(url, { method = 'GET', body, keepalive = false } = {}) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const response = await fetch(url, {
        method,
        keepalive,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    const data = response.status === 204 ? null : await response.json().catch(() => null);
    if (!response.ok) {
        const error = new Error(data?.message || `Request failed (${response.status})`);
        error.status = response.status;
        error.data = data;
        throw error;
    }
    return data;
};

Alpine.data('appShell', () => ({
    collapsed: window.safeStorage.get('ip.sidebar') === '1',
    moreOpen: false,
    toggleSidebar() {
        this.collapsed = !this.collapsed;
        window.safeStorage.set('ip.sidebar', this.collapsed ? '1' : '0');
    },
}));

Alpine.start();

// Heavy, page-specific bundles (Three.js, MediaPipe, Chart.js) are code-split and only loaded
// on the page that declares them with <body data-page="...">.
const pages = import.meta.glob('./pages/*.js');

document.addEventListener('DOMContentLoaded', async () => {
    initEffects(document);

    const page = document.body.dataset.page;
    const loader = page && pages[`./pages/${page}.js`];
    if (loader) {
        const module = await loader();
        if (typeof module.default === 'function') module.default();
    }
});
