/**
 * Alpine component shared by the onboarding wizard and the profile page.
 * - Canonical values (height_cm / weight_kg) are always submitted in metric via hidden inputs.
 * - The visible inputs switch between metric and imperial without any server round-trip.
 * - In wizard mode it walks through steps, validating each step before moving on.
 */
const round1 = (n) => Math.round(n * 10) / 10;

export default (config) => ({
    wizard: !!config.wizard,
    step: config.wizard ? config.errorStep || 1 : 0,
    total: 4,
    unit: config.unit || 'metric',
    cm: config.heightCm ?? '',
    kg: config.weightKg ?? '',
    ft: '',
    inch: '',
    lb: '',
    timezone: config.timezone || 'UTC',
    equipment: config.equipment || [],
    allEquipment: config.allEquipment || [],

    init() {
        this.syncImperial();
        try {
            const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (tz && !config.timezone) this.timezone = tz;
        } catch { /* keep default */ }
    },

    syncImperial() {
        const cm = parseFloat(this.cm);
        if (!Number.isNaN(cm) && cm > 0) {
            const totalIn = cm / 2.54;
            this.ft = Math.floor(totalIn / 12);
            this.inch = Math.round(totalIn - this.ft * 12);
            if (this.inch === 12) { this.ft += 1; this.inch = 0; }
        }
        const kg = parseFloat(this.kg);
        if (!Number.isNaN(kg) && kg > 0) this.lb = round1(kg * 2.2046226);
    },

    setUnit(unit) {
        if (unit === this.unit) return;
        if (unit === 'imperial') this.syncImperial();
        else {
            this.cm = this.heightCm || '';
            this.kg = this.weightKg || '';
        }
        this.unit = unit;
    },

    get heightCm() {
        if (this.unit === 'metric') return this.cm === '' ? '' : round1(parseFloat(this.cm));
        const total = (parseFloat(this.ft) || 0) * 12 + (parseFloat(this.inch) || 0);
        return total > 0 ? round1(total * 2.54) : '';
    },
    get weightKg() {
        if (this.unit === 'metric') return this.kg === '' ? '' : round1(parseFloat(this.kg));
        const lb = parseFloat(this.lb);
        return lb > 0 ? round1(lb / 2.2046226) : '';
    },
    get heightHint() {
        const cm = parseFloat(this.heightCm);
        if (!cm) return '';
        const totalIn = cm / 2.54;
        return this.unit === 'metric'
            ? `${Math.floor(totalIn / 12)}'${Math.round(totalIn % 12)}"`
            : `${cm} cm`;
    },
    get weightHint() {
        const kg = parseFloat(this.weightKg);
        if (!kg) return '';
        return this.unit === 'metric' ? `${round1(kg * 2.2046226)} lb` : `${kg} kg`;
    },

    toggleAllEquipment() {
        this.equipment = this.equipment.length >= this.allEquipment.length ? [] : [...this.allEquipment];
    },

    stepIsValid() {
        const panel = this.$refs[`step${this.step}`];
        if (!panel) return true;
        for (const field of panel.querySelectorAll('input, select, textarea')) {
            if (field.type === 'hidden' || field.disabled) continue;
            if (!field.checkValidity()) { field.reportValidity(); return false; }
        }
        if (this.step === 4 && this.equipment.length === 0) {
            this.$refs.equipmentError.hidden = false;
            return false;
        }
        return true;
    },
    next() {
        if (this.stepIsValid() && this.step < this.total) {
            this.step += 1;
            this.$nextTick(() => this.$refs[`step${this.step}`]?.querySelector('input:not([type=hidden]), select')?.focus({ preventScroll: true }));
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    },
    prev() {
        if (this.step > 1) this.step -= 1;
    },
});
