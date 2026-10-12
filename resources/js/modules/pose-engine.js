/**
 * Camera + MediaPipe Pose Landmarker.
 *
 * Everything runs in the browser. The model (.task) and the WebAssembly runtime are served from this
 * site's own /vendor/mediapipe folder (see `npm run vendor:mediapipe`), the camera frames never leave
 * the page, and nothing is recorded.
 */

/** A short, user-facing reason why the camera or model could not start. */
export class PoseError extends Error {
    /** @param {'insecure'|'unsupported'|'denied'|'no_camera'|'in_use'|'assets'|'model'|'unknown'} code */
    constructor(code, message, cause) {
        super(message);
        this.name = 'PoseError';
        this.code = code;
        this.cause = cause;
    }
}

export const PROBLEMS = {
    insecure: 'The camera only works on https:// pages or on localhost. Open the app over HTTPS and try again.',
    unsupported: 'This browser cannot open a camera from a web page. Try a recent Chrome, Edge, Safari or Firefox.',
    denied: 'Camera permission was blocked. Allow the camera for this site in your browser settings, then reload the page.',
    no_camera: 'No camera was found. Connect or enable a camera and try again.',
    in_use: 'The camera is busy or could not be started. Close other apps or tabs that use it and try again.',
    assets: 'The pose model files are not installed on this server yet. Run `npm run vendor:mediapipe` (see the README) and reload.',
    model: 'The pose model could not be loaded. Check your connection to this site and try again.',
    unknown: 'Something went wrong starting the camera.',
};

export function classifyCameraError(error) {
    const name = error?.name ?? '';
    if (name === 'NotAllowedError' || name === 'SecurityError' || name === 'PermissionDeniedError') return 'denied';
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') return 'no_camera';
    if (name === 'NotReadableError' || name === 'TrackStartError' || name === 'AbortError') return 'in_use';
    return 'unknown';
}

/** Can this page ask for a camera at all? Returns an error code or null. */
export function cameraUnavailableReason() {
    if (typeof window === 'undefined') return 'unsupported';
    if (!window.isSecureContext) return 'insecure';
    if (!navigator.mediaDevices?.getUserMedia) return 'unsupported';
    return null;
}

/**
 * Open the camera into a <video>. Prefers 1280x720 at 30 fps; MediaPipe resizes internally,
 * so more pixels only cost CPU.
 * @returns {Promise<{stream: MediaStream, facing: string, width: number, height: number, stop: () => void}>}
 */
export async function openCamera(video, { facing = 'user', width = 1280, height = 720 } = {}) {
    const reason = cameraUnavailableReason();
    if (reason) throw new PoseError(reason, PROBLEMS[reason]);

    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: { facingMode: { ideal: facing }, width: { ideal: width }, height: { ideal: height }, frameRate: { ideal: 30, max: 30 } },
        });
    } catch (error) {
        const code = classifyCameraError(error);
        throw new PoseError(code, PROBLEMS[code], error);
    }

    video.srcObject = stream;
    video.muted = true;
    video.playsInline = true;
    await new Promise((resolve, reject) => {
        const done = () => { video.removeEventListener('loadedmetadata', done); resolve(); };
        video.addEventListener('loadedmetadata', done);
        setTimeout(() => reject(new PoseError('in_use', PROBLEMS.in_use)), 8000);
    });
    try {
        await video.play();
    } catch (error) {
        stream.getTracks().forEach((t) => t.stop());
        throw new PoseError('in_use', PROBLEMS.in_use, error);
    }

    const settings = stream.getVideoTracks()[0]?.getSettings?.() ?? {};
    return {
        stream,
        facing: settings.facingMode ?? facing,
        width: video.videoWidth,
        height: video.videoHeight,
        stop() {
            stream.getTracks().forEach((t) => t.stop());
            video.srcObject = null;
        },
    };
}

/**
 * Load the Pose Landmarker from same-origin assets. Tries the GPU delegate and falls back to the CPU one.
 * @param {{wasm: string, model: string, onStatus?: (text: string) => void}} assets
 * @returns {Promise<{landmarker: object, delegate: 'GPU'|'CPU'}>}
 */
