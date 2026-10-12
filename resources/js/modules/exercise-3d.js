/**
 * 3D exercise demos with Three.js.
 *
 * - ExerciseViewer: an interactive viewer (orbit/rotate, front/side/45 degree camera buttons, speed and
 *   slow-motion, play/pause, muscle highlighting).
 * - ThumbRenderer: ONE shared offscreen WebGL renderer that draws pose snapshots into plain 2D canvases,
 *   so a page of 24 exercise cards needs one WebGL context, not 24.
 *
 * The default figure is built procedurally (humanoid.js + animations.js), so everything works with no
 * external asset. An exercise can optionally point at a .glb model (exercises.glb_path); it is loaded
 * with GLTFLoader and its first animation clip is played instead.
 */
import {
    ACESFilmicToneMapping, AmbientLight, AnimationMixer, CircleGeometry, DirectionalLight, GridHelper,
    HemisphereLight, Mesh, MeshBasicMaterial, PerspectiveCamera, RingGeometry, Scene, SRGBColorSpace, Vector3,
    WebGLRenderer,
} from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { applyPose, clearProps, createHumanoid, disposeObject, setRegions } from './humanoid.js';
import { getAnimation, poseAt } from './animations.js';

const prefersReducedMotion = () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

export const CAMERAS = {
    front: { label: 'Front', dir: [0, 0.12, 1] },
    side: { label: 'Side', dir: [1, 0.1, 0] },
    angle: { label: '45°', dir: [0.72, 0.22, 0.72] },
};

export const SPEEDS = [0.25, 0.5, 1, 1.5, 2];

/** Is WebGL available at all? */
export function webglSupported() {
    try {
        const canvas = document.createElement('canvas');
        return !!(window.WebGLRenderingContext && (canvas.getContext('webgl2') || canvas.getContext('webgl')));
    } catch {
        return false;
    }
}

function buildScene() {
    const scene = new Scene();
    const rig = createHumanoid();
    scene.add(rig.root);
    scene.add(rig.props);

    scene.add(new HemisphereLight(0xd8f5e3, 0x07120b, 1.15));
    scene.add(new AmbientLight(0x8fb59f, 0.35));
    const key = new DirectionalLight(0xffffff, 1.6);
    key.position.set(2.2, 4, 3);
    scene.add(key);
    const rim = new DirectionalLight(0x39ff14, 0.9);
    rim.position.set(-3, 2.5, -2.5);
    scene.add(rim);

    const floor = new Mesh(new CircleGeometry(1.55, 48), new MeshBasicMaterial({ color: 0x0b140f, transparent: true, opacity: 0.85 }));
    floor.rotation.x = -Math.PI / 2;
    floor.position.y = -0.002;
    scene.add(floor);
    const ring = new Mesh(new RingGeometry(1.5, 1.56, 64), new MeshBasicMaterial({ color: 0x39ff14, transparent: true, opacity: 0.75 }));
    ring.rotation.x = -Math.PI / 2;
    ring.position.y = 0;
    scene.add(ring);
    const grid = new GridHelper(3.1, 12, 0x1fa80d, 0x123a1a);
    grid.material.transparent = true;
    grid.material.opacity = 0.22;
    grid.position.y = 0.001;
    scene.add(grid);

    return { scene, rig };
}

/** Apply an animation spec to a rig at loop time t and keep its props in sync. Returns the live prop handles. */
function installAnimation(rig, spec) {
    clearProps(rig);
    applyPose(rig, poseAt(spec, 0), spec.setup);
    const ctx = { pelvis: rig.pelvis.getWorldPosition(new Vector3()) };
    const props = spec.setup.props ? spec.setup.props(rig, ctx).flat().filter(Boolean) : [];
    return props;
}

function framing(spec) {
    // A demo can say where to look when its equipment reaches well beyond the figure (a leg press sled).
    if (spec.setup.frame) return { target: new Vector3(...spec.setup.frame.target), distance: spec.setup.frame.distance };
    const ground = spec.setup.ground;
    if (ground === 'hands') return { target: new Vector3(0, 1.2, 0), distance: 5.4 };
    if (spec.base?.rootPitch && Math.abs(spec.base.rootPitch) > 60) return { target: new Vector3(0, 0.65, 0), distance: 4.2 };
    return { target: new Vector3(0, 0.98, 0), distance: 4.3 };
}

// -------------------------------------------------------------------------------------------------
// Interactive viewer
// -------------------------------------------------------------------------------------------------

