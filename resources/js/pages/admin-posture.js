/** Admin posture rule editor: live validation with the same rule engine the Posture Lab runs. */
import { validateRule } from '../modules/rule-engine';

export function register(Alpine) {
    Alpine.data('postureEditor', () => ({
        state: 'idle',
        errors: [],

        init() {
            this.check(this.$el.querySelector('textarea[name="config_json"]').value);
        },

        check(text) {
            let parsed;
            try {
                parsed = JSON.parse(text);
            } catch (e) {
                this.state = 'bad';
                this.errors = [`Not valid JSON: ${e.message}`];
                return;
            }
            const errors = validateRule(parsed);
            this.errors = errors;
            this.state = errors.length ? 'bad' : 'ok';
        },
    }));
}
