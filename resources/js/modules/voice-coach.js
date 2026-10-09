/** Spoken prompts via the Web Speech API (built into the browser; nothing is sent anywhere). */
export class VoiceCoach {
    constructor(enabled = false) {
        this.enabled = enabled;
        this.synth = typeof window !== 'undefined' ? window.speechSynthesis : null;
        this.voice = null;
        if (this.synth) {
            this.pickVoice();
            this.synth.addEventListener?.('voiceschanged', () => this.pickVoice());
        }
    }

    get supported() {
        return !!this.synth && typeof SpeechSynthesisUtterance !== 'undefined';
    }

    pickVoice() {
        const voices = this.synth?.getVoices?.() ?? [];
        this.voice = voices.find((v) => /^en/i.test(v.lang) && v.localService) ?? voices.find((v) => /^en/i.test(v.lang)) ?? null;
    }

    setEnabled(value) {
        this.enabled = !!value;
        if (!this.enabled) this.cancel();
    }

    /** Say something. New prompts interrupt older ones so the coach never lags behind the workout. */
    say(text, { interrupt = true } = {}) {
        if (!this.enabled || !this.supported || !text) return;
        if (interrupt) this.synth.cancel();
        const utterance = new SpeechSynthesisUtterance(text);
        if (this.voice) utterance.voice = this.voice;
        utterance.rate = 1.05;
        utterance.pitch = 1;
        utterance.volume = 1;
        this.synth.speak(utterance);
    }

    cancel() {
        this.synth?.cancel?.();
    }
}