export class ExerciseViewer {
    constructor(container, { interactive = true, autoRotate = false } = {}) {
        this.container = container;
        this.interactive = interactive;
        this.speed = 1;
        this.playing = !prefersReducedMotion();
        this.t = 0.46;
        this.camName = 'angle';
        this.props = [];
        this.spec = null;
        this.glb = null;
        this.mixer = null;
        this.lastTime = performance.now();
        this.disposed = false;
        this.visible = true;
        this.raf = 0;

        this.renderer = new WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'low-power' });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        this.renderer.outputColorSpace = SRGBColorSpace;
        this.renderer.toneMapping = ACESFilmicToneMapping;
        this.renderer.domElement.className = 'h-full w-full touch-none';
        this.renderer.domElement.setAttribute('role', 'img');
        this.renderer.domElement.setAttribute('aria-label', '3D exercise demonstration. Drag to rotate.');
        container.replaceChildren(this.renderer.domElement);

        const { scene, rig } = buildScene();
        this.scene = scene;
        this.rig = rig;
        this.camera = new PerspectiveCamera(36, 1, 0.1, 50);
        this.controls = new OrbitControls(this.camera, this.renderer.domElement);
        this.controls.enableDamping = true;
        this.controls.dampingFactor = 0.08;
        this.controls.enablePan = false;
        this.controls.minDistance = 2;
        this.controls.maxDistance = 7;
        this.controls.maxPolarAngle = Math.PI * 0.52;
        this.controls.autoRotate = autoRotate;
        this.controls.autoRotateSpeed = 1.6;
        this.controls.enabled = interactive;

        this.resizeObserver = new ResizeObserver(() => this.resize());
        this.resizeObserver.observe(container);
        this.io = new IntersectionObserver((entries) => {
            this.visible = entries.some((e) => e.isIntersecting);
            this.kick();
        });
        this.io.observe(container);
        this.onVisibility = () => this.kick();
        document.addEventListener('visibilitychange', this.onVisibility);

        this.resize();
        this.kick();
    }

    resize() {
        const w = Math.max(1, this.container.clientWidth);
        const h = Math.max(1, this.container.clientHeight);
        this.renderer.setSize(w, h, false);
        this.camera.aspect = w / h;
        this.camera.updateProjectionMatrix();
        this.render();
    }

    /** Show an exercise: demo key (animation registry), muscle regions, optional glb url. */
    async setExercise({ key, regions, glbUrl = null }) {
        this.key = key;
        setRegions(this.rig, regions);
        this.disposeGlb();

        if (glbUrl) {
            try {
                await this.loadGlb(glbUrl);
                return;
            } catch {
                // Fall back to the built-in figure if the model cannot be loaded.
            }
        }

        this.rig.root.visible = true;
        this.spec = getAnimation(key);
        this.props = installAnimation(this.rig, this.spec);
        this.t = 0.46;
        this.setCamera(this.spec.camera === 'front' ? 'front' : this.spec.camera === 'angle' ? 'angle' : 'side', false);
        this.applyFrame();
        this.kick();
    }

    async loadGlb(url) {
        const { GLTFLoader } = await import('three/examples/jsm/loaders/GLTFLoader.js');
        const gltf = await new GLTFLoader().loadAsync(url);
        this.rig.root.visible = false;
        this.glb = gltf.scene;
        this.scene.add(this.glb);
        if (gltf.animations?.length) {
            this.mixer = new AnimationMixer(this.glb);
            this.mixer.clipAction(gltf.animations[0]).play();
        }
        this.spec = { duration: 4, camera: 'angle', setup: {}, frames: [] };
        this.setCamera('angle', false);
        this.kick();
    }

    disposeGlb() {
        if (this.glb) {
            this.scene.remove(this.glb);
            disposeObject(this.glb);
            this.glb = null;
        }
        this.mixer = null;
    }

    setCamera(name, animate = true) {
        const preset = CAMERAS[name] ?? CAMERAS.angle;
        this.camName = name;
        const { target, distance } = this.spec ? framing(this.spec) : { target: new Vector3(0, 0.95, 0), distance: 4 };
        const dir = new Vector3(...preset.dir).normalize().multiplyScalar(distance);
        this.controls.target.copy(target);
        if (animate && !prefersReducedMotion()) {
            this.camTween = { from: this.camera.position.clone(), to: target.clone().add(dir), start: performance.now(), duration: 450 };
        } else {
            this.camera.position.copy(target).add(dir);
            this.camTween = null;
        }
        this.controls.update();
        this.kick();
    }

    setSpeed(value) {
        this.speed = value;
    }

    setAutoRotate(on) {
        this.controls.autoRotate = !!on;
        this.kick();
    }

    play() {
        this.playing = true;
        this.lastTime = performance.now();
        this.kick();
    }

    pause() {
        this.playing = false;
    }

    toggle() {
        this.playing ? this.pause() : this.play();
        return this.playing;
    }

    applyFrame() {
        if (!this.spec?.frames?.length) return;
        applyPose(this.rig, poseAt(this.spec, this.t), this.spec.setup);
        for (const prop of this.props) prop.update?.(this.rig);
    }

    /** Start the render loop if something needs to animate. */
    kick() {
        if (this.disposed || this.raf) return;
        if (!this.visible || document.hidden) return;
        this.lastTime = performance.now();
        this.raf = requestAnimationFrame(() => this.loop());
    }

    loop() {
        this.raf = 0;
        if (this.disposed || !this.visible || document.hidden) return;

        const now = performance.now();
        const dt = Math.min(0.1, (now - this.lastTime) / 1000);
        this.lastTime = now;
        if (this.playing && this.spec) {
            this.t = (this.t + (dt * this.speed) / (this.spec.duration || 4)) % 1;
            this.applyFrame();
            this.mixer?.update(dt * this.speed);
        }

        if (this.camTween) {
            const k = Math.min(1, (performance.now() - this.camTween.start) / this.camTween.duration);
            const e = 0.5 - 0.5 * Math.cos(Math.PI * k);
            this.camera.position.lerpVectors(this.camTween.from, this.camTween.to, e);
            if (k >= 1) this.camTween = null;
        }
        this.controls.update();
        this.render();

        const animating = this.playing || this.camTween || this.controls.autoRotate;
        if (animating) this.raf = requestAnimationFrame(() => this.loop());
    }

    render() {
        this.renderer.render(this.scene, this.camera);
    }

    dispose() {
        this.disposed = true;
        cancelAnimationFrame(this.raf);
        this.resizeObserver.disconnect();
        this.io.disconnect();
        document.removeEventListener('visibilitychange', this.onVisibility);
        this.controls.dispose();
        this.disposeGlb();
        disposeObject(this.scene);
        this.renderer.dispose();
        this.renderer.forceContextLoss?.();
        this.renderer.domElement.remove();
    }
}

