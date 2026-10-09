/** Alpine component for the "swap exercise" bottom sheet on the plan day page. */
export default () => ({
    open: false,
    loading: false,
    saving: false,
    error: '',
    itemId: null,
    itemName: '',
    alternatives: [],

    async show(itemId, name) {
        this.itemId = itemId;
        this.itemName = name;
        this.alternatives = [];
        this.error = '';
        this.open = true;
        this.loading = true;
        try {
            const data = await window.api(`/api/plan-items/${itemId}/alternatives`);
            this.alternatives = data.alternatives;
        } catch (e) {
            this.error = e.message || 'Could not load alternatives.';
        } finally {
            this.loading = false;
        }
    },

    close() {
        this.open = false;
    },

    async choose(exerciseId, permanent) {
        this.saving = true;
        this.error = '';
        try {
            await window.api(`/api/plan-items/${this.itemId}/swap`, { method: 'POST', body: { exercise_id: exerciseId, permanent } });
            window.location.reload();
        } catch (e) {
            this.error = e.data?.errors?.exercise_id?.[0] || e.message || 'Could not swap this exercise.';
            this.saving = false;
        }
    },

    async revert(itemId) {
        try {
            await window.api(`/api/plan-items/${itemId}/swap`, { method: 'DELETE' });
            window.location.reload();
        } catch (e) {
            this.error = e.message;
        }
    },
});
