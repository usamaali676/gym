/**
 * Visual effects: count-up numbers, animated progress rings/bars, particles, confetti.
 * Every effect respects prefers-reduced-motion.
 */

export const prefersReducedMotion = () =>
    window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);

/** Animate [data-count-to] elements from 0 to the target value. */
export function initCountUp(root = document) {
    root.querySelectorAll('[data-count-to]').forEach((el) => {
        const target = parseFloat(el.dataset.countTo);
        const decimals = parseInt(el.dataset.decimals || '0', 10);
        const suffix = el.dataset.suffix || '';
        const format = (v) => v.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + suffix;

        if (Number.isNaN(target)) return;
        if (prefersReducedMotion() || target === 0) {
            el.textContent = format(target);
            return;
        }

        const duration = 900;
        const start = performance.now();
        const tick = (now) => {
            const t = Math.min(1, (now - start) / duration);
            el.textContent = format(target * easeOutCubic(t));
            if (t < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    });
}

/** Animate SVG gauges: <div class="gauge" data-ring="0.62"> with a .gauge-value circle (r given in data-r). */
export function initRings(root = document) {
    root.querySelectorAll('[data-ring]').forEach((ring) => {
        const circle = ring.querySelector('.gauge-value');
        if (!circle) return;
        const r = parseFloat(circle.getAttribute('r'));
        const circumference = 2 * Math.PI * r;
        const value = Math.max(0, Math.min(1, parseFloat(ring.dataset.ring) || 0));

        circle.style.strokeDasharray = `${circumference}`;
        circle.style.strokeDashoffset = `${circumference}`;
        const apply = () => { circle.style.strokeDashoffset = `${circumference * (1 - value)}`; };

        if (prefersReducedMotion()) {
            circle.style.transition = 'none';
            apply();
        } else {
            requestAnimationFrame(() => requestAnimationFrame(apply));
        }
    });
}

/** Animate .bar > i widths from data-pct. */
export function initBars(root = document) {
    root.querySelectorAll('.bar > i[data-pct]').forEach((bar) => {
        const pct = Math.max(0, Math.min(100, parseFloat(bar.dataset.pct) || 0));
        if (prefersReducedMotion()) {
            bar.style.transition = 'none';
            bar.style.width = `${pct}%`;
        } else {
            requestAnimationFrame(() => requestAnimationFrame(() => { bar.style.width = `${pct}%`; }));
        }
    });
}

/** Faint floating green particles on a fixed canvas (landing, auth, dashboard). */
export function initParticles(canvas) {
    if (!canvas || prefersReducedMotion()) return () => {};
    const ctx = canvas.getContext('2d');
    let width = 0;
    let height = 0;
    let raf = 0;
    const count = window.innerWidth < 640 ? 22 : 42;
    const dots = Array.from({ length: count }, () => ({
        x: Math.random(), y: Math.random(), r: 0.6 + Math.random() * 1.8,
        vx: (Math.random() - 0.5) * 0.00012, vy: -0.00005 - Math.random() * 0.00018,
        a: 0.25 + Math.random() * 0.55,
    }));

    const resize = () => {
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        width = canvas.clientWidth;
        height = canvas.clientHeight;
        canvas.width = width * dpr;
        canvas.height = height * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    };

    const frame = () => {
        ctx.clearRect(0, 0, width, height);
        for (const d of dots) {
            d.x += d.vx * 16; d.y += d.vy * 16;
            if (d.y < -0.02) { d.y = 1.02; d.x = Math.random(); }
            if (d.x < -0.02) d.x = 1.02; if (d.x > 1.02) d.x = -0.02;
            ctx.beginPath();
            ctx.fillStyle = `rgba(57,255,20,${d.a})`;
            ctx.shadowColor = 'rgba(57,255,20,0.9)';
            ctx.shadowBlur = 8;
            ctx.arc(d.x * width, d.y * height, d.r, 0, Math.PI * 2);
            ctx.fill();
        }
        raf = requestAnimationFrame(frame);
    };

    const onVisibility = () => {
        cancelAnimationFrame(raf);
        if (!document.hidden) raf = requestAnimationFrame(frame);
    };

    resize();
    window.addEventListener('resize', resize);
    document.addEventListener('visibilitychange', onVisibility);
    raf = requestAnimationFrame(frame);

    return () => {
        cancelAnimationFrame(raf);
        window.removeEventListener('resize', resize);
        document.removeEventListener('visibilitychange', onVisibility);
    };
}

/** Neon confetti burst on a canvas (workout completion). */
export function confetti(canvas, { duration = 2600, particles = 150 } = {}) {
    if (!canvas || prefersReducedMotion()) return Promise.resolve();
    const ctx = canvas.getContext('2d');
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = canvas.clientWidth * dpr;
    canvas.height = canvas.clientHeight * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    const w = canvas.clientWidth;
    const h = canvas.clientHeight;
    const colors = ['#39ff14', '#00e676', '#b6ffc9', '#e8f5e9', '#1fa80d'];
    const bits = Array.from({ length: particles }, () => {
        const angle = -Math.PI / 2 + (Math.random() - 0.5) * 1.5;
        const speed = 5 + Math.random() * 11;
        return {
            x: w / 2, y: h * 0.62, vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed,
            s: 4 + Math.random() * 6, rot: Math.random() * 6, vr: (Math.random() - 0.5) * 0.4,
            c: colors[(Math.random() * colors.length) | 0],
        };
    });

    return new Promise((resolve) => {
        const start = performance.now();
        const frame = (now) => {
            const t = now - start;
            ctx.clearRect(0, 0, w, h);
            for (const b of bits) {
                b.vy += 0.28; b.vx *= 0.992; b.x += b.vx; b.y += b.vy; b.rot += b.vr;
                ctx.save();
                ctx.globalAlpha = Math.max(0, 1 - t / duration);
                ctx.translate(b.x, b.y); ctx.rotate(b.rot);
                ctx.fillStyle = b.c; ctx.shadowColor = b.c; ctx.shadowBlur = 10;
                ctx.fillRect(-b.s / 2, -b.s / 4, b.s, b.s / 2);
                ctx.restore();
            }
            if (t < duration) requestAnimationFrame(frame);
            else { ctx.clearRect(0, 0, w, h); resolve(); }
        };
        requestAnimationFrame(frame);
    });
}

export function initEffects(root = document) {
    initCountUp(root);
    initRings(root);
    initBars(root);
    const particles = root.querySelector('canvas.particles');
    if (particles) initParticles(particles);
}
