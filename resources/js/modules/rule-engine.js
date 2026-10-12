/**
 * Posture rule engine: validates a rule's JSON config, compiles it, and evaluates its named
 * measurements and checks on one frame of landmarks. Pure functions (no DOM, no MediaPipe), so every
 * rule shipped in database/seeders/data/posture-rules is unit-tested on synthetic landmarks.
 *
 * Rule config (schema 1), all keys snake_case:
 *   side            auto | left | right | both. Which body side bare names like "elbow" refer to.
 *   required        points that must be visible before a set can start.
 *   measures        named numbers computed per frame (angle, inclination, offset, ratio, line_offset, diff).
 *   rep             the rep state machine (movement: signal/start/peak/tolerance...; or hold: in_position conditions).
 *   checks          named form errors: a measure, a comparison, a severity, a message and the bones to colour red.
 *
 * The PHP validator (app/Services/PostureRuleValidator.php) mirrors validateRule() and uses the same messages.
 */

import {
    angleAt, clamp, dist, inclination, isPointName, isSegmentGroup, isSidedBare, lineOffsetUp, resolvePoint,
} from './geometry.js';

export const SCHEMA = 1;
export const SIDES = ['auto', 'left', 'right', 'both'];
export const MEASURE_TYPES = ['angle', 'inclination', 'offset', 'ratio', 'line_offset', 'diff'];
export const NORMS = ['torso', 'shoulder_width', 'thigh', 'shank', 'upper_arm', 'forearm', 'none'];
export const AXES = ['x', 'forward', 'up'];
export const OPS = ['<', '>', 'outside', 'inside'];
export const PHASES = ['rest', 'out', 'peak', 'back', 'hold'];
export const STATS = ['min', 'max', 'peak', 'range'];
export const SEVERITIES = ['major', 'minor'];
export const COMBINE = ['mean', 'min', 'max'];
export const RELATIVE = ['delta', 'ratio'];

const NAME_RE = /^[a-z][a-z0-9_]*$/;
const isNum = (v) => typeof v === 'number' && Number.isFinite(v);
const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

export class RuleConfigError extends Error {
    constructor(errors) {
        super(`Invalid posture rule: ${errors.join('; ')}`);
        this.name = 'RuleConfigError';
        this.errors = errors;
    }
}

// ---------------------------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------------------------

/** @returns {string[]} human-readable problems; empty when the config is valid */
export function validateRule(config) {
    const errors = [];
    if (!isObj(config)) return ['config must be an object'];

    if (config.schema !== SCHEMA) errors.push(`schema must be ${SCHEMA}`);
    if (config.side !== undefined && !SIDES.includes(config.side)) errors.push(`side must be one of ${SIDES.join(', ')}`);
    if (config.min_visibility !== undefined && !(isNum(config.min_visibility) && config.min_visibility >= 0.1 && config.min_visibility <= 0.95)) {
        errors.push('min_visibility must be a number between 0.1 and 0.95');
    }
    if (config.good_score !== undefined && !(isNum(config.good_score) && config.good_score >= 0 && config.good_score <= 100)) {
        errors.push('good_score must be a number between 0 and 100');
    }
    if (config.smoothing !== undefined) {
        const s = config.smoothing;
        if (!isObj(s) || (s.min_cutoff !== undefined && !(isNum(s.min_cutoff) && s.min_cutoff > 0)) || (s.beta !== undefined && !(isNum(s.beta) && s.beta >= 0))) {
            errors.push('smoothing needs min_cutoff > 0 and beta >= 0');
        }
    }

    if (!Array.isArray(config.required) || config.required.length === 0) {
        errors.push('required must list at least one point');
    } else {
        for (const p of config.required) if (!isPointName(p)) errors.push(`unknown point "${p}" in required`);
    }

    const measures = isObj(config.measures) ? config.measures : null;
    if (!measures || Object.keys(measures).length === 0) {
        errors.push('measures must define at least one measure');
    } else {
        for (const [name, def] of Object.entries(measures)) validateMeasure(name, def, measures, errors);
    }

    const names = measures ? new Set(Object.keys(measures)) : new Set();
    validateRep(config.rep, names, errors);
    validateChecks(config.checks, names, config.rep, errors);

    return errors;
}

function checkPoint(measureName, point, errors) {
    if (!isPointName(point)) errors.push(`unknown point "${point}" in measure "${measureName}"`);
}

