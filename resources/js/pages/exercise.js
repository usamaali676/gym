/** Exercise detail page: interactive 3D demo plus thumbnails for related exercises. */
export default async function init() {
    const { mountStage, initDemoThumbs } = await import('../modules/exercise-3d');
    initDemoThumbs(document);
    const stage = document.querySelector('.demo-stage');
    if (stage) await mountStage(stage);
}
