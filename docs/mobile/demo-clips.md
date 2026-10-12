# Exercise demonstration clips (mobile)

**Status: pipeline built and tested; the app's video layer has never run on a phone.** The clips are *rendered* from the
website's 3D figure. They are not recordings of a person and the phone does not run a 3D model. Say so wherever they are
shown (the app captions each clip: "Animated demonstration (rendered figure, not a recording of a person)").

## Why rendered clips

The website draws its demonstrations live with Three.js (a procedural figure plus equipment, `resources/js/modules/`).
Running that on a phone would mean a second 3D engine to build and keep in step. A short MP4 loop per exercise gives the
phone the same picture with the platform's own video decoder, costs the server one small file per exercise and view, and can
be replaced from the admin without an app release (the app learns about new media from the exercise answer).

## The pipeline

```text
php artisan exercises:demo-manifest                 -> storage/app/demo-manifest.json   (published exercises, demo key, muscle regions)
node scripts/render-exercise-demos.mjs --manifest=storage/app/demo-manifest.json --out=storage/app/demo-render
php artisan exercises:import-demos storage/app/demo-render                              (registers the clips)
```

1. **Render** (`scripts/render-exercise-demos.mjs`, page script `scripts/demo-render/entry.js`). esbuild bundles the
   website's own `ExerciseViewer`; headless Chromium (software WebGL) loads it; for each exercise and camera the script sets
   the loop position `t = i / N` and the viewer draws that frame (deterministic: no clock is involved), composites it on the
   brand background and saves a JPEG. ffmpeg encodes H.264 (`yuv420p`, `+faststart`, no audio, CRF 24), one full loop, last
   frame leading back into the first. A poster JPEG is taken from the animation's own thumbnail pose. `index.json` lists each
   clip with its size, duration and SHA-256.
   * Options: `--views=angle,side,front`, `--size=640`, `--fps=30`, `--only=slug,slug`.
   * Needs Node, `npm i --no-save playwright` (or playwright-core), a Chromium and ffmpeg with libx264.
   * An exercise whose `demo_key` has **no dedicated animation is skipped** and listed under `skipped` in `index.json`: the
     viewer would draw a generic standing figure, and that must not be presented as a demonstration of the exercise.
2. **Import** (`exercises:import-demos`). Re-checks each file's size and SHA-256 against the index, stores the file on the
   private `media` disk, and registers `exercise_media` rows: `demo_video` (`video/mp4`, view, width, height, `duration_ms`,
   checksum) and a `thumbnail` poster. One clip per kind and view: a changed clip replaces the old file and row; an
   **unchanged clip is left alone** (so a re-run does not make every phone download it again). `--dry-run` reports only.
   A corrupt or edited file, an unknown exercise or an unknown view fails the command (non-zero exit) and is skipped.
3. **Serve.** The existing `GET /exercises/{slug}` already lists published media, each with a 6-hour signed URL, the
   checksum, MIME type, size and duration. A premium exercise that the member's plan does not include is a teaser with **no**
   media: the server withholds it. Nothing about the clips needed an API change.
4. **Play** (`mobile/lib/features/exercise_library/presentation/demo_clip.dart`). The exercise page shows the clip above the
   title: muted, looping, tap to pause or play. If the exercise has more than one view it offers 45° / Side / Front. With no
   clip the widget draws **nothing** (no placeholder, no fake video). The member's system "reduce motion" setting stops
   autoplay and shows the poster with a play button. The clip pauses when the app goes to the background and does not restart
   by itself. A load failure says so and offers *Try again*.

You can also upload a clip of your own for an exercise in the admin (Exercises → media): real footage replaces a rendered
loop for that exercise and view with no other change, and the app plays it the same way.

## Size and time

Measured on this repository's 85 exercises, one 640 × 640 clip per exercise at 30 fps: see the table at the end of this file
(filled in from a real run). Rendering with software WebGL takes roughly a minute per exercise; use `--only` for a subset.
Phones stream the clip from the signed URL. It is **not saved on the phone**, so with no connection the clip does not play:
the member sees the poster if the platform still has it in its image cache, otherwise a plain dark panel, and the
"could not be loaded" message with *Try again* once playback is attempted.

## How it is verified

* **PHP** (`tests/Feature/Console/DemoClipsTest.php`, 8 tests): the manifest lists only published exercises with their keys
  and regions; an import registers a clip and its poster, stores the bytes, and the exercise API returns them; a second run
  changes nothing (no new rows, no new change-feed entries); a changed clip replaces the old file and row; a file that does
  not match the index is refused; an unknown exercise fails; `--dry-run` writes nothing; a missing index stops.
* **Flutter** (`test/features/demo_clip_test.dart`, 14 tests, a fake player behind the `ClipPlayer` interface): which media
  are playable (only demo videos, one per view, ordered; thumbnails, images, instruction videos ignored); nothing drawn and no
  player created without a clip; autoplay, the honest caption, tap to pause and resume, the spinner, load failure and retry,
  reduce motion, backgrounding, switching views (the first player is released), leaving the screen (released), a late
  loader after the screen is gone (no exception), the page layout with and without a clip. Four deliberate breaks (accepting
  any media kind, not releasing the previous player, ignoring backgrounding, ignoring reduce-motion) were each caught.
* **Live contract** (CI job "App against the real API"): the server imports `tests/fixtures/demo-clips` (a 64 × 64 test pattern, **not** a
  demonstration), the app's repository reads it, the signed URL is downloaded without a token and begins with an MP4 `ftyp` box.
* **CI job "Demo clip rendering"**: renders four clips (two exercises × two cameras) from a clean machine and checks codec,
  size and pixel format with ffprobe and that each poster actually contains a drawn figure; the clips are attached as an artifact.

## Not verified

* `PlatformClipPlayer` (the thin wrapper over the `video_player` plugin) has only been compiled, never run: decoding, looping,
  muting and the aspect ratio on real Android and iOS devices are unconfirmed.
* No review of all 85 clips by eye: spot checks only. A clip is exactly what the website's viewer draws, so a flaw in a web
  demo is a flaw in its clip (the web demos were checked for equipment overlap separately).
* No offline copy, no download-ahead, no clip on the workout screen yet (the exercise page only).
