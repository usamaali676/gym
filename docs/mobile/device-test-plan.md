# Device test plan

**Status: not run.** Everything in this repository has been tested on a desktop test host (Flutter's widget tester), against a
local Laravel server, and in CI (which compiles the Android debug and release builds and, once it passes, the iOS build). No
one has installed the app on a phone. This plan is what has to happen before anyone relies on it. Record results in the
tables as you go; "not run" is the honest default for every row.

Devices: at least one **recent Android** (Android 14+) and one **older/low-end Android** (Android 8-10, 3 GB RAM), one
**recent iPhone** and one **older iPhone** (iOS 15.5 is the minimum). A tablet is optional (the app is portrait-only).

Build: a **release-mode build from a store test track** (Play internal testing, TestFlight), not a debug build. Server: a
staging server seeded with demo data, over HTTPS.

## 1. Smoke test (about 15 minutes)

The ordered checklist is in [release.md](release.md) section 7. Run it first; stop and fix if it fails.

## 2. Camera form coach

The full checklist is at the end of [posture-coach.md](posture-coach.md). It exists because the ML Kit layer (camera frame
format, rotation, mirroring, coordinates, likelihood versus visibility) has never executed. Record frames per second for the
front and back camera on each device; below about 10 fps the rep timing is unreliable.

## 3. Offline and flaky network

| # | Step | Expect | Result |
| --- | --- | --- | --- |
| 1 | Start a workout, airplane mode, log 3 sets, finish | sets marked "waiting to sync", honest summary without XP | not run |
| 2 | Airplane mode off | strip clears within about a minute (or tap Sync now); real summary appears; Account → Sync shows all saved | not run |
| 3 | Kill the app while sets are waiting, reopen with a connection | they are sent; nothing duplicated on the server | not run |
| 4 | Poor network (Network Link Conditioner / `adb shell` throttling, 5 % loss) during a set | no duplicate sets on the server; no frozen screen | not run |
| 5 | Sign out with changes waiting | the warning names the count; sign back in; they are sent | not run |
| 6 | Server in maintenance mode while a workout is open | "Continue my workout" works; other screens show the maintenance message | not run |
| 7 | Server requires a newer version | update-required screen; the store button opens the store page | not run |

## 4. Timers, lifecycle and the rest alert

| # | Step | Expect | Result |
| --- | --- | --- | --- |
| 1 | Start a rest, lock the phone for the whole rest, unlock | the rest is over and the next set is ready; the clock is right | not run |
| 2 | Start a rest, change the phone's clock +1 hour | no jump in the timer or the elapsed workout time | not run |
| 3 | Account → Rest timer alert on; accept the prompt; start a rest; press Home | a "Rest is over" notification appears when the rest ends (record the delay in seconds) | not run |
| 4 | As 3, but swipe the app away during the rest | the notification still appears | not run |
| 5 | As 3, with notifications denied in settings | the switch stays off and explains how to allow them | not run |
| 6 | Android 12+: exact alarms not allowed | the note appears; the alert still arrives (record how late) | not run |
| 7 | Do Not Disturb, battery saver, Doze (leave the phone idle 10 minutes before the rest ends) | record whether the alert arrives | not run |
| 8 | Phone call during a set | the workout is intact afterwards | not run |

## 5. Demonstration clips

| # | Step | Expect | Result |
| --- | --- | --- | --- |
| 1 | Open an exercise with a clip | plays muted and loops, no stutter; tap pauses and resumes | not run |
| 2 | Open an exercise with no clip | no video area, no gap | not run |
| 3 | Switch camera view, if more than one exists | the second clip loads, the first is released | not run |
| 4 | Airplane mode, open a clip | "could not be loaded", Try again works when back online | not run |
| 5 | System "reduce motion" on | the poster with a play button; no autoplay | not run |
| 6 | Home button while playing, come back | paused, still paused on return | not run |

## 6. Accessibility (the automated checks do not replace this)

* **TalkBack (Android) and VoiceOver (iOS):** sign up, onboard, start and finish a workout, log food, add a measurement,
  open the exercise page, switch the Train / Progress segments, use the Sync screen, delete a throwaway account. Note every
  control with no name, a wrong name, or that cannot be activated.
* **Largest system font and display size:** every tab, the workout screen, the forms. Note any clipped or overlapping text.
* **Contrast in sunlight and in a dark gym** (brightness at the minimum and the maximum).
* **Switch Access / Voice Control** on one device: can every button be reached?

## 6b. Visual check

Compare the screens with the website on the same account: colours, typography (Orbitron / Rajdhani / Inter), spacing. The
design tokens are exported from the website's CSS, but nobody has compared the two side by side on a phone. Check the launcher
icon, the adaptive-icon shapes (circle, squircle) and the launch screen (no white flash) on both platforms.

## 7. Performance budgets to measure

Measure on the low-end Android and the older iPhone, release mode. None of these has been measured; the targets are the
author's, not a store requirement.

| Measure | Target | How | Result |
| --- | --- | --- | --- |
| Cold start to the dashboard (warm network) | under 3 s on the low-end phone | `flutter run --release --trace-startup`, or a stopwatch | not run |
| Scrolling the exercise library (85+ items) | no visible jank; under 1 % dropped frames | Flutter DevTools performance overlay in profile mode | not run |
| Camera coach frame rate | 15 fps or more | the debug build's fps readout (see posture-coach.md) | not run |
| Memory during a 5-minute camera set | stable (no growth); under 400 MB | DevTools memory / Xcode Instruments | not run |
| Battery during a 30-minute workout with the screen on | record the percentage used | before and after | not run |
| Install size | record | CI job summary for the AAB and the per-ABI APKs; the iOS build job summary | see CI |
| Data used by an exercise page with a clip | record; expect tens of kilobytes | the OS network monitor | not run |

## 8. Security spot checks on a device

Following [security.md](security.md) "What was not done": with a proxy such as mitmproxy, confirm the app refuses the proxy's
certificate unless the proxy's root is installed; confirm no token appears in any URL or log (`adb logcat`, Xcode console);
on a rooted test phone, confirm what is readable in the app's private storage (expected: cached answers and the offline queue,
not the token).

## 9. Compatibility

Install and open on the oldest supported OS of each platform (Android 7.0, API 24; iOS 15.5). Check portrait lock, the camera
permission prompt, notifications permission prompt, and that the app is still installable on a phone without a front camera
(Play: the camera feature is optional).