export async function loadLandmarker({ wasm, model, onStatus = () => {} }) {
    onStatus('Loading the pose model…');

    // Fail fast with a useful message if the files were never installed (a bare 404 inside MediaPipe is cryptic).
    try {
        const head = await fetch(model, { method: 'HEAD', credentials: 'same-origin' });
        if (!head.ok) throw new Error(String(head.status));
    } catch (error) {
        throw new PoseError('assets', PROBLEMS.assets, error);
    }

    const { FilesetResolver, PoseLandmarker } = await import('@mediapipe/tasks-vision');
    let vision;
    try {
        vision = await FilesetResolver.forVisionTasks(wasm);
    } catch (error) {
        throw new PoseError('assets', PROBLEMS.assets, error);
    }

    const create = (delegate) => PoseLandmarker.createFromOptions(vision, {
        baseOptions: { modelAssetPath: model, delegate },
        runningMode: 'VIDEO',
        numPoses: 1,
        minPoseDetectionConfidence: 0.5,
        minPosePresenceConfidence: 0.5,
        minTrackingConfidence: 0.5,
        outputSegmentationMasks: false,
    });

    try {
        return { landmarker: await create('GPU'), delegate: 'GPU' };
    } catch (gpuError) {
        onStatus('GPU not available, using the CPU…');
        try {
            return { landmarker: await create('CPU'), delegate: 'CPU' };
        } catch (cpuError) {
            throw new PoseError('model', PROBLEMS.model, cpuError ?? gpuError);
        }
    }
}

/**
 * Runs the landmarker on every new video frame and reports landmarks (or null when nobody is found).
 * The loop is throttled when inference is slow so a weak phone degrades gracefully instead of freezing.
 */
export class PoseRunner {
    /**
     * @param {HTMLVideoElement} video
     * @param {object} landmarker a PoseLandmarker in VIDEO mode
     * @param {(frame: {landmarks: Array|null, t: number, width: number, height: number}) => void} onFrame
     */
    constructor(video, landmarker, onFrame) {
        this.video = video;
        this.landmarker = landmarker;
        this.onFrame = onFrame;
        this.running = false;
        this.handle = null;
        this.lastVideoTime = -1;
        this.lastT = 0;
        this.inferenceMs = 0;
        this.fps = 0;
        this.frames = 0;
        this.skip = false;
        this.onVisibility = () => {
            if (document.hidden) this.cancelLoop();
            else if (this.running) this.schedule();
        };
    }

    start() {
        if (this.running) return;
        this.running = true;
        document.addEventListener('visibilitychange', this.onVisibility);
        this.schedule();
    }

    stop() {
        this.running = false;
        document.removeEventListener('visibilitychange', this.onVisibility);
        this.cancelLoop();
    }

    schedule() {
        this.cancelLoop();
        const tick = () => this.step();
        if (typeof this.video.requestVideoFrameCallback === 'function') {
            this.handle = { type: 'video', id: this.video.requestVideoFrameCallback(tick) };
        } else {
            this.handle = { type: 'raf', id: requestAnimationFrame(tick) };
        }
    }

    cancelLoop() {
        if (!this.handle) return;
        if (this.handle.type === 'video') this.video.cancelVideoFrameCallback?.(this.handle.id);
        else cancelAnimationFrame(this.handle.id);
        this.handle = null;
    }

    step() {
        if (!this.running) return;
        const video = this.video;

        if (video.readyState >= 2 && video.videoWidth > 0 && video.currentTime !== this.lastVideoTime) {
            this.lastVideoTime = video.currentTime;
            // When inference takes longer than ~45 ms, analyse every other frame to keep the page responsive.
            this.skip = this.inferenceMs > 45 ? !this.skip : false;
            if (!this.skip) this.detect();
        }
        if (this.running) this.schedule();
    }

    detect() {
        const t = performance.now();
        if (t <= this.lastT) return; // MediaPipe needs strictly increasing timestamps
        let result;
        const started = performance.now();
        try {
            result = this.landmarker.detectForVideo(this.video, t);
        } catch (error) {
            // A failed frame is not fatal (tab was throttled, GPU context lost for a moment).
            console.warn('Pose frame skipped:', error);
            return;
        }
        const took = performance.now() - started;
        this.inferenceMs = this.inferenceMs === 0 ? took : this.inferenceMs + 0.1 * (took - this.inferenceMs);
        if (this.lastT) {
            const inst = 1000 / Math.max(1, t - this.lastT);
            this.fps = this.fps === 0 ? inst : this.fps + 0.08 * (inst - this.fps);
        }
        this.lastT = t;
        this.frames += 1;

        const landmarks = result?.landmarks?.[0] ?? null;
        this.onFrame({ landmarks, t, width: this.video.videoWidth, height: this.video.videoHeight });
    }

    close() {
        this.stop();
        try { this.landmarker.close?.(); } catch { /* already closed */ }
    }
}
