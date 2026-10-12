/**
 * Skeleton overlay. Bones the rules flag are drawn red, everything else neon green, bones the camera
 * cannot see well are drawn faint. Red/green is never the only signal: the HUD also shows the cue text.
 */

import { BONES, DRAW_ORDER, LANDMARK_INDEX } from './geometry.js';

const GREEN = '#39FF14';
const RED = '#FF3B3B';

/** Resolve a bone's endpoint to a normalised point, or null if it is not visible enough. */
function endpoint(landmarks, name, minVis) {
    if (name.startsWith('mid_')) {
        const side = name === 'mid_shoulder' ? [11, 12] : [23, 24];
        const pts = side.map((i) => landmarks[i]).filter((p) => p && (p.visibility ?? 1) >= minVis);
        if (!pts.length) return null;
        return { x: pts.reduce((s, p) => s + p.x, 0) / pts.length, y: pts.reduce((s, p) => s + p.y, 0) / pts.length };
    }
    const p = landmarks[LANDMARK_INDEX[name]];
    return p && (p.visibility ?? 1) >= minVis ? p : null;
}

/**
 * Size a canvas to its CSS box at device resolution. Returns the logical (CSS pixel) size.
 */
export function fitCanvas(canvas) {
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const w = canvas.clientWidth;
    const h = canvas.clientHeight;
    if (canvas.width !== Math.round(w * dpr) || canvas.height !== Math.round(h * dpr)) {
        canvas.width = Math.round(w * dpr);
        canvas.height = Math.round(h * dpr);
    }
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { ctx, width: w, height: h };
}

/**
 * @param {CanvasRenderingContext2D} ctx
 * @param {Array<{x:number,y:number,visibility?:number}>|null} landmarks normalised, unmirrored
 * @param {{width:number,height:number,mirror?:boolean,red?:Set<string>,minVis?:number,glow?:boolean,joints?:boolean,labels?:Array<{point:string,text:string}>}} opts
 */
export function drawSkeleton(ctx, landmarks, { width, height, mirror = false, red = new Set(), minVis = 0.5, glow = true, joints = true, labels = [] }) {
    ctx.clearRect(0, 0, width, height);
    if (!landmarks) return;

    const px = (p) => ({ x: (mirror ? 1 - p.x : p.x) * width, y: p.y * height });
    const base = Math.max(3, Math.min(width, height) / 90);

    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';

    for (const id of DRAW_ORDER) {
        const [a, b] = BONES[id];
        const pa = endpoint(landmarks, a, 0.2);
        const pb = endpoint(landmarks, b, 0.2);
        if (!pa || !pb) continue;

        const visible = !!endpoint(landmarks, a, minVis) && !!endpoint(landmarks, b, minVis);
        const bad = red.has(id);
        const color = bad ? RED : GREEN;
        const A = px(pa);
        const B = px(pb);

        ctx.globalAlpha = visible ? 1 : 0.22;
        ctx.strokeStyle = color;
        ctx.lineWidth = bad ? base * 1.7 : base * 1.25;
        ctx.shadowColor = color;
        ctx.shadowBlur = glow && visible ? base * (bad ? 5 : 3) : 0;
        ctx.beginPath();
        ctx.moveTo(A.x, A.y);
        ctx.lineTo(B.x, B.y);
        ctx.stroke();
    }
    ctx.shadowBlur = 0;

    if (joints) {
        const names = ['nose', 'l_shoulder', 'r_shoulder', 'l_elbow', 'r_elbow', 'l_wrist', 'r_wrist', 'l_hip', 'r_hip', 'l_knee', 'r_knee', 'l_ankle', 'r_ankle'];
        for (const name of names) {
            const p = endpoint(landmarks, name, minVis);
            if (!p) continue;
            const { x, y } = px(p);
            ctx.globalAlpha = 1;
            ctx.fillStyle = '#041006';
            ctx.strokeStyle = '#E8F5E9';
            ctx.lineWidth = Math.max(1.5, base * 0.45);
            ctx.beginPath();
            ctx.arc(x, y, base * 1.05, 0, Math.PI * 2);
            ctx.fill();
            ctx.stroke();
        }
    }

    ctx.globalAlpha = 1;
    ctx.font = `600 ${Math.max(11, base * 3.2)}px Inter, system-ui, sans-serif`;
    ctx.textBaseline = 'middle';
    for (const { point, text } of labels) {
        const p = endpoint(landmarks, point, minVis);
        if (!p) continue;
        const { x, y } = px(p);
        const pad = 5;
        const w = ctx.measureText(text).width + pad * 2;
        ctx.fillStyle = 'rgba(4, 16, 6, 0.78)';
        ctx.fillRect(x + 10, y - 11, w, 22);
        ctx.fillStyle = '#E8F5E9';
        ctx.fillText(text, x + 10 + pad, y);
    }
}

/** Positioning aid: an outline to fit the whole body in, with a floor line. */
export function drawGuide(ctx, { width, height, color = 'rgba(57, 255, 20, 0.55)' }) {
    ctx.clearRect(0, 0, width, height);
    const insetX = width * 0.18;
    const top = height * 0.06;
    const bottom = height * 0.94;
    ctx.save();
    ctx.setLineDash([10, 8]);
    ctx.lineWidth = 2;
    ctx.strokeStyle = color;
    ctx.strokeRect(insetX, top, width - insetX * 2, bottom - top);
    ctx.setLineDash([]);
    ctx.font = '600 12px Inter, system-ui, sans-serif';
    ctx.fillStyle = color;
    ctx.textAlign = 'center';
    ctx.fillText('Fit your whole body inside the box', width / 2, top + 18);
    ctx.restore();
}
