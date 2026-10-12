# Store listing: draft text and checklist

**Status: a draft written from what the code does today. Nothing has been submitted.** Replace the placeholders, have the gym
approve every claim, and change this text as features change. The rule for the listing is the rule for the app: **do not
describe anything the app does not do on a phone today.** Both stores reject, and members distrust, a listing that promises
more than the build delivers.

## Short description (Play: 80 characters)

> Your gym plan, workouts, food and progress, with a form coach in your pocket.

(If the camera coach is not enabled in the build you ship, drop "with a form coach in your pocket".)

## Full description (draft)

> **IronPulse** puts your gym's training plan and progress tracking on your phone.
>
> **Train**
> • See this week's plan and today's workout, set by your gym.
> • Run a workout: log weight and reps for each set, with a rest timer that keeps the right time even if you lock your phone.
> • Browse the exercise library with instructions, common mistakes and short animated demonstrations.
>
> **Eat**
> • Log food and see calories and protein against your targets. Every number is calculated by IronPulse, not by the app on your phone.
>
> **Progress**
> • Track weight and body measurements, personal records, streaks and badges.
>
> **Form coach (optional)**
> • Prop up your phone and the camera counts your reps and points out form mistakes. Pictures are analysed on your phone and are never recorded, stored or sent. It is a form aid, not a coach or a medical device, and it cannot judge everything a single camera can't see.
>
> **Works when the signal doesn't**
> • Sets you log without a connection are kept on your phone and sent when it's back.
>
> **Your account**
> • You can delete your account and its data from inside the app.
>
> Some features depend on your gym's membership plan. IronPulse is not a medical service; talk to a professional before starting a new programme if you have a health condition.

Do **not** add: "AI coach" (the website's optional coach is not in the app), "3D exercises" (the clips are rendered
animations, not interactive 3D), "sync with Apple Health / Google Fit" (not built), "reminders" (only the optional rest-timer
alert exists), "buy a membership in the app" (there is no purchase flow), "works offline" without the list above (food and
starting a workout need a connection).

## Metadata

| | |
| --- | --- |
| Category | Health & Fitness |
| Content rating | Everyone; no violence, no user-generated content, no ads, no purchases in the app |
| Target audience | 13+ (not designed for children). The server's sign-up asks for an age; check the minimum your jurisdiction needs |
| Contact | the gym's support email and a privacy-policy URL (**required by both stores; not created**) |
| Keywords (iOS, 100 characters) | `gym,workout,training plan,lifting,fitness log,macros,form check,rest timer,progress,strength` |
| Price | Free to download. Membership is sold by the gym (see [release.md](release.md) section 4 before saying anything else) |

## Assets still to produce

| Asset | Size | Note |
| --- | --- | --- |
| App icon | 512 × 512 (Play), 1024 × 1024 (App Store) | exports exist from `public/icons/icon-512.png` and `AppIcon.appiconset`; replace with the gym's artwork when available |
| Play feature graphic | 1024 × 500 | not made |
| Phone screenshots | Play: at least 2 (1080 × 1920 is safe); App Store: 6.9" and 6.5" sets are required | **take them from a real build on a device or emulator signed in to a demo account, not from the widget-test goldens** (those use fake data, e.g. "Exercise 1") |
| Tablet screenshots | optional on Play; required by the App Store if the iPad build is offered (the project currently lists iPad orientations) | decide whether to ship iPad |
| Short preview video | optional | the exercise clips are rendered; do not present them as footage of a person |

Suggested screenshots: today's workout, a set being logged with the rest timer, the exercise page with its demonstration,
Fuel (food log against targets), Progress (weight trend and personal records), the form coach summary, the Sync screen showing
"all saved".

## Questionnaires (inventory from the code; confirm before submitting)

* **Play Data safety, App Store Privacy:** see the table in [release.md](release.md) section 5. Notifications: the optional
  local rest alert needs the notification permission and shows the exercise name on the lock screen.
* **Play Health apps declaration / App Store health claims:** the app records workouts, nutrition and body measurements the
  member enters. It does not read sensors, HealthKit or Health Connect, and makes no diagnosis or treatment claim.
* **Exact alarms (Play):** declared for the rest-timer alert; see [notifications.md](notifications.md).
* **Account deletion:** in the app (Account → Delete account) and on the website; give both URLs where asked.
* **Reviewer access:** a demo account whose password you give in the review notes. Create it on a staging server seeded with
  demo data, never a real member.
