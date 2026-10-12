/** Diet page: the meal-plan component. */
import mealPlan from '../modules/meal-plan';

export function register(Alpine) {
    const raw = document.getElementById('meal-plan-config');
    if (!raw) return;

    const config = JSON.parse(raw.textContent);
    Alpine.data('mealPlan', () => mealPlan(config));
}
