/** Posture Lab (live camera): define the component before Alpine starts. */
import postureLab from '../modules/posture-lab';

export function register(Alpine) {
    const raw = document.getElementById('lab-config');
    if (!raw) return;

    const config = JSON.parse(raw.textContent);
    Alpine.data('postureLab', () => postureLab(config));
}