function validateMeasure(name, def, measures, errors) {
    if (!NAME_RE.test(name)) {
        errors.push(`measure name "${name}" is invalid`);
        return;
    }
    if (!isObj(def)) {
        errors.push(`measure "${name}" must be an object`);
        return;
    }
    if (!MEASURE_TYPES.includes(def.type)) {
        errors.push(`measure "${name}" has unknown type "${def.type}"`);
        return;
    }
    if (def.combine !== undefined && !COMBINE.includes(def.combine)) errors.push(`measure "${name}": combine must be one of ${COMBINE.join(', ')}`);
    if (def.relative !== undefined && def.relative !== null && !RELATIVE.includes(def.relative)) errors.push(`measure "${name}": relative must be one of ${RELATIVE.join(', ')}`);
    if (def.norm !== undefined && !NORMS.includes(def.norm)) errors.push(`measure "${name}": norm must be one of ${NORMS.join(', ')}`);

    switch (def.type) {
        case 'angle':
            if (!Array.isArray(def.points) || def.points.length !== 3) errors.push(`measure "${name}": points must list exactly 3 points`);
            else def.points.forEach((p) => checkPoint(name, p, errors));
            break;
        case 'inclination':
            checkPoint(name, def.from, errors);
            checkPoint(name, def.to, errors);
            if (!['vertical', 'horizontal'].includes(def.ref)) errors.push(`measure "${name}": ref must be vertical or horizontal`);
            break;
        case 'offset':
            checkPoint(name, def.from, errors);
            checkPoint(name, def.to, errors);
            if (!AXES.includes(def.axis)) errors.push(`measure "${name}": axis must be one of ${AXES.join(', ')}`);
            break;
        case 'ratio':
            for (const key of ['num', 'den']) {
                if (!Array.isArray(def[key]) || def[key].length !== 2) errors.push(`measure "${name}": ${key} must list exactly 2 points`);
                else def[key].forEach((p) => checkPoint(name, p, errors));
            }
            break;
        case 'line_offset':
            checkPoint(name, def.from, errors);
            checkPoint(name, def.to, errors);
            checkPoint(name, def.point, errors);
            break;
        case 'diff':
            for (const key of ['a', 'b']) {
                const target = measures[def[key]];
                if (typeof def[key] !== 'string' || def[key] === name || !isObj(target)) errors.push(`measure "${name}": ${key} must reference another measure`);
                else if (target.type === 'diff') errors.push(`measure "${name}": ${key} cannot reference a diff measure`);
            }
            break;
        default:
    }
}

function validateRep(rep, measureNames, errors) {
    if (!isObj(rep)) {
        errors.push('rep must be an object');
        return;
    }

    if (rep.hold === true) {
        if (!Array.isArray(rep.in_position) || rep.in_position.length === 0) {
            errors.push('rep.in_position must list at least one condition for a hold');
        } else {
            rep.in_position.forEach((c, i) => {
                if (!isObj(c) || !measureNames.has(c.measure)) errors.push(`rep.in_position[${i}] must reference a measure`);
                else validateComparison(`rep.in_position[${i}]`, c, errors);
            });
        }
        for (const key of ['settle_ms', 'grace_ms', 'min_hold_ms']) {
            if (rep[key] !== undefined && !(isNum(rep[key]) && rep[key] >= 0)) errors.push(`rep.${key} must be a number >= 0`);
        }
        return;
    }

    if (typeof rep.signal !== 'string' || !measureNames.has(rep.signal)) errors.push(`rep.signal "${rep.signal}" is not a measure`);
    if (!isNum(rep.start) || !isNum(rep.peak)) {
        errors.push('rep.start and rep.peak must be numbers');
        return;
    }
    if (rep.start === rep.peak) errors.push('rep.start and rep.peak must differ');
    const range = Math.abs(rep.peak - rep.start);
    if (!isNum(rep.tolerance) || rep.tolerance <= 0) errors.push('rep.tolerance must be a number greater than 0');
    else if (rep.tolerance >= range / 2) errors.push('rep.tolerance must be smaller than half the range between start and peak');
    const min = rep.min_ms ?? 700;
    const max = rep.max_ms ?? 15000;
    if (!isNum(min) || min < 100) errors.push('rep.min_ms must be a number >= 100');
    if (!isNum(max) || max <= min) errors.push('rep.max_ms must be greater than rep.min_ms');
    if (rep.min_rom !== undefined && !(isNum(rep.min_rom) && rep.min_rom > 0 && rep.min_rom <= 1)) errors.push('rep.min_rom must be a number between 0 and 1');
    if (rep.settle_ms !== undefined && !(isNum(rep.settle_ms) && rep.settle_ms >= 0)) errors.push('rep.settle_ms must be a number >= 0');
    if (rep.fast_ms !== undefined && !(isNum(rep.fast_ms) && rep.fast_ms > min)) errors.push('rep.fast_ms must be greater than rep.min_ms');
    if (rep.partial_message !== undefined && !(typeof rep.partial_message === 'string' && rep.partial_message.trim() !== '' && rep.partial_message.length <= 110)) {
        errors.push('rep.partial_message must be a short text (110 characters at most)');
    }
}

