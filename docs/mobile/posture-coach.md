# Camera form coach (mobile)

**Status: built and tested without a camera. Never run on a phone.** The rule engine, rep counting, form checks, session
screens and upload are implemented and verified against the website's engine and a fake camera. The one piece that
touches real hardware, the camera plus Google ML Kit pose model, compiles and is analysed in CI but has not been
executed by anyone. Treat the first real-device session as a test of that layer, using the checklist at the end.

## What it does

The member picks an exercise the server has a rule for, films themselves with the phone, and the app counts reps (or times
a hold), flags form mistakes with a cue and a red bone on the skeleton, and shows a summary. Only the numbers of a finished
set (counts, scores, error ids, per-rep log) are sent to the server. **Camera pictures and landmarks are never recorded,
stored or sent**; the pose model runs on the phone.

This is a form-awareness aid: a single phone camera sees in 2D and cannot judge depth or rotation, and the rule thresholds
have not been validated with real lifters. The app says so on the intro screen ("How much to trust it") together with the
rule's own accuracy note from the server.

## Pipeline

```text
camera frame ──► ML Kit pose (33 landmarks, on device)          lib/features/posture/data/ml_kit_pose_capture.dart   NOT RUN ON A DEVICE
             ──► PoseFrame {landmarks, time, aspect}             data/pose_capture.dart (the seam tests replace)
             ──► One-Euro smoothing                              domain/one_euro_filter.dart                           ported + verified
             ──► measures (angles, inclinations, offsets …)      domain/rule_engine.dart                               ported + verified
             ──► rep / hold state machine                         domain/rep_counter.dart                               ported + verified
             ──► debounced form checks, score, bones to colour    domain/posture_analyzer.dart                          ported + verified
             ──► session state machine + HUD + skeleton overlay  presentation/posture_coach.dart, posture_session_screen.dart
             ──► POST /posture/sessions (numbers only)            data/posture_repository.dart
```

The **rules are data from the server** (`GET /posture/rules/{exercise}`, schema 1). The app contains no exercise-specific
logic; adding or changing a rule in the admin needs no app release. A rule with a newer schema than the app understands
is refused with "needs a newer version of the app"; an invalid rule says it cannot be used. Rules are cached, so a set can
start offline once the rule has been fetched.

## How the Dart port is verified

The engine is a line-for-line port of `resources/js/modules/{geometry,one-euro-filter,rule-engine,rep-counter,posture-analyzer}.js`.

* `scripts/export-posture-parity.mjs` runs the **JavaScript** engine over 15 deterministic scenarios (2,029 frames of synthetic
  stick-figure poses: clean and flawed squats, mirrored and portrait-framed, curls with swing and drift, a press with
  phase-limited checks, lateral raises with uneven arms and shrugging, fast jumping jacks, a plank that sags, a plank too
  short to count, nobody in view, hidden joints, walking out of frame, random landmarks) and records every frame's answer and
  each session summary. It also records the message list `validateRule()` gives for 68 deliberately broken rule configs.
  Output: `mobile/test/fixtures/posture_parity.json.gz` (313 KB).
