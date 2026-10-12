/** Diet page: swap suggested meals and log one with a tap. */
export default function mealPlan(config) {
    return {
        slots: config.meals,
        choice: Object.fromEntries(config.meals.map((s) => [s.slot, 0])),
        logged: {},
        busy: null,
        message: '',

        /** The meal currently shown for a slot: the best fit first, then the alternatives in order. */
        current(slot) {
            const i = this.choice[slot.slot];
            return i === 0 ? slot.meal : slot.alternatives[i - 1];
        },

        swap(slot) {
            const options = slot.alternatives.length + 1;
            this.choice[slot.slot] = (this.choice[slot.slot] + 1) % options;
            this.logged[slot.slot] = false;
        },

        get dayTotals() {
            const sum = { kcal: 0, protein_g: 0 };
            for (const s of this.slots) {
                const m = this.current(s);
                sum.kcal += m.kcal;
                sum.protein_g += m.protein_g;
            }
            return sum;
        },

        async log(slot) {
            if (this.logged[slot.slot]) return;
            const meal = this.current(slot);
            this.busy = slot.slot;
            this.message = '';
            try {
                await window.api(config.batchUrl, {
                    method: 'POST',
                    body: { logged_on: config.date, meal: slot.slot, items: meal.items.map((i) => ({ food_id: i.food_id, grams: i.grams })) },
                });
                this.logged[slot.slot] = true;
                this.message = `${slot.label} logged: ${meal.name}. You can edit the amounts in your food log.`;
            } catch (error) {
                this.message = error.data?.message ?? 'Could not log that meal. Try again.';
            } finally {
                this.busy = null;
            }
        },
    };
}
