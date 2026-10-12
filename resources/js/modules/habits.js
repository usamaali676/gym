/** Recovery page: water, creatine, sleep and mobility minutes, each saved the moment you tap. */
export default function habits(config) {
    return {
        s: config.state,
        busy: false,
        message: '',
        custom: 300,
        sleepForm: {
            hours: config.state.sleep.hours ?? config.state.sleep.goal,
            quality: config.state.sleep.quality ?? 3,
            bedtime: config.state.sleep.bedtime ?? '',
            wake_time: config.state.sleep.wake_time ?? '',
        },
        mobility: config.state.mobility_minutes,

        get waterPct() {
            return this.s.water.pct;
        },

        get waterDash() {
            const c = 2 * Math.PI * 44;
            return `${(this.waterPct / 100) * c} ${c}`;
        },

        async call(url, method, body) {
            if (this.busy) return null;
            this.busy = true;
            this.message = '';
            try {
                const res = await window.api(url, { method, body });
                this.s = res;
                window.toastBadges?.(res.badges);
                return res;
            } catch (error) {
                this.message = error.data?.errors ? Object.values(error.data.errors)[0][0] : (error.message ?? 'Something went wrong.');
                return null;
            } finally {
                this.busy = false;
            }
        },

        async drink(ml) {
            const before = this.s.water.reached;
            const res = await this.call(config.urls.water, 'POST', { amount_ml: Math.round(ml) });
            if (res && !before && res.water.reached) window.toast?.(`Water goal reached${res.water.xp ? ` (+${res.water.xp} XP)` : ''}`);
        },

        undoDrink() {
            return this.call(config.urls.undoWater, 'DELETE');
        },

        async toggleCreatine() {
            const res = await this.call(config.urls.creatine, 'PUT', { taken: !this.s.creatine.taken });
            if (res?.creatine.taken) window.toast?.(`Creatine logged. Streak: ${res.creatine.current} ${res.creatine.current === 1 ? 'day' : 'days'}`);
        },

        async saveSleep() {
            const f = this.sleepForm;
            const res = await this.call(config.urls.sleep, 'PUT', {
                sleep_hours: Number(f.hours), sleep_quality: Number(f.quality), bedtime: f.bedtime || null, wake_time: f.wake_time || null,
            });
            if (res) window.toast?.('Sleep saved');
        },

        saveMobility() {
            return this.call(config.urls.mobility, 'PUT', { minutes: Math.max(0, Math.round(Number(this.mobility) || 0)) });
        },

        sleepBar(h) {
            return h === null ? 0 : Math.min(100, Math.round((h / 10) * 100));
        },

        dayLabel(iso) {
            return new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { weekday: 'narrow' });
        },
    };
}