function validateComparison(where, c, errors) {
    if (!OPS.includes(c.op)) errors.push(`${where}: op must be one of ${OPS.join(', ')}`);
    else if (c.op === 'outside' || c.op === 'inside') {
        if (!Array.isArray(c.value) || c.value.length !== 2 || !c.value.every(isNum) || c.value[0] >= c.value[1]) errors.push(`${where}: value must be [low, high] for ${c.op}`);
    } else if (!isNum(c.value)) {
        errors.push(`${where}: value must be a number`);
    }
}

function validateChecks(checks, measureNames, rep, errors) {
    if (checks === undefined) return;
    if (!Array.isArray(checks)) {
        errors.push('checks must be a list');
        return;
    }
    const seen = new Set();
    checks.forEach((c, i) => {
        const where = `check #${i + 1}`;
        if (!isObj(c)) {
            errors.push(`${where} must be an object`);
            return;
        }
        if (typeof c.id !== 'string' || !NAME_RE.test(c.id)) errors.push(`${where}: id is invalid`);
        else if (seen.has(c.id)) errors.push(`duplicate check id "${c.id}"`);
        else seen.add(c.id);
        const label = typeof c.id === 'string' ? `check "${c.id}"` : where;

        if (typeof c.label !== 'string' || c.label.trim() === '') errors.push(`${label}: label is required`);
        if (typeof c.message !== 'string' || c.message.trim() === '') errors.push(`${label}: message is required`);
        if (!measureNames.has(c.measure)) errors.push(`${label}: measure "${c.measure}" does not exist`);
        if (!SEVERITIES.includes(c.severity ?? 'major')) errors.push(`${label}: severity must be major or minor`);
        validateComparison(label, c, errors);

        const scope = c.scope ?? 'frame';
        if (!['frame', 'rep'].includes(scope)) errors.push(`${label}: scope must be frame or rep`);
        if (scope === 'rep') {
            if (!STATS.includes(c.stat)) errors.push(`${label}: stat must be one of ${STATS.join(', ')}`);
            if (rep?.hold === true) errors.push(`${label}: rep-scope checks need a movement rep, not a hold`);
        }
        if (c.phases !== undefined) {
            if (!Array.isArray(c.phases) || c.phases.length === 0 || !c.phases.every((p) => PHASES.includes(p))) errors.push(`${label}: phases must be a list of ${PHASES.join(', ')}`);
        }
        if (c.frames !== undefined && !(Number.isInteger(c.frames) && c.frames >= 1 && c.frames <= 30)) errors.push(`${label}: frames must be a whole number between 1 and 30`);
        if (c.segments !== undefined) {
            if (!Array.isArray(c.segments)) errors.push(`${label}: segments must be a list`);
            else for (const s of c.segments) if (!isSegmentGroup(s)) errors.push(`${label}: unknown segment "${s}"`);
        }
    });
}

// ---------------------------------------------------------------------------------------------
// Compilation
// ---------------------------------------------------------------------------------------------

