/**
 * One-Euro filter (Casiez, Roussel, Vogel 2012): a low-pass filter whose cut-off rises with speed,
 * so a still limb is rock steady while a fast one keeps up with almost no lag.
 * Pose landmarks jitter by a few pixels from frame to frame, which would flicker the skeleton
 * and wreck threshold-based rep counting, so every landmark goes through one of these.
 */

const smoothingFactor = (cutoff, dt) => {
    const r = 2 * Math.PI * cutoff * dt;
    return r / (r + 1);
};

export class OneEuroFilter {
    /**
     * @param {{minCutoff?:number, beta?:number, dCutoff?:number}} options
     *   minCutoff: Hz at rest (lower = smoother). beta: how fast the cut-off grows with speed. dCutoff: Hz for the speed estimate.
     */
    constructor({ minCutoff = 1.0, beta = 40, dCutoff = 2 } = {}) {
        this.minCutoff = minCutoff;
        this.beta = beta;
        this.dCutoff = dCutoff;
        this.reset();
    }

    reset() {
        this.t = null;
        this.x = 0;
        this.dx = 0;
    }

    /** @param {number} value @param {number} tMs timestamp in milliseconds */
    filter(value, tMs) {
        if (this.t === null) {
            this.t = tMs;
            this.x = value;
            this.dx = 0;
            return value;
        }

        const dt = Math.max((tMs - this.t) / 1000, 1e-3);
        this.t = tMs;

        const rawSpeed = (value - this.x) / dt;
        const aD = smoothingFactor(this.dCutoff, dt);
        this.dx += aD * (rawSpeed - this.dx);

        const cutoff = this.minCutoff + this.beta * Math.abs(this.dx);
        const a = smoothingFactor(cutoff, dt);
        this.x += a * (value - this.x);

        return this.x;
    }
}

/** One filter per landmark axis. Resets itself after a gap (the person left the frame). */
export class LandmarkSmoother {
    constructor({ minCutoff = 1.0, beta = 40, dCutoff = 2, count = 33, resetGapMs = 500 } = {}) {
        this.options = { minCutoff, beta, dCutoff };
        this.count = count;
        this.resetGapMs = resetGapMs;
        this.filters = null;
        this.visibility = null;
        this.lastT = null;
        this.reset();
    }

    reset() {
        this.filters = Array.from({ length: this.count }, () => ({
            x: new OneEuroFilter(this.options),
            y: new OneEuroFilter(this.options),
            z: new OneEuroFilter(this.options),
        }));
        this.visibility = new Array(this.count).fill(null);
        this.lastT = null;
    }

    /**
     * @param {Array<{x:number,y:number,z?:number,visibility?:number}>} landmarks
     * @returns {Array<{x:number,y:number,z:number,visibility:number}>}
     */
    smooth(landmarks, tMs) {
        if (this.lastT !== null && tMs - this.lastT > this.resetGapMs) this.reset();
        this.lastT = tMs;

        return landmarks.map((l, i) => {
            const f = this.filters[i];
            const raw = l.visibility ?? 1;
            // Visibility flickers around thresholds; a light EMA avoids bones blinking on and off.
            const previous = this.visibility[i];
            const visibility = previous === null ? raw : previous + 0.5 * (raw - previous);
            this.visibility[i] = visibility;

            return {
                x: f.x.filter(l.x, tMs),
                y: f.y.filter(l.y, tMs),
                z: f.z.filter(l.z ?? 0, tMs),
                visibility,
            };
        });
    }
}
