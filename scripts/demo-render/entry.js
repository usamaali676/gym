// Runs inside headless Chromium. Drives the web app's own 3D viewer one frame at a time, so a clip is exactly what the
// website shows (same figure, same animation, same camera presets). Nothing here invents motion.
import { ExerciseViewer } from '../../resources/js/modules/exercise-3d.js';
import { animationKeys, getAnimation, hasAnimation } from '../../resources/js/modules/animations.js';

const BG0 = '#07090A';
const BG1 = '#0D1210';

let viewer = null;
let size = 640;
const out = document.createElement('canvas');

window.demo = {
    keys: () => animationKeys(),
    has: (key) => hasAnimation(key),

    /** Show one exercise from one camera. Returns what the encoder needs to know. */
    async open({ key, regions, view, pixels }) {
        size = pixels;
        viewer?.dispose();
        const box = document.createElement('div');
        box.style.cssText = `width:${size}px;height:${size}px;position:fixed;left:0;top:0`;
        document.body.replaceChildren(box);
        viewer = new ExerciseViewer(box, { interactive: false });
        viewer.pause();
        await viewer.setExercise({ key, regions: regions ?? { primary: [], secondary: [] } });
        viewer.setCamera(view, false);
        viewer.resize();
        out.width = size;
        out.height = size;
        const spec = getAnimation(key);
        return { name: spec.name, durationSeconds: spec.duration || 4, thumbT: spec.thumbT ?? 0.5 };
    },

    /** Draw the loop at t (0..1, wraps) over the brand background and return it as a JPEG data URL. */
    frame(t) {
        viewer.t = t;
        viewer.applyFrame();
        viewer.render();
        const ctx = out.getContext('2d');
        const g = ctx.createRadialGradient(size / 2, size * 0.45, size * 0.1, size / 2, size / 2, size * 0.75);
        g.addColorStop(0, BG1);
        g.addColorStop(1, BG0);
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, size, size);
        ctx.drawImage(viewer.renderer.domElement, 0, 0, size, size);
        return out.toDataURL('image/jpeg', 0.93);
    },
};
