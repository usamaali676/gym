#!/usr/bin/env node
/**
 * Exports the web design tokens (the `@theme` block of resources/css/app.css) to
 *   - design/tokens.json                          (neutral, machine-readable)
 *   - mobile/lib/app/theme/tokens.g.dart          (Flutter constants)
 *
 * The CSS file is the single source of truth. `node scripts/export-design-tokens.mjs --check`
 * (run by tests/js/design-tokens.test.mjs) fails when either generated file is stale.
 */
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const CSS = resolve(root, 'resources/css/app.css');
const JSON_OUT = resolve(root, 'design/tokens.json');
const DART_OUT = resolve(root, 'mobile/lib/app/theme/tokens.g.dart');

/** @returns {{colors: Record<string,string>, fonts: Record<string,string[]>, shadows: Record<string,string>}} */
export function parseTheme(css) {
    const block = css.match(/@theme\s*\{([\s\S]*?)\n\}/);
    if (!block) throw new Error('No @theme block found in app.css');

    const colors = {};
    const fonts = {};
    const shadows = {};

    for (const m of block[1].matchAll(/--([a-z0-9-]+):\s*([^;]+);/gi)) {
        const [, name, rawValue] = m;
        const value = rawValue.trim();
        if (name.startsWith('color-')) colors[name.slice(6)] = value;
        else if (name.startsWith('font-')) fonts[name.slice(5)] = splitFonts(value);
        else if (name.startsWith('shadow-')) shadows[name.slice(7)] = value;
    }

    return { colors, fonts, shadows };
}

function splitFonts(value) {
    return value.split(',').map((f) => f.trim().replace(/^"|"$/g, ''));
}

/** "#39ff14" -> 0xFF39FF14 ; "rgba(57, 255, 20, 0.15)" -> Color.fromRGBO(...) */
function dartColor(value) {
    const hex = value.match(/^#([0-9a-f]{6})$/i);
    if (hex) return `Color(0xFF${hex[1].toUpperCase()})`;
    const rgba = value.match(/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)$/i);
    if (rgba) return `Color.fromRGBO(${rgba[1]}, ${rgba[2]}, ${rgba[3]}, ${rgba[4] ?? '1'})`;
    throw new Error(`Unsupported colour value: ${value}`);
}

function camel(name) {
    return name.replace(/-([a-z0-9])/g, (_, c) => c.toUpperCase());
}

/** "0 0 0 1px rgba(...), 0 0 22px rgba(...)" -> BoxShadow list (only the glow shadows are exported) */
function dartShadows(value) {
    const parts = value.split(/,(?![^(]*\))/).map((p) => p.trim());
    return parts.map((p) => {
        const m = p.match(/^(-?[\d.]+)(?:px)?\s+(-?[\d.]+)(?:px)?\s+([\d.]+)(?:px)?\s*(?:([\d.]+)(?:px)?\s+)?(rgba?\([^)]*\))$/i);
        if (!m) throw new Error(`Unsupported shadow: ${p}`);
        const [, dx, dy, blur, spread, color] = m;
        return `BoxShadow(color: ${dartColor(color)}, offset: Offset(${dx}, ${dy}), blurRadius: ${blur}, spreadRadius: ${spread ?? 0})`;
    });
}

export function render(tokens) {
    const json = `${JSON.stringify(tokens, null, 4)}\n`;

    const colorLines = Object.entries(tokens.colors)
        .map(([k, v]) => `  static const Color ${camel(k)} = ${dartColor(v)};`)
        .join('\n');
    const fontLines = Object.entries(tokens.fonts)
        .map(([k, v]) => `  static const List<String> ${camel(k)} = [${v.map((f) => `'${f}'`).join(', ')}];`)
        .join('\n');
    const shadowLines = Object.entries(tokens.shadows)
        .map(([k, v]) => `  static const List<BoxShadow> ${camel(k)} = [\n    ${dartShadows(v).join(',\n    ')},\n  ];`)
        .join('\n');

    const dart = `// GENERATED FILE. Do not edit by hand.
// Source of truth: resources/css/app.css (@theme). Regenerate with:
//   node scripts/export-design-tokens.mjs
// tests/js/design-tokens.test.mjs fails when this file is out of date.

import 'package:flutter/painting.dart';

/// Brand colours shared with the web app.
abstract final class IpColors {
${colorLines}
}

/// CSS font stacks from the web theme. The first entry is the bundled family.
abstract final class IpFontStacks {
${fontLines}
}

/// Glow shadows from the web theme.
abstract final class IpShadows {
${shadowLines}
}
`;
    return { json, dart };
}

function main() {
    const check = process.argv.includes('--check');
    const tokens = parseTheme(readFileSync(CSS, 'utf8'));
    const { json, dart } = render(tokens);

    const stale = [];
    for (const [path, content] of [[JSON_OUT, json], [DART_OUT, dart]]) {
        const current = existsSync(path) ? readFileSync(path, 'utf8') : null;
        if (current !== content) {
            if (check) stale.push(path.replace(`${root}/`, ''));
            else {
                mkdirSync(dirname(path), { recursive: true });
                writeFileSync(path, content);
                console.log(`wrote ${path.replace(`${root}/`, '')}`);
            }
        }
    }

    if (check) {
        if (stale.length) {
            console.error(`Design tokens are out of date: ${stale.join(', ')}\nRun: node scripts/export-design-tokens.mjs`);
            process.exit(1);
        }
        console.log('design tokens are up to date');
    }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main();
