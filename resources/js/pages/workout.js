/** Game mode: define the workout component before Alpine starts. */
import workoutGame from '../modules/workout-session';

export function register(Alpine) {
    const raw = document.getElementById('game-config');
    if (!raw) return;

    const config = JSON.parse(raw.textContent);
    Alpine.data('workoutGame', () => workoutGame(config));
}

export default function init() {
    document.getElementById('game-loading')?.remove();
}
