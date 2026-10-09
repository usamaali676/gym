/**
 * Service worker registration, the install prompt and offline awareness.
 * Everything here is progressive: with no service worker support the app simply behaves like a normal website.
 */
let deferredPrompt = null;

const isStandalone = () => window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true;
const isIos = () => /iphone|ipad|ipod/i.test(window.navigator.userAgent) && !window.MSStream;

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt = event;
    window.dispatchEvent(new Event('ip:install-ready'));
});

/** Alpine component for the "Install the app" control. */
export function installApp() {
    return {
        canPrompt: false,
        ios: false,
        standalone: false,
        get visible() { return !this.standalone && (this.canPrompt || this.ios); },
        init() {
            this.standalone = isStandalone();
            this.ios = isIos();
            this.canPrompt = deferredPrompt !== null;
            window.addEventListener('ip:install-ready', () => { this.canPrompt = true; });
            window.addEventListener('appinstalled', () => { this.standalone = true; deferredPrompt = null; });
        },
        async install() {
            const prompt = deferredPrompt;
            if (!prompt) return;
            deferredPrompt = null;
            this.canPrompt = false;
            await prompt.prompt();
            await prompt.userChoice.catch(() => {});
        },
    };
}

function controller() {
    return navigator.serviceWorker?.controller ?? null;
}

/** Cached pages belong to whoever was signed in, so drop them on logout and whenever a signed-out page loads. */
function wireCacheClearing() {
    const signedIn = document.querySelector('meta[name="ip-auth"]')?.content === '1';
    if (!signedIn) controller()?.postMessage({ type: 'clear-pages' });
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (form instanceof HTMLFormElement && /\/logout\/?$/.test(new URL(form.action, location.href).pathname)) {
            controller()?.postMessage({ type: 'clear-pages' });
        }
    });
}

function wireConnectivity() {
    let wasOffline = !navigator.onLine;
    window.addEventListener('offline', () => {
        wasOffline = true;
        window.toast?.('You are offline. Saved pages still work; workout sets are kept and sent later.', { tone: 'plain', ms: 6000 });
    });
    window.addEventListener('online', () => {
        if (wasOffline) window.toast?.('Back online.', { ms: 2500 });
        wasOffline = false;
    });
}

export function initPwa() {
    wireConnectivity();
    if (!('serviceWorker' in navigator) || !import.meta.env.PROD || !window.isSecureContext) return;

    const url = document.querySelector('meta[name="ip-sw"]')?.content;
    if (!url) return;
    wireCacheClearing();
    window.addEventListener('load', () => {
        navigator.serviceWorker.register(url).catch(() => { /* not fatal: the site works without it */ });
    }, { once: true });
}
