#!/usr/bin/env node
/**
 * Installs the MediaPipe assets the Posture Lab needs, so the browser never contacts a third party:
 *
 *   public/vendor/mediapipe/wasm/*                              copied from node_modules/@mediapipe/tasks-vision
 *   public/vendor/mediapipe/models/pose_landmarker_lite.task    downloaded once, SHA-256 verified
 *
 * Usage:  npm run vendor:mediapipe [-- --soft] [-- --force]
 *   --soft   print a warning and exit 0 if something fails (used by `composer setup`, so an offline
 *            install still finishes; the Posture Lab then explains that the model is missing)
 *   --force  re-copy and re-download even if the files are already there
 *
 * Pinned versions and hashes live in scripts/mediapipe-assets.lock.json.
 * A different network setup? Download the model yourself and put it at the path above; if its hash
 * differs from the lock file the script warns but keeps the file when you pass --trust-existing.
 */

import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = new Set(process.argv.slice(2));
const soft = args.has('--soft');
const force = args.has('--force');
const trustExisting = args.has('--trust-existing');
const writeLock = args.has('--write-lock'); // maintainers: refresh hashes after bumping versions

const lockPath = path.join(root, 'scripts/mediapipe-assets.lock.json');
const lock = fs.existsSync(lockPath) ? JSON.parse(fs.readFileSync(lockPath, 'utf8')) : { wasm: {}, model: {} };

const outDir = path.join(root, 'public/vendor/mediapipe');
const wasmOut = path.join(outDir, 'wasm');
const modelOut = path.join(outDir, 'models');
const pkgDir = path.join(root, 'node_modules/@mediapipe/tasks-vision');

const sha256 = (file) => createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const mb = (bytes) => `${(bytes / 1048576).toFixed(1)} MB`;
const problems = [];

function fail(message) {
    problems.push(message);
    console.error(`  ✗ ${message}`);
}

// ---------------------------------------------------------------------------------------------
// 1. WebAssembly runtime
// ---------------------------------------------------------------------------------------------

function copyWasm() {
    const src = path.join(pkgDir, 'wasm');
    if (!fs.existsSync(src)) {
        fail('node_modules/@mediapipe/tasks-vision is missing. Run `npm install` first.');
        return;
    }
    const version = JSON.parse(fs.readFileSync(path.join(pkgDir, 'package.json'), 'utf8')).version;
    if (lock.package && lock.package.version !== version && !writeLock) {
        console.warn(`  ! @mediapipe/tasks-vision is ${version} but the lock file pins ${lock.package.version}.`);
    }

    fs.mkdirSync(wasmOut, { recursive: true });
    const files = fs.readdirSync(src).filter((f) => f.endsWith('.js') || f.endsWith('.wasm')).sort();
    const hashes = {};
    for (const f of files) {
        const from = path.join(src, f);
        const to = path.join(wasmOut, f);
        hashes[f] = sha256(from);
        if (force || !fs.existsSync(to) || sha256(to) !== hashes[f]) fs.copyFileSync(from, to);
        if (!writeLock && lock.wasm?.[f] && lock.wasm[f] !== hashes[f]) {
            fail(`${f} does not match the lock file (installed package differs from the pinned version).`);
        }
    }
    console.log(`  ✓ wasm: ${files.length} files in public/vendor/mediapipe/wasm (${version})`);
    return { version, hashes };
}

// ---------------------------------------------------------------------------------------------
// 2. Pose model
// ---------------------------------------------------------------------------------------------

async function download(url, dest) {
    const tmp = `${dest}.part`;
    try {
        const res = await fetch(url, { signal: AbortSignal.timeout(180_000) });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        fs.writeFileSync(tmp, Buffer.from(await res.arrayBuffer()));
    } catch (error) {
        // Node's fetch ignores HTTPS_PROXY; curl honours it, so fall back to curl behind a proxy.
        console.log(`  … fetch failed (${error.cause?.code ?? error.message}), trying curl`);
        execFileSync('curl', ['-fsSL', '--retry', '3', '--max-time', '300', '-o', tmp, url], { stdio: 'inherit' });
    }
    fs.renameSync(tmp, dest);
}

async function fetchModel() {
    const name = lock.model?.file ?? 'pose_landmarker_lite.task';
    const url = lock.model?.url ?? 'https://storage.googleapis.com/mediapipe-models/pose_landmarker/pose_landmarker_lite/float16/1/pose_landmarker_lite.task';
    const dest = path.join(modelOut, name);
    fs.mkdirSync(modelOut, { recursive: true });

    const verify = (file) => {
        const actual = sha256(file);
        if (writeLock || !lock.model?.sha256) return { ok: true, actual };
        return { ok: actual === lock.model.sha256, actual };
    };

    if (!force && fs.existsSync(dest)) {
        const check = verify(dest);
        if (check.ok || trustExisting) {
            console.log(`  ✓ model: ${name} already installed (${mb(fs.statSync(dest).size)})`);
            return { file: name, url, sha256: check.actual, bytes: fs.statSync(dest).size };
        }
        console.warn('  ! the installed model does not match the lock file, downloading it again');
    }

    console.log(`  … downloading ${name} from ${new URL(url).host}`);
    try {
        await download(url, dest);
    } catch (error) {
        fail(`could not download the model: ${error.message}. Download ${url} yourself and save it as public/vendor/mediapipe/models/${name}.`);
        return null;
    }

    const check = verify(dest);
    if (!check.ok) {
        fs.rmSync(dest, { force: true });
        fail(`SHA-256 mismatch for ${name}: expected ${lock.model.sha256}, got ${check.actual}. The file was removed.`);
        return null;
    }
    console.log(`  ✓ model: ${name} (${mb(fs.statSync(dest).size)}, sha256 ${check.actual.slice(0, 12)}…)`);
    return { file: name, url, sha256: check.actual, bytes: fs.statSync(dest).size };
}

// ---------------------------------------------------------------------------------------------

console.log('Posture Lab assets → public/vendor/mediapipe');
const wasm = copyWasm();
const model = await fetchModel();

fs.writeFileSync(path.join(outDir, 'NOTICE.txt'), [
    'MediaPipe Tasks Vision runtime and the Pose Landmarker (lite) model.',
    'Copyright Google LLC. Licensed under the Apache License, Version 2.0: https://www.apache.org/licenses/LICENSE-2.0',
    'Model card: https://storage.googleapis.com/mediapipe-assets/Model%20Card%20BlazePose%20GHUM%203D.pdf',
    'These files are served from this site; the browser never contacts Google while using the Posture Lab.',
    '',
].join('\n'));

if (writeLock && wasm && model) {
    fs.writeFileSync(lockPath, `${JSON.stringify({
        _comment: 'Pinned MediaPipe assets. Regenerate with `npm run vendor:mediapipe -- --force --write-lock` after bumping @mediapipe/tasks-vision.',
        package: { name: '@mediapipe/tasks-vision', version: wasm.version },
        wasm: wasm.hashes,
        model: { file: model.file, url: model.url, sha256: model.sha256, bytes: model.bytes },
    }, null, 4)}\n`);
    console.log('  ✓ lock file written');
}

if (problems.length) {
    console.error(`\n${problems.length} problem(s). The Posture Lab will not start until this is fixed.`);
    process.exit(soft ? 0 : 1);
}
console.log('Done.');