/** Validate and fill in defaults. Throws RuleConfigError. The result is deeply frozen. */
export function compileRule(config) {
    const errors = validateRule(config);
    if (errors.length) throw new RuleConfigError(errors);

    const isHold = config.rep.hold === true;
    const measures = {};
    for (const [name, def] of Object.entries(config.measures)) {
        measures[name] = { ...def, combine: def.combine ?? 'mean', relative: def.relative ?? null, norm: def.norm ?? (def.type === 'offset' || def.type === 'line_offset' ? 'torso' : 'none') };
        measures[name].sided = measureUsesSide(measures[name]);
    }

    const compiled = {
        schema: SCHEMA,
        side: config.side ?? 'auto',
        minVisibility: config.min_visibility ?? 0.5,
        goodScore: config.good_score ?? 75,
        smoothing: { minCutoff: config.smoothing?.min_cutoff ?? 1.0, beta: config.smoothing?.beta ?? 40 },
        required: [...config.required],
        measures,
        rep: isHold
            ? {
                kind: 'hold',
                inPosition: config.rep.in_position.map((c) => ({ ...c })),
                settleMs: config.rep.settle_ms ?? 800,
                graceMs: config.rep.grace_ms ?? 900,
                minHoldMs: config.rep.min_hold_ms ?? 3000,
            }
            : {
                kind: 'movement',
                signal: config.rep.signal,
                start: config.rep.start,
                peak: config.rep.peak,
                tolerance: config.rep.tolerance,
                settleMs: config.rep.settle_ms ?? 250,
                minMs: config.rep.min_ms ?? 700,
                maxMs: config.rep.max_ms ?? 15000,
                minRom: config.rep.min_rom ?? 0.45,
                fastMs: config.rep.fast_ms ?? null,
                lostMs: config.rep.lost_ms ?? 1200,
                partialMessage: config.rep.partial_message ?? 'Go through the full range of motion.',
            },
        checks: (config.checks ?? []).map((c) => ({
            id: c.id,
            label: c.label,
            message: c.message,
            measure: c.measure,
            op: c.op,
            value: c.value,
            abs: c.abs === true,
            severity: c.severity ?? 'major',
            scope: c.scope ?? 'frame',
            stat: c.stat ?? null,
            phases: c.phases ?? (isHold ? ['hold'] : ['out', 'peak', 'back']),
            segments: c.segments ?? [],
            frames: c.frames ?? 4,
        })),
    };

    return deepFreeze(compiled);
}

function deepFreeze(o) {
    Object.freeze(o);
    for (const v of Object.values(o)) if (v && typeof v === 'object' && !Object.isFrozen(v)) deepFreeze(v);
    return o;
}

function pointsOf(def) {
    switch (def.type) {
        case 'angle': return def.points;
        case 'inclination': return [def.from, def.to];
        case 'offset': return [def.from, def.to];
        case 'ratio': return [...def.num, ...def.den];
        case 'line_offset': return [def.from, def.to, def.point];
        default: return [];
    }
}

function measureUsesSide(def) {
    const normSided = ['torso', 'thigh', 'shank', 'upper_arm', 'forearm'].includes(def.norm) && (def.type === 'offset' || def.type === 'line_offset');
    return normSided || pointsOf(def).some(isSidedBare);
}

/** Bare sided points a rule needs, expanded to the active side. Used for side selection and readiness. */
export function requiredPoints(compiled, side) {
    return compiled.required.flatMap((name) => {
        if (!isSidedBare(name)) return [name];
        if (side === 'both') return [`l_${name}`, `r_${name}`];
        return [`${side === 'left' || side === 'l' ? 'l' : 'r'}_${name}`];
    });
}

// ---------------------------------------------------------------------------------------------
// Evaluation
// ---------------------------------------------------------------------------------------------

/**
 * A frame view: scaled landmarks (x already multiplied by the aspect ratio) plus visibility
 * threshold and facing. Built by the analyzer; tests can build one directly.
 *
 * @typedef {{points: Array<{x:number,y:number,v:number}>, minVis: number, facing: number, aspect: number}} FrameView
 */

const inFrame = (p, aspect) => p.x >= -0.05 * aspect && p.x <= 1.05 * aspect && p.y >= -0.05 && p.y <= 1.05;

/** Fetch a named point for one side. Returns null if it is hidden or off screen. */
export function getPoint(view, name, side, missing) {
    const idx = resolvePoint(name, side);
    if (!idx) return null;
    const pts = idx.map((i) => view.points[i]).filter((p) => p && p.v >= view.minVis && inFrame(p, view.aspect));
    if (pts.length === 0) {
        missing?.add(name);
        return null;
    }
    if (pts.length === 1) return pts[0];
    return { x: (pts[0].x + pts[1].x) / 2, y: (pts[0].y + pts[1].y) / 2, v: Math.min(pts[0].v, pts[1].v) };
}

