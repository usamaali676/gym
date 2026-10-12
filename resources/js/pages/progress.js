/** Progress page: weight chart, strength curves and the photo comparison. */
let config = null;

export function register(Alpine) {
    const raw = document.getElementById('progress-config');
    if (!raw) return;
    config = JSON.parse(raw.textContent);

    Alpine.data('progressPage', () => {
        let chart = null;

        return {
            exercises: config.exercises,
            slug: config.exercises[0]?.slug ?? null,
            loading: false,
            empty: false,
            compareA: '',
            compareB: '',

            async init() {
                if (this.slug) await this.loadStrength();
            },

            async loadStrength() {
                if (!this.slug) return;
                this.loading = true;
                try {
                    const res = await window.api(`${config.strengthUrl}/${this.slug}`);
                    this.empty = res.series.length === 0;
                    const { strengthChart, strengthData } = await import('../modules/charts');
                    const canvas = document.getElementById('strength-chart');
                    if (!chart) {
                        chart = await strengthChart(canvas, res.series);
                    } else {
                        chart.data = strengthData(res.series);
                        chart.update();
                    }
                } catch {
                    this.empty = true;
                } finally {
                    this.loading = false;
                }
            },
        };
    });
}

export default async function init() {
    const canvas = document.getElementById('weight-chart');
    if (!canvas || !config || config.weight.length < 2) return;

    const { weightChart } = await import('../modules/charts');
    await weightChart(canvas, config.weight, config.target);
}
