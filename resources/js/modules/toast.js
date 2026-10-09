/** Tiny toast stack for "you earned something" moments. Announced politely to screen readers. */

function host() {
    let el = document.getElementById('toasts');
    if (!el) {
        el = document.createElement('div');
        el.id = 'toasts';
        el.setAttribute('aria-live', 'polite');
        el.setAttribute('role', 'status');
        el.className = 'pointer-events-none fixed inset-x-0 bottom-24 z-[90] flex flex-col items-center gap-2 px-4 lg:bottom-6';
        document.body.append(el);
    }
    return el;
}

const reduce = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

export function toast(text, { ms = 5200, tone = 'neon' } = {}) {
    const item = document.createElement('div');
    item.className = `pointer-events-auto flex max-w-md items-center gap-2 rounded-2xl border px-4 py-3 text-sm font-semibold shadow-xl backdrop-blur ${tone === 'neon' ? 'border-neon/50 bg-[#0b1a0f]/95 text-neon' : 'border-line-strong bg-black/85 text-ink'}`;
    item.textContent = text;
    if (!reduce()) item.animate([{ transform: 'translateY(12px)', opacity: 0 }, { transform: 'none', opacity: 1 }], { duration: 220, easing: 'ease-out' });
    host().append(item);
    setTimeout(() => item.remove(), ms);
}

/** @param {Array<{name:string, xp_reward:number}>} badges */
export function toastBadges(badges = []) {
    badges.forEach((b, i) => setTimeout(() => toast(`Badge earned: ${b.name} (+${b.xp_reward} XP)`), i * 600));
}
