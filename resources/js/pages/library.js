/** Page script: render 3D pose thumbnails for the exercise cards on this page. */
export default async function init() {
    const { initDemoThumbs } = await import('../modules/exercise-3d');
    initDemoThumbs(document);
}
