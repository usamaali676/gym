/** Recovery: 3D thumbnails for the generated routine (the habit tracker itself is registered globally). */
export default async function init() {
    if (!document.querySelector('.demo-thumb')) return;
    const { initDemoThumbs } = await import('../modules/exercise-3d');
    initDemoThumbs(document);
}
