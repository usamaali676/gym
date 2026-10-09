/** Summary page: confetti burst and the shareable card. */
import { confetti } from '../modules/effects';

export default function init() {
    const canvas = document.getElementById('confetti');
    if (canvas) confetti(canvas, { duration: 2800, particles: 170 }).then(() => canvas.remove());

    const button = document.getElementById('share-btn');
    const status = document.getElementById('share-status');
    button?.addEventListener('click', async () => {
        button.disabled = true;
        status.textContent = 'Creating your card…';
        try {
            const { buildShareCard, shareOrDownload } = await import('../modules/share-card');
            const blob = await buildShareCard(JSON.parse(button.dataset.share));
            const result = await shareOrDownload(blob);
            status.textContent = result === 'downloaded' ? 'Saved to your downloads.' : result === 'shared' ? 'Shared!' : '';
        } catch {
            status.textContent = 'Could not create the card in this browser.';
        } finally {
            button.disabled = false;
        }
    });
}