/** Build the standard control bar (camera, speed, play/pause, rotate) for a viewer. Returns the element. */
export function buildViewerControls(viewer) {
    const bar = document.createElement('div');
    bar.className = 'flex flex-wrap items-center justify-between gap-2';
    const button = (label, onClick, { pressed = false, aria } = {}) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = label;
        b.className = 'btn btn-ghost btn-sm !min-h-9 !px-3 !text-[0.65rem]';
        if (aria) b.setAttribute('aria-label', aria);
        b.setAttribute('aria-pressed', String(pressed));
        b.addEventListener('click', onClick);
        return b;
    };

    const group = (label) => {
        const g = document.createElement('div');
        g.className = 'flex items-center gap-1';
        g.setAttribute('role', 'group');
        g.setAttribute('aria-label', label);
        return g;
    };
    const mark = (buttons, active) => buttons.forEach((b, i) => { const on = i === active; b.setAttribute('aria-pressed', String(on)); b.classList.toggle('!border-neon', on); b.classList.toggle('!text-neon', on); });

    const camGroup = group('Camera angle');
    const camButtons = Object.entries(CAMERAS).map(([name, { label }]) => button(label, () => { viewer.setCamera(name); mark(camButtons, Object.keys(CAMERAS).indexOf(name)); }, { aria: `${label} view` }));
    camButtons.forEach((b) => camGroup.append(b));
    mark(camButtons, Object.keys(CAMERAS).indexOf(viewer.camName));

    const speedGroup = group('Playback speed');
    const speedButtons = SPEEDS.map((v) => button(`${v}×`, () => { viewer.setSpeed(v); mark(speedButtons, SPEEDS.indexOf(v)); }, { aria: `Speed ${v} times` }));
    speedButtons.forEach((b) => speedGroup.append(b));
    mark(speedButtons, SPEEDS.indexOf(viewer.speed));

    const actions = group('Playback');
    const playBtn = button(viewer.playing ? 'Pause' : 'Play', () => { playBtn.textContent = viewer.toggle() ? 'Pause' : 'Play'; });
    const slow = button('Slow-mo', () => { viewer.setSpeed(0.25); mark(speedButtons, 0); });
    const rotate = button('Auto-rotate', () => { const on = !viewer.controls.autoRotate; viewer.setAutoRotate(on); rotate.setAttribute('aria-pressed', String(on)); rotate.classList.toggle('!text-neon', on); });
    actions.append(playBtn, slow, rotate);

    bar.append(camGroup, speedGroup, actions);
    return bar;
}

