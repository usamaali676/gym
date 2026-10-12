/** Food log page: add, edit, delete and copy entries, with live totals from the server. */

const COLORS = { calories: '#39FF14', protein_g: '#00E676', carbs_g: '#FFC83D', fat_g: '#4DA3FF', fibre_g: '#8FA39A' };
const LABELS = { calories: 'Calories', protein_g: 'Protein', carbs_g: 'Carbs', fat_g: 'Fat', fibre_g: 'Fibre' };
const UNITS = { calories: 'kcal', protein_g: 'g', carbs_g: 'g', fat_g: 'g', fibre_g: 'g' };

const round1 = (n) => Math.round(n * 10) / 10;

export default function foodLog(config) {
    let searchAbort = null;
    let messageTimer = null;

    return {
        config,
        logs: config.logs,
        day: config.day,
        sheet: null,
        sheetMeal: 'snack',
        query: '',
        results: [],
        searching: false,
        showAll: false,
        picked: null,
        quantity: 1,
        unit: 'serving',
        servingId: null,
        editing: null,
        confirmDelete: null,
        custom: null,
        busy: false,
        error: '',
        message: '',

        init() {
            this.custom = this.blankCustom();
            this.$watch('confirmDelete', (v) => { if (v !== null) setTimeout(() => { if (this.confirmDelete === v) this.confirmDelete = null; }, 3500); });
        },

        // ---- derived numbers ----------------------------------------------------------------------
        get meters() {
            const t = this.day.totals;
            const g = this.day.targets;
            return ['calories', 'protein_g', 'carbs_g', 'fat_g', 'fibre_g'].map((key) => {
                const consumed = Math.round(t[key]);
                const target = g ? Math.round(g[key]) : null;
                const rem = target === null ? null : target - consumed;
                // Eating more protein or fibre than the target is fine; more calories, carbs or fat is "over".
                const over = rem !== null && rem < 0;
                return {
                    key, label: LABELS[key], unit: UNITS[key], color: COLORS[key], consumed, target,
                    pct: target ? Math.min(100, Math.round((consumed / target) * 100)) : 0,
                    over,
                    note: rem === null ? '' : (over ? `${-rem} over` : `${rem} left`),
                };
            });
        },

        get gapG() {
            return this.day.remaining?.protein_g ?? 0;
        },

        byMeal(meal) {
            return this.logs.filter((l) => l.meal === meal);
        },

        mealTotals(meal) {
            const rows = this.byMeal(meal);
            return { kcal: Math.round(rows.reduce((s, l) => s + l.kcal, 0)), protein: Math.round(rows.reduce((s, l) => s + l.protein_g, 0)) };
        },

        amountLabel(log) {
            if (log.unit === 'serving' && log.serving_label) return `${log.quantity} × ${log.serving_label}`;
            return `${Math.round(log.grams)} g`;
        },

        nextMeal() {
            const empty = Object.keys(this.config.meals).find((m) => this.byMeal(m).length === 0);
            if (this.config.date !== this.config.today) return empty ?? 'snack';
            const h = new Date().getHours();
            return h < 10 ? 'breakfast' : h < 15 ? 'lunch' : h < 18 ? 'snack' : 'dinner';
        },

        // ---- add sheet ----------------------------------------------------------------------------
        openAdd(meal) {
            this.sheetMeal = meal;
            this.sheet = 'add';
            this.picked = null;
            this.query = '';
            this.results = [];
            this.error = '';
            this.$nextTick(() => this.$refs.search?.focus());
        },

        closeSheet() {
            this.sheet = null;
            this.picked = null;
            this.editing = null;
            this.error = '';
        },

        async search() {
            const q = this.query.trim();
            searchAbort?.abort();
            if (q.length < 1) {
                this.results = [];
                this.searching = false;
                return;
            }
            searchAbort = new AbortController();
            this.searching = true;
            try {
                const params = new URLSearchParams({ q });
                if (this.showAll) params.set('all', '1');
                const res = await fetch(`${this.config.urls.search}?${params}`, {
                    signal: searchAbort.signal,
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) throw new Error(String(res.status));
                this.results = (await res.json()).foods;
                this.searching = false;
            } catch (error) {
                if (error.name === 'AbortError') return; // superseded by a newer keystroke
                this.searching = false;
                this.results = [];
                this.error = 'Search failed. Check your connection and try again.';
            }
        },

        pick(food) {
            this.picked = food;
            this.error = '';
            const serving = food.servings.find((s) => s.is_default) ?? food.servings[0];
            this.unit = serving ? 'serving' : 'grams';
            this.servingId = serving?.id ?? null;
            this.quantity = serving ? 1 : 100;
        },

        unitChanged() {
            if (this.unit === 'serving') {
                this.servingId ??= this.picked.servings[0]?.id ?? null;
                this.quantity = 1;
            } else {
                this.quantity = 100;
            }
        },

        get serving() {
            return this.picked?.servings.find((s) => s.id === this.servingId) ?? null;
        },

        get grams() {
            if (!this.picked) return 0;
            const q = Number(this.quantity) || 0;
            return this.unit === 'serving' ? q * (this.serving?.grams ?? 0) : q;
        },

        get preview() {
            const f = this.picked;
            const k = this.grams / 100;
            return f
                ? { kcal: f.kcal_100g * k, protein_g: f.protein_100g * k, carbs_g: f.carbs_100g * k, fat_g: f.fat_100g * k }
                : { kcal: 0, protein_g: 0, carbs_g: 0, fat_g: 0 };
        },

        get canAdd() {
            return this.picked && this.grams >= 1 && this.grams <= 5000 && (this.unit !== 'serving' || this.serving);
        },

        async add(keepGoing) {
            if (!this.canAdd || this.busy) return;
            this.busy = true;
            this.error = '';
            try {
                const res = await window.api(this.config.urls.store, {
                    method: 'POST',
                    body: {
                        food_id: this.picked.id, logged_on: this.config.date, meal: this.sheetMeal,
                        quantity: Number(this.quantity), unit: this.unit, serving_id: this.unit === 'serving' ? this.servingId : null,
                    },
                });
                this.logs.push(res.log);
                this.day = res.day;
                window.toastBadges?.(res.badges);
                this.say(`Added ${res.log.name} to ${this.config.meals[this.sheetMeal].toLowerCase()}.`);
                if (keepGoing) {
                    this.picked = null;
                    this.query = '';
                    this.results = [];
                    this.$nextTick(() => this.$refs.search?.focus());
                } else {
                    this.closeSheet();
                }
            } catch (error) {
                this.error = this.errorText(error);
            } finally {
                this.busy = false;
            }
        },

        /** One-tap add from the protein hint: the suggested portion goes into snacks. */
        async quickAdd(s) {
            if (this.busy) return;
            this.busy = true;
            try {
                const res = await window.api(this.config.urls.store, {
                    method: 'POST',
                    body: { food_id: s.food_id, logged_on: this.config.date, meal: 'snack', quantity: s.grams, unit: 'grams' },
                });
                this.logs.push(res.log);
                this.day = res.day;
                this.say(`Added ${res.log.name} (${Math.round(res.log.grams)} g) to snacks.`);
            } catch (error) {
                this.say(this.errorText(error));
            } finally {
                this.busy = false;
            }
        },

        // ---- edit / delete ------------------------------------------------------------------------
        async openEdit(log) {
            this.error = '';
            this.editing = { ...log, serving_id: null, servings: [], meal: log.meal };
            this.sheet = 'edit';
            if (log.food_id) {
                try {
                    const res = await window.api(`${this.config.urls.food}/${log.food_id}`);
                    const servings = res.food.servings;
                    const match = servings.find((s) => s.label === log.serving_label) ?? servings.find((s) => s.is_default) ?? servings[0];
                    this.editing.servings = servings;
                    this.editing.serving_id = match?.id ?? null;
                    if (log.unit === 'serving' && !match) this.editing.unit = 'grams';
                } catch {
                    this.editing.unit = 'grams'; // the food is gone: edit by weight, the snapshot scales
                    this.editing.quantity = Math.round(log.grams);
                }
            } else {
                this.editing.unit = 'grams';
                this.editing.quantity = Math.round(log.grams);
            }
        },

        editUnitChanged() {
            const e = this.editing;
            if (e.unit === 'serving') {
                e.serving_id ??= e.servings[0]?.id ?? null;
                e.quantity = 1;
            } else {
                e.quantity = Math.round(e.grams) || 100;
            }
        },

        async saveEdit() {
            const e = this.editing;
            if (!e || this.busy) return;
            this.busy = true;
            this.error = '';
            try {
                const res = await window.api(`${this.config.urls.log}/${e.id}`, {
                    method: 'PATCH',
                    body: { quantity: Number(e.quantity), unit: e.unit, serving_id: e.unit === 'serving' ? e.serving_id : null, meal: e.meal },
                });
                this.logs = this.logs.map((l) => (l.id === e.id ? res.log : l));
                this.day = res.day;
                this.closeSheet();
                this.say(`Updated ${res.log.name}.`);
            } catch (error) {
                this.error = this.errorText(error);
            } finally {
                this.busy = false;
            }
        },

        /** First tap arms the button, second tap deletes (no dialog to dismiss on a phone). */
        async remove(log) {
            if (this.confirmDelete !== log.id) {
                this.confirmDelete = log.id;
                this.say(`Tap delete again to remove ${log.name}.`);
                return;
            }
            this.confirmDelete = null;
            try {
                const res = await window.api(`${this.config.urls.log}/${log.id}`, { method: 'DELETE' });
                this.logs = this.logs.filter((l) => l.id !== log.id);
                this.day = res.day;
                this.say(`Removed ${log.name}.`);
            } catch (error) {
                this.say(this.errorText(error));
            }
        },

        async copyYesterday() {
            if (this.busy) return;
            this.busy = true;
            try {
                const res = await window.api(this.config.urls.copy, { method: 'POST', body: { from: this.config.yesterday, to: this.config.date } });
                this.logs = res.logs;
                this.day = res.day;
                const skipped = res.skipped.length ? ` ${res.skipped.join(', ')} already had entries, so they were left alone.` : '';
                this.say(res.copied ? `Copied ${res.copied} ${res.copied === 1 ? 'entry' : 'entries'}.${skipped}` : `Nothing to copy.${skipped || ' That day has no entries.'}`);
            } catch (error) {
                this.say(this.errorText(error));
            } finally {
                this.busy = false;
            }
        },

        // ---- custom food --------------------------------------------------------------------------
        blankCustom() {
            return { name: '', kcal_100g: null, protein_100g: null, carbs_100g: null, fat_100g: null, fibre_100g: null, diet_type: 'vegan', serving_label: '', serving_grams: null };
        },

        openCustom() {
            this.custom = this.blankCustom();
            if (this.query.trim()) this.custom.name = this.query.trim();
            this.error = '';
            this.sheet = 'custom';
        },

        async saveCustom() {
            if (this.busy) return;
            const c = this.custom;
            this.busy = true;
            this.error = '';
            try {
                const res = await window.api(this.config.urls.customFood, {
                    method: 'POST',
                    body: {
                        name: c.name, diet_type: c.diet_type, kcal_100g: c.kcal_100g, protein_100g: c.protein_100g, carbs_100g: c.carbs_100g, fat_100g: c.fat_100g,
                        fibre_100g: c.fibre_100g || 0,
                        servings: c.serving_label && c.serving_grams ? [{ label: c.serving_label, grams: c.serving_grams }] : [],
                    },
                });
                this.sheet = 'add';
                this.pick(res.food);
            } catch (error) {
                this.error = this.errorText(error);
            } finally {
                this.busy = false;
            }
        },

        // ---- helpers ------------------------------------------------------------------------------
        say(text) {
            this.message = text;
            clearTimeout(messageTimer);
            messageTimer = setTimeout(() => { this.message = ''; }, 6000);
        },

        errorText(error) {
            const first = error.data?.errors ? Object.values(error.data.errors)[0]?.[0] : null;
            return first ?? error.data?.message ?? error.message ?? 'Something went wrong.';
        },

        fmt: round1,
    };
}
