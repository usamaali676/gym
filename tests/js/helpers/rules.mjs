import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
export const ROOT = path.resolve(here, '../../..');
export const RULE_DIR = path.join(ROOT, 'database/seeders/data/posture-rules');

export function loadRuleFile(slug) {
    return JSON.parse(fs.readFileSync(path.join(RULE_DIR, `${slug}.json`), 'utf8'));
}

export function loadAllRules() {
    return fs.readdirSync(RULE_DIR).filter((f) => f.endsWith('.json')).map((f) => ({ file: f, ...loadRuleFile(f.replace(/\.json$/, '')) }));
}

export const loadJson = (rel) => JSON.parse(fs.readFileSync(path.join(ROOT, rel), 'utf8'));

/** Apply dot-path `set` / `unset` edits to a deep copy of `config` (numbers index arrays). */
export function mutate(config, { set = {}, unset = [] }) {
    const copy = structuredClone(config);
    const walk = (obj, keys) => keys.slice(0, -1).reduce((o, k) => o[k], obj);
    for (const [p, v] of Object.entries(set)) {
        const keys = p.split('.');
        walk(copy, keys)[keys.at(-1)] = v;
    }
    for (const p of unset) {
        const keys = p.split('.');
        delete walk(copy, keys)[keys.at(-1)];
    }
    return copy;
}