// -------------------------------------------------------------------------------------------------
// Shared thumbnail renderer
// -------------------------------------------------------------------------------------------------

class ThumbRenderer {
    constructor() {
        this.renderer = new WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: false });
        this.renderer.setPixelRatio(1);
        this.renderer.outputColorSpace = SRGBColorSpace;
        this.renderer.toneMapping = ACESFilmicToneMapping;
        const { scene, rig } = buildScene();
        this.scene = scene;
        this.rig = rig;
        this.camera = new PerspectiveCamera(34, 1, 0.1, 50);
        this.queue = Promise.resolve();
        this.cache = new Map();
    }

    /** Render a representative pose of an exercise into a 2D canvas (queued, one at a time). */
    snapshot(key, regions, size = 160, t = null, view = null) {
        const task = this.queue.then(() => this.draw(key, regions, size, t, view));
        this.queue = task.catch(() => {});
        return task;
    }

    draw(key, regions, size, t = null, view = null) {
        const cacheKey = `${key}:${size}:${t}:${view}:${JSON.stringify(regions)}`;
        const cached = this.cache.get(cacheKey);
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');
        if (cached) {
            ctx.drawImage(cached, 0, 0);
            return canvas;
        }

        const spec = getAnimation(key);
        setRegions(this.rig, regions);
        const props = installAnimation(this.rig, spec);
        applyPose(this.rig, poseAt(spec, t ?? spec.thumbT ?? 0.5), spec.setup);
        props.forEach((p) => p.update?.(this.rig));

        const { target, distance } = framing(spec);
        const camera = view ?? (spec.camera === 'front' ? 'front' : 'angle');
        const dir = new Vector3(...CAMERAS[camera].dir).normalize().multiplyScalar(distance * 1.0);
        this.camera.position.copy(target).add(dir);
        this.camera.lookAt(target);
        this.renderer.setSize(size, size, false);
        this.renderer.render(this.scene, this.camera);
        ctx.drawImage(this.renderer.domElement, 0, 0, size, size);

        const copy = document.createElement('canvas');
        copy.width = size;
        copy.height = size;
        copy.getContext('2d').drawImage(canvas, 0, 0);
        this.cache.set(cacheKey, copy);
        clearProps(this.rig);
        return canvas;
    }
}

let thumbs = null;

/** Replace the placeholder icon in every `.demo-thumb` with a rendered pose, as they scroll into view. */
export function initDemoThumbs(root = document) {
    const nodes = [...root.querySelectorAll('.demo-thumb:not([data-ready])')];
    if (!nodes.length || !webglSupported()) return;

    const io = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            io.unobserve(entry.target);
            const el = entry.target;
            thumbs ??= new ThumbRenderer();
            const size = Math.round(Math.max(96, Math.min(240, el.clientWidth * (window.devicePixelRatio > 1 ? 2 : 1.5))));
            let regions;
            try { regions = JSON.parse(el.dataset.regions || '{}'); } catch { regions = {}; }
            const t = el.dataset.t !== undefined ? parseFloat(el.dataset.t) : null;
            thumbs.snapshot(el.dataset.demo, regions, size, Number.isNaN(t) ? null : t, el.dataset.view || null).then((canvas) => {
                canvas.className = 'size-full object-contain';
                el.dataset.ready = '1';
                el.replaceChildren(canvas);
            }).catch(() => { /* keep the icon */ });
        }
    }, { rootMargin: '120px' });
    nodes.forEach((n) => io.observe(n));
}

/** Mount an interactive viewer (+ controls) into a `.demo-stage` element. Returns the viewer or null. */
export async function mountStage(el, { withControls = true, autoRotate = false } = {}) {
    if (!webglSupported()) {
        el.innerHTML = '<p class="px-4 text-center text-sm text-muted">3D preview needs WebGL, which is not available in this browser.</p>';
        return null;
    }
    let regions;
    try { regions = JSON.parse(el.dataset.regions || '{}'); } catch { regions = {}; }
    const viewer = new ExerciseViewer(el, { autoRotate });
    await viewer.setExercise({ key: el.dataset.demo, regions, glbUrl: el.dataset.glb || null });
    if (withControls) {
        const controls = buildViewerControls(viewer);
        el.parentElement.insertBefore(controls, el.nextSibling);
    }
    return viewer;
}
