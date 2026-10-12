import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { test } from 'node:test';
import { parseTheme, render } from '../../scripts/export-design-tokens.mjs';

const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');

test('the web @theme exposes the brand palette the mobile apps rely on', () => {
    const { colors } = parseTheme(css);
    assert.equal(colors.bg0, '#07090a', 'near-black background');
    assert.equal(colors.surface, '#111815', 'graphite card');
    assert.equal(colors.neon, '#39ff14', 'neon green');
    assert.equal(colors.ink, '#e8f5e9', 'primary text');
    assert.equal(colors.muted, '#8fa39a', 'secondary text');
    assert.equal(colors.danger, '#ff3b3b', 'error / incorrect posture');
    assert.equal(colors.warn, '#ffb020', 'amber warning');
});

test('every colour and shadow converts to Dart without loss', () => {
    const { dart } = render(parseTheme(css));
    assert.match(dart, /static const Color neon = Color\(0xFF39FF14\);/);
    assert.match(dart, /static const Color line = Color\.fromRGBO\(57, 255, 20, 0\.15\);/);
    assert.match(dart, /BoxShadow\(color: Color\.fromRGBO\(57, 255, 20, 0\.35\), offset: Offset\(0, 0\), blurRadius: 22/);
});

test('generated token files are committed and current', () => {
    // Throws (non-zero exit) when design/tokens.json or tokens.g.dart differ from what app.css would generate.
    execFileSync('node', ['scripts/export-design-tokens.mjs', '--check'], { cwd: new URL('../..', import.meta.url), stdio: 'pipe' });
});