* `mobile/test/features/posture/parity_test.dart` replays the same landmarks through the Dart port and compares status,
  phase, counts, side, facing, score, missing/hidden joints, active checks, red bones, events, the last rep and the final
  summary, with a 1e-6 tolerance on floating-point values (the two runtimes' maths libraries can differ in the last bit). It
  also checks that all 35 shipped rule files validate and compile, and that the 68 validation messages are identical.
  **120 tests, all passing.**
* **Mutation check:** four deliberate breaks of the Dart code (a rep-counter margin, the check-clearing debounce, the
  inclination formula, a validation message) each made the parity test fail (8, 3, 10 and 1 tests); restoring the code made
  it pass again.
* CI job "JavaScript" runs `node scripts/export-posture-parity.mjs --check`, so changing the web engine or a rule without
  regenerating the fixture fails the build, and the Dart tests then fail until the port is updated.
* `test/live/live_api_test.dart` fetches **every rule the real server serves**, compiles it with the Dart engine, runs the
  squat scenario with the server's own squat rule, and saves the resulting summary with the real validator (`POST
  /posture/sessions` → 201, and again → 200 "not created", so a retry cannot duplicate a set).

What this proves: the Dart engine does what the web engine does. What it does not prove: that either engine behaves well
on a real person filmed by a real phone (the inputs are stick figures).

## Screens and their tests

`test/features/posture/posture_flow_test.dart` (23 widget tests) drives the real screens with a fake camera that replays the
recorded frames:

* entry points (Account → Camera form coach; "Check my form" on an exercise that supports it; locked plan; unusable rule)
* intro (how to film, what it watches, how far to trust it, privacy; the Start button stays on screen)
* camera errors (permission blocked → message and retry; model cannot start)
* positioning ("I can't see you", "Move so I can see your left ankle…", steady pose → 3-2-1 countdown → live; losing the
  person cancels the countdown; "Start anyway" after 6 s; switching camera releases the first camera before opening the second)
* live set (three clean squats counted and judged good; flawed squats flagged with the rule's own words and haptics; pause
  stops counting; leaving mid-set asks first; backgrounding the app releases the camera, pauses the set and re-opens the
  camera on return)
* saving (the exact numbers sent; the same upload id on every attempt; offline and rejected sets stay on screen with a retry;
  leaving before an unsaved set is saved asks first; "Another set" starts clean)
* a timed plank (hold seconds, sag flagged)

Mutation check: breaking the retry id, the pause, the camera release and the end-of-set flush each made a test fail.
Goldens (`test/goldens/posture_*.png`) show the intro, a live set with the skeleton (including red bones and a cue after a
bad rep) and both summaries; reading them found and fixed a wrapped HUD label.
`ml_kit_mapping_test.dart` (12 tests) checks the pure mapping between ML Kit's output and the engine's landmarks, including
that ML Kit's 33 landmark names line up one-to-one with the rule engine's indices.

## Platform setup

| | |
| --- | --- |
| Android | `CAMERA` permission; camera and front camera declared `required="false"` (phones without one can still install the app). `minSdk` is Flutter's default (24), above the plugins' 21. ML Kit's pose model is bundled with the plugin, so there is no model download. |
| iOS | `NSCameraUsageDescription` set. **Minimum iOS 15.5** (ML Kit): the Xcode project and `ios/Podfile` say so. ML Kit has no 32-bit slices. If a simulator build on Apple Silicon fails to link ML Kit, test on a device. `ios/Podfile` is Flutter's template with the platform line set; it has never been run through CocoaPods (no macOS here). |
| Orientation | The app is portrait-only; the camera layer assumes portrait. |

## Known limitations

1. **Device layer unverified** (the reason for this document's status line). In particular unconfirmed: the rotation to give
   ML Kit on each platform and device orientation; whether Android's returned coordinates are in the rotated (portrait) image
   as `ml_kit_mapping.dart` assumes (this follows the plugin's example app); whether iOS frames arrive upright; that the
   skeleton lines up with the mirrored selfie preview; the NV21 / BGRA frame formats; frame rate and battery use.
2. **ML Kit's `likelihood` is not MediaPipe's `visibility`.** ML Kit reports the probability a joint is *inside the frame*,
   the web's model reports whether it is *visible* (not occluded). The rules' `min_visibility` thresholds were chosen for
   the second. The coach may therefore under-warn about joints hidden behind the body or equipment ("Move so I can see your
   ankle" appears less often than on the web). This needs real-device tuning, possibly per-rule.
3. **Rules are unvalidated** against real lifters on any platform (also true of the web).
4. **No spoken cues or audio tones** (the web speaks cues and plays tones). The app shows the cue text and uses haptics.
5. **Saving needs a connection.** A set that cannot be saved stays on screen with "Try saving again"; the offline queue
   (`POST /sync/batch` already accepts posture sessions) is Phase 6.
6. A set started from a workout in progress is supported by the route (`/posture/{slug}?workout={id}` sets
   `workout_session_id`) but the workout screen has no button for it yet.
7. One person in frame; a second person can steal the tracking (ML Kit returns one pose in this configuration).
8. The pose model is ML Kit's base streaming model; the web uses MediaPipe's full/lite/heavy variants. `model_variant` is not
   sent.
9. APK size and cold-start cost of ML Kit were not measured; read them from the CI artifact.

## First real-device checklist

Run on at least one Android phone and one iPhone, a recent and an older one if possible. Prop the phone about 2.5 m away at
hip height, side-on, whole body in frame.

- [ ] First launch of the coach: the OS camera prompt appears; allow → preview shows. Deny → the "permission was blocked" screen;
      enable in Settings, return → **Try again** works.
- [ ] The picture is upright in portrait on both cameras; the skeleton sits on the body (raise your left hand: the matching side
      moves on screen, for the front and the back camera).
- [ ] The debug build shows an fps figure bottom-left of the picture: note it for the front and back camera. Below ~10 fps, rep
      timing will be unreliable.
- [ ] Stand in the start position: "Ready" → countdown → live. Do 5 bodyweight squats: the count is 5.
- [ ] Do 3 deliberately shallow squats: "Squat deeper…"; lean forward a lot: the torso bones turn red.
- [ ] Walk out of frame mid-rep: "Lost sight of you", nothing counted. Step back in: counting resumes.
- [ ] Cover an ankle with the other leg or a bench: check whether the app notices (limitation 2).
- [ ] Press the home button during a set: on return the set is paused and the camera comes back; **Resume** carries on.
- [ ] Switch cameras (if shown). Rotate the phone: the screen stays portrait.
- [ ] Finish a set: the summary matches what you did; the web's "Posture Lab" history shows the same numbers.
- [ ] Plank: the hold timer starts after a moment in position, stops when you drop, and a short hold is not counted.
- [ ] Dim room, a patterned background, loose clothing, a second person passing: note what breaks.
- [ ] Record the APK/IPA size increase and the time from tapping Start camera to the first frame.
