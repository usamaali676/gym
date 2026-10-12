#!/usr/bin/env node
/**
 * Draws the app's launcher icons and launch logo from the website's own icon (public/icons/icon.svg: the neon bolt on
 * the brand's near-black) and writes them into the Android and iOS projects:
 *
 *   Android  mipmap-*dpi/ic_launcher.png (rounded, for older phones), ic_launcher_foreground.png (adaptive icon layer),
 *            mipmap-anydpi-v26/ic_launcher.xml, values/ic_launcher_background.xml, drawable-nodpi/launch_logo.png
 *   iOS      AppIcon.appiconset/*.png (square and opaque: the system rounds them), LaunchImage.imageset/*.png
 *
 *   node scripts/export-mobile-icons.mjs
 *
 * Needs `npm i --no-save playwright` and a Chromium (the same setup as scripts/render-exercise-demos.mjs).
 */
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync, mkdirSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const res = join(root, 'mobile/android/app/src/main/res');
const ios = join(root, 'mobile/ios/Runner/Assets.xcassets');

const BG = '#07090A';
const NEON = '#39FF14';
const BOLT = 'M13.2 2 4.5 13.4c-.4.5 0 1.2.6 1.2H10l-1 7.1c-.1.8.9 1.2 1.4.6l8.7-11.4c.4-.5 0-1.2-.6-1.2H14l1-7.1c.1-.8-.9-1.2-1.4-.6Z';
// The bolt's own box inside its 24 x 24 drawing: x 4.4 to 19.7, y 2 to 22.3.
const BOLT_BOX = { x: 4.4, y: 2, w: 15.3, h: 20.3 };

/** The bolt alone, centred, filling `fill` of the canvas height (transparent background). */
const bolt = (fill) => {
    const s = (512 * fill) / BOLT_BOX.h;
    const tx = 256 - (BOLT_BOX.x + BOLT_BOX.w / 2) * s;
    const ty = 256 - (BOLT_BOX.y + BOLT_BOX.h / 2) * s;
    return `<g transform="translate(${tx} ${ty}) scale(${s})" fill="${NEON}"><path d="${BOLT}"/></g>`;
};
const svg = (inner) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="100%" height="100%">${inner}</svg>`;

// The website's icon, taken as it is, for the rounded Android icon.
const roundedIcon = readFileSync(join(root, 'public/icons/icon.svg'), 'utf8').replace('<svg ', '<svg width="100%" height="100%" ');
// Square, no rounded corners, for iOS (the system applies its own mask, and the App Store icon must not have transparency).
const squareIcon = svg(`<rect width="512" height="512" fill="${BG}"/><rect x="24" y="24" width="464" height="464" rx="92" fill="none" stroke="${NEON}" stroke-opacity=".25" stroke-width="6"/>${bolt(0.62)}`);
const adaptiveForeground = svg(bolt(0.5)); // inside the 66 % safe zone of an adaptive icon
const launchLogo = svg(bolt(0.9));

const req = createRequire(join(root, 'package.json'));
let playwright;
for (const name of ['playwright', 'playwright-core', '/opt/node-tools/node_modules/playwright']) {
    try { playwright = req(name); break; } catch { /* next */ }
}
if (!playwright) throw new Error('Playwright is not installed. Run: npm i --no-save playwright');
const browser = await playwright.chromium.launch({ args: ['--no-sandbox'] });
const page = await browser.newPage({ deviceScaleFactor: 1 });

async function png(markup, size, file, { transparent = true } = {}) {
    await page.setViewportSize({ width: size, height: size });
    await page.setContent(`<!doctype html><body style="margin:0;background:transparent;width:${size}px;height:${size}px;overflow:hidden">${markup}</body>`);
    const buffer = await page.screenshot({ omitBackground: transparent, clip: { x: 0, y: 0, width: size, height: size } });
    mkdirSync(dirname(file), { recursive: true });
    writeFileSync(file, buffer);
}

// ---- Android
const densities = { mdpi: 1, hdpi: 1.5, xhdpi: 2, xxhdpi: 3, xxxhdpi: 4 };
for (const [name, k] of Object.entries(densities)) {
    await png(roundedIcon, Math.round(48 * k), join(res, `mipmap-${name}`, 'ic_launcher.png'));
    await png(adaptiveForeground, Math.round(108 * k), join(res, `mipmap-${name}`, 'ic_launcher_foreground.png'));
}
await png(launchLogo, 192, join(res, 'drawable-nodpi', 'launch_logo.png'));
mkdirSync(join(res, 'mipmap-anydpi-v26'), { recursive: true });
writeFileSync(join(res, 'mipmap-anydpi-v26', 'ic_launcher.xml'), `<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@color/ic_launcher_background"/>
    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>
    <monochrome android:drawable="@mipmap/ic_launcher_foreground"/>
</adaptive-icon>
`);
writeFileSync(join(res, 'values', 'ic_launcher_background.xml'), `<?xml version="1.0" encoding="utf-8"?>
<resources>
    <color name="ic_launcher_background">${BG}</color>
</resources>
`);
writeFileSync(join(res, 'drawable', 'launch_background.xml'), `<?xml version="1.0" encoding="utf-8"?>
<!-- Shown while the app starts, before the first Flutter frame: the brand's dark background and the bolt, so the app never
     opens with a white flash. Generated with the icons by scripts/export-mobile-icons.mjs. -->
<layer-list xmlns:android="http://schemas.android.com/apk/res/android">
    <item android:drawable="@color/ic_launcher_background" />
    <item>
        <bitmap android:gravity="center" android:src="@drawable/launch_logo" />
    </item>
</layer-list>
`);

// ---- iOS: one PNG per entry of the icon set's Contents.json
const iconSet = join(ios, 'AppIcon.appiconset');
const contents = JSON.parse(readFileSync(join(iconSet, 'Contents.json'), 'utf8'));
for (const image of contents.images) {
    const [w] = image.size.split('x').map(Number);
    const scale = Number(image.scale.replace('x', ''));
    await png(squareIcon, Math.round(w * scale), join(iconSet, image.filename), { transparent: false });
}
const launchSet = join(ios, 'LaunchImage.imageset');
for (const [file, size] of [['LaunchImage.png', 96], ['LaunchImage@2x.png', 192], ['LaunchImage@3x.png', 288]]) {
    await png(launchLogo, size, join(launchSet, file));
}

await browser.close();
process.stdout.write(`icons written (${readdirSync(iconSet).length - 1} iOS icon files)\n`);
