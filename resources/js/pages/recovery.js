/** Recovery: habit tracker component, then 3D thumbnails for the routine. */
import habits from '../modules/habits';

let config = null;

export function register(Alpine) {
    const raw = document.getElementById('habit-config');
    if (!raw) return;

    config = JSON.parse(raw.textContent);
    Alpine.data('habits', () => habits(config));
}

export default async function init() {
    if (!document.querySelector('.demo-thumb')) return;
    const { initDemoThumbs } = await import('../modules/exercise-3d');
    initDemoThumbs(document);
}
