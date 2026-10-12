/** Admin 3D demo gallery: four poses per exercise, to review every animation at a glance. */
export default async function init() {
    const { initDemoThumbs } = await import('../modules/exercise-3d');
    initDemoThumbs(document);
}
