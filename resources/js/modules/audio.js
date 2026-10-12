/**
 * Sound cues and a tempo metronome, synthesised with the Web Audio API.
 * No audio files, nothing is fetched. Browsers only allow audio after a user gesture, so call
 * `unlock()` from a click handler (the "Start" button does).
 */

let context = null;
let master = null;
let enabled = true;

const AudioCtor = typeof window !== 'undefined' ? window.AudioContext || window.webkitAudioContext : null;

export const isSupported = () => !!AudioCtor;
export const isSoundEnabled = () => enabled;

export function setSoundEnabled(value) {
    enabled = !!value;
    if (master) master.gain.value = enabled ? 0.9 : 0;
}

/** Create or resume the audio context. Safe to call repeatedly. */
export function unlock() {
    if (!AudioCtor) return null;
    if (!context) {
        context = new AudioCtor();
        master = context.createGain();
        master.gain.value = enabled ? 0.9 : 0;
        master.connect(context.destination);
    }
    if (context.state === 'suspended') context.resume().catch(() => {});
    return context;
}

/** Play one short tone. `when` is seconds from now (or an absolute context time if `absolute`). */
function tone(frequency, { duration = 0.12, type = 'sine', gain = 0.2, when = 0, slideTo = null, absolute = false } = {}) {
    if (!enabled || !context) return;
    const start = absolute ? when : context.currentTime + when;
    const osc = context.createOscillator();
    const amp = context.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(frequency, start);
    if (slideTo) osc.frequency.exponentialRampToValueAtTime(slideTo, start + duration);
    amp.gain.setValueAtTime(0.0001, start);
    amp.gain.exponentialRampToValueAtTime(gain, start + 0.01);
    amp.gain.exponentialRampToValueAtTime(0.0001, start + duration);
    osc.connect(amp).connect(master);
    osc.start(start);
    osc.stop(start + duration + 0.02);
}

export const cues = {
    /** 3-2-1 countdown beep. */
    tick: () => tone(660, { duration: 0.14, type: 'square', gain: 0.12 }),
    /** "GO" */
    go: () => { tone(880, { duration: 0.35, type: 'square', gain: 0.16 }); tone(1320, { duration: 0.4, type: 'sine', gain: 0.12, when: 0.05 }); },
    setDone: () => { tone(523, { duration: 0.1, gain: 0.18 }); tone(784, { duration: 0.18, gain: 0.18, when: 0.09 }); },
    restTick: () => tone(740, { duration: 0.07, type: 'triangle', gain: 0.1 }),
    restEnd: () => { tone(988, { duration: 0.15, type: 'square', gain: 0.14 }); tone(988, { duration: 0.15, type: 'square', gain: 0.14, when: 0.2 }); tone(1318, { duration: 0.3, type: 'square', gain: 0.14, when: 0.4 }); },
    pr: () => { [523, 659, 784, 1047].forEach((f, i) => tone(f, { duration: 0.18, type: 'triangle', gain: 0.2, when: i * 0.09 })); },
    fanfare: () => { [392, 523, 659, 784, 1047].forEach((f, i) => tone(f, { duration: 0.28, type: 'triangle', gain: 0.2, when: i * 0.12 })); tone(1047, { duration: 0.8, type: 'sine', gain: 0.18, when: 0.65 }); },
    error: () => tone(180, { duration: 0.25, type: 'sawtooth', gain: 0.1, slideTo: 110 }),
    toggle: () => tone(600, { duration: 0.06, type: 'sine', gain: 0.1 }),
};

/**
 * Tempo metronome with look-ahead scheduling (beats are scheduled on the audio clock, so they do
 * not drift when the main thread is busy). `onBeat(index, isAccent)` fires near each beat for the UI.
 */
export class Metronome {
    constructor(onBeat = () => {}) {
        this.onBeat = onBeat;
        this.bpm = 60;
        this.accentEvery = 4;
        this.running = false;
        this.timer = null;
        this.nextTime = 0;
        this.index = 0;
    }

    start(bpm = this.bpm, accentEvery = this.accentEvery) {
        const ctx = unlock();
        if (!ctx || this.running) return;
        this.bpm = bpm;
        this.accentEvery = accentEvery;
        this.running = true;
        this.index = 0;
        this.nextTime = ctx.currentTime + 0.08;
        this.timer = setInterval(() => this.schedule(), 25);
        this.schedule();
    }

    setBpm(bpm) {
        this.bpm = Math.min(200, Math.max(20, Math.round(bpm)));
    }

    stop() {
        this.running = false;
        clearInterval(this.timer);
        this.timer = null;
    }

    schedule() {
        if (!context) return;
        const lookAhead = 0.12;
        while (this.nextTime < context.currentTime + lookAhead) {
            const accent = this.index % this.accentEvery === 0;
            tone(accent ? 1200 : 800, { duration: 0.05, type: 'square', gain: accent ? 0.13 : 0.08, when: this.nextTime, absolute: true });
            const delay = Math.max(0, (this.nextTime - context.currentTime) * 1000);
            const i = this.index;
            setTimeout(() => this.running && this.onBeat(i, accent), delay);
            this.nextTime += 60 / this.bpm;
            this.index += 1;
        }
    }
}
