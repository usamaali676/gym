#!/usr/bin/env node
/**
 * Renders the website's 3D exercise demonstrations to short looping MP4 clips (plus a poster image each) that the
 * mobile apps play. The frames come from the website's own viewer (resources/js/modules/exercise-3d.js) driven one
 * frame at a time in headless Chromium, so a clip is the same figure, animation and camera as the web page. These are
 * RENDERED CLIPS of a procedural animation, not captured motion of a person and not a live 3D model on the phone.
 *
 *   php artisan exercises:demo-manifest --out=storage/app/demo-manifest.json      (which exercises, with their muscle regions)
 *   node scripts/render-exercise-demos.mjs --manifest=storage/app/demo-manifest.json --out=storage/app/demo-render
 *   php artisan exercises:import-demos storage/app/demo-render                    (registers the clips for the apps)
 *
 * Needs: Node, `npm i --no-save playwright` (or playwright-core with PLAYWRIGHT_BROWSERS_PATH / CHROMIUM_PATH), a
 * Chromium, and ffmpeg with libx264. Options: --views=angle,side,front  --size=640  --fps=30  --only=slug,slug
 * Without --manifest every animation in the registry is rendered under its own key, with no muscle highlighting.
 * An exercise whose demo_key has no dedicated animation is skipped (the viewer would draw a generic standing figure,
 * which must not be presented as a demonstration of that exercise).
 */
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, statSync, writeFileSync, copyFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = Object.fromEntries(process.argv.slice(2).filter((a) => a.startsWith('--')).map((a) => { const [k, v = 'true'] = a.slice(2).split('='); return [k, v]; }));
const OUT = resolve(args.out ?? join(root, 'storage/app/demo-render'));
const SIZE = Number(args.size ?? 640);
const FPS = Number(args.fps ?? 30);
const VIEWS = (args.views ?? 'angle').split(',').map((v) => v.trim()).filter(Boolean);
const ONLY = args.only ? new Set(args.only.split(',')) : null;

if (!VIEWS.every((v) => ['angle', 'side', 'front'].includes(v))) throw new Error('--views must be a list of angle, side, front');
if (!Number.isInteger(SIZE) || SIZE < 160 || SIZE > 1280 || SIZE % 2) throw new Error('--size must be an even number between 160 and 1280');

function loadPlaywright() {
    const req = createRequire(join(root, 'package.json'));
    const tries = ['playwright', 'playwright-core', ...(process.env.NODE_TOOLS ? [join(process.env.NODE_TOOLS, 'node_modules/playwright')] : []), '/opt/node-tools/node_modules/playwright'];
    for (const name of tries) {
        try { return req(name); } catch { /* try the next */ }
    }
    throw new Error('Playwright is not installed. Run: npm i --no-save playwright');
}

const ffmpeg = spawnSync('ffmpeg', ['-hide_banner', '-encoders'], { encoding: 'utf8' });
if (ffmpeg.error || !/libx264/.test(ffmpeg.stdout)) throw new Error('ffmpeg with libx264 is required');

// 1. Bundle the page script (the viewer and the animation registry) for the browser.
const work = mkdtempSync(join(tmpdir(), 'demo-render-'));
const { build } = await import(pathToFileURL(join(root, 'node_modules/esbuild/lib/main.js')).href);
await build({ entryPoints: [join(root, 'scripts/demo-render/entry.js')], bundle: true, format: 'iife', outfile: join(work, 'bundle.js'), logLevel: 'error', minify: true });
writeFileSync(join(work, 'index.html'), '<!doctype html><meta charset="utf-8"><body style="margin:0;background:#07090A"><script src="bundle.js"></script>');

// 2. Which exercises.
const { chromium } = loadPlaywright();
const browser = await chromium.launch({ args: ['--use-angle=swiftshader', '--use-gl=angle', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist', '--no-sandbox'] });
const page = await browser.newPage({ viewport: { width: SIZE, height: SIZE }, deviceScaleFactor: 1 });
page.on('pageerror', (e) => { throw e; });
await page.goto(pathToFileURL(join(work, 'index.html')).href);

let items;
if (args.manifest) {
    items = JSON.parse(readFileSync(resolve(args.manifest), 'utf8'));
} else {
    items = (await page.evaluate(() => window.demo.keys())).map((k) => ({ slug: k, demo_key: k, regions: null }));
}
items = items.filter((i) => !ONLY || ONLY.has(i.slug));

mkdirSync(OUT, { recursive: true });
const index = { generated_by: 'scripts/render-exercise-demos.mjs', size: SIZE, fps: FPS, clips: [], skipped: [] };

// 3. Render.
for (const item of items) {
    const key = item.demo_key || item.slug;
    if (!(await page.evaluate((k) => window.demo.has(k), key))) {
        index.skipped.push({ slug: item.slug, reason: `no dedicated animation for "${key}"` });
        continue;
    }
    for (const view of VIEWS) {
        const info = await page.evaluate((o) => window.demo.open(o), { key, regions: item.regions, view, pixels: SIZE });
        const frames = Math.max(2, Math.round(info.durationSeconds * FPS));
        const dir = mkdtempSync(join(work, 'f-'));
        for (let i = 0; i < frames; i++) {
            const url = await page.evaluate((t) => window.demo.frame(t), i / frames); // one full loop, the last frame leads back into the first
            writeFileSync(join(dir, `${String(i).padStart(4, '0')}.jpg`), Buffer.from(url.split(',')[1], 'base64'));
        }
        const base = `${item.slug}-${view}`;
        const mp4 = join(OUT, `${base}.mp4`);
        const enc = spawnSync('ffmpeg', ['-y', '-loglevel', 'error', '-framerate', String(FPS), '-i', join(dir, '%04d.jpg'), '-c:v', 'libx264', '-preset', 'slow', '-crf', '24', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-an', mp4], { encoding: 'utf8' });
        if (enc.status !== 0) throw new Error(`ffmpeg failed for ${base}: ${enc.stderr}`);
        const poster = join(OUT, `${base}.jpg`);
        copyFileSync(join(dir, `${String(Math.min(frames - 1, Math.round(info.thumbT * frames))).padStart(4, '0')}.jpg`), poster);
        rmSync(dir, { recursive: true, force: true });

        index.clips.push({
            slug: item.slug, demo_key: key, view, title: info.name,
            video: `${base}.mp4`, poster: `${base}.jpg`,
            width: SIZE, height: SIZE, duration_ms: Math.round((frames / FPS) * 1000), fps: FPS,
            bytes: statSync(mp4).size, sha256: createHash('sha256').update(readFileSync(mp4)).digest('hex'),
            poster_bytes: statSync(poster).size, poster_sha256: createHash('sha256').update(readFileSync(poster)).digest('hex'),
        });
        process.stdout.write(`${base}: ${frames} frames, ${(statSync(mp4).size / 1024).toFixed(0)} KB\n`);
    }
}

await browser.close();
rmSync(work, { recursive: true, force: true });
writeFileSync(join(OUT, 'index.json'), JSON.stringify(index, null, 2));
process.stdout.write(`${index.clips.length} clips, ${index.skipped.length} skipped -> ${OUT}\n`);