export function normLength(norm, view, side, missing) {
    switch (norm) {
        case 'none': return 1;
        case 'torso': {
            const a = getPoint(view, 'shoulder', side, missing);
            const b = getPoint(view, 'hip', side, missing);
            return a && b ? dist(a, b) : null;
        }
        case 'shoulder_width': {
            const a = getPoint(view, 'l_shoulder', side, missing);
            const b = getPoint(view, 'r_shoulder', side, missing);
            return a && b ? dist(a, b) : null;
        }
        case 'thigh': {
            const a = getPoint(view, 'hip', side, missing);
            const b = getPoint(view, 'knee', side, missing);
            return a && b ? dist(a, b) : null;
        }
        case 'shank': {
            const a = getPoint(view, 'knee', side, missing);
            const b = getPoint(view, 'ankle', side, missing);
            return a && b ? dist(a, b) : null;
        }
        case 'upper_arm': {
            const a = getPoint(view, 'shoulder', side, missing);
            const b = getPoint(view, 'elbow', side, missing);
            return a && b ? dist(a, b) : null;
        }
        case 'forearm': {
            const a = getPoint(view, 'elbow', side, missing);
            const b = getPoint(view, 'wrist', side, missing);
            return a && b ? dist(a, b) : null;
        }
        default: return null;
    }
}

function evaluateOne(def, view, side, missing) {
    const get = (name) => getPoint(view, name, side, missing);
    switch (def.type) {
        case 'angle': {
            const [a, b, c] = def.points.map(get);
            return a && b && c ? angleAt(a, b, c) : null;
        }
        case 'inclination': {
            const a = get(def.from);
            const b = get(def.to);
            return a && b ? inclination(a, b, def.ref) : null;
        }
        case 'offset': {
            const a = get(def.from);
            const b = get(def.to);
            const n = normLength(def.norm, view, side, missing);
            if (!a || !b || !n) return null;
            const dx = b.x - a.x;
            const dy = b.y - a.y;
            const component = def.axis === 'x' ? dx : def.axis === 'forward' ? dx * view.facing : -dy;
            return component / n;
        }
        case 'ratio': {
            const [a, b] = def.num.map(get);
            const [c, d] = def.den.map(get);
            if (!a || !b || !c || !d) return null;
            const den = dist(c, d);
            return den < 1e-6 ? null : dist(a, b) / den;
        }
        case 'line_offset': {
            const a = get(def.from);
            const b = get(def.to);
            const p = get(def.point);
            const n = normLength(def.norm, view, side, missing);
            if (!a || !b || !p || !n) return null;
            const off = lineOffsetUp(a, b, p);
            return off === null ? null : off / n;
        }
        default: return null;
    }
}

/**
 * Compute every measure for a frame.
 * @param {ReturnType<typeof compileRule>} rule
 * @param {FrameView} view
 * @param {'l'|'r'} side the active side (used for bare names unless the rule's side is 'both')
 * @returns {{values: Record<string, number|null>, missing: Set<string>}}
 */
export function evaluateMeasures(rule, view, side) {
    const missing = new Set();
    const values = {};
    const both = rule.side === 'both';

    for (const [name, def] of Object.entries(rule.measures)) {
        if (def.type === 'diff') continue;
        if (both && def.sided) {
            const l = evaluateOne(def, view, 'l', missing);
            const r = evaluateOne(def, view, 'r', missing);
            const present = [l, r].filter((v) => v !== null);
            if (present.length === 0) values[name] = null;
            else if (def.combine === 'min') values[name] = Math.min(...present);
            else if (def.combine === 'max') values[name] = Math.max(...present);
            else values[name] = present.reduce((s, v) => s + v, 0) / present.length;
        } else {
            values[name] = evaluateOne(def, view, side, missing);
        }
    }

    for (const [name, def] of Object.entries(rule.measures)) {
        if (def.type !== 'diff') continue;
        const a = values[def.a];
        const b = values[def.b];
        values[name] = a === null || a === undefined || b === null || b === undefined ? null : Math.abs(a - b);
    }

    return { values, missing };
}

/** Does `value` violate the comparison? Null never violates (we cannot judge what we cannot see). */
export function violates(value, op, limit, abs = false) {
    if (value === null || value === undefined || Number.isNaN(value)) return false;
    const v = abs ? Math.abs(value) : value;
    switch (op) {
        case '<': return v < limit;
        case '>': return v > limit;
        case 'outside': return v < limit[0] || v > limit[1];
        case 'inside': return v >= limit[0] && v <= limit[1];
        default: return false;
    }
}

/** Pick the body side with the better visibility for the rule's required points (for side-on cameras). */
export function sideVisibility(rule, points) {
    const score = (side) => {
        const names = rule.required.filter(isSidedBare);
        if (names.length === 0) return 0;
        return names.reduce((sum, n) => sum + (points[resolvePoint(n, side)[0]]?.v ?? 0), 0) / names.length;
    };
    return { l: score('l'), r: score('r') };
}

export { clamp };
