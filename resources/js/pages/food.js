/** Food log: the component, then the history chart once the page is live. */
import foodLog from '../modules/food-log';

let config = null;

export function register(Alpine) {
    const raw = document.getElementById('food-config');
    if (!raw) return;

    config = JSON.parse(raw.textContent);
    Alpine.data('foodLog', () => foodLog(config));
}

export default async function init() {
    const canvas = document.getElementById('nutrition-chart');
    if (!canvas || !config) return;

    const { nutritionChart } = await import('../modules/charts');
    await nutritionChart(canvas, config.history);
}
