# IronPulse

A Laravel gym web app: your push / pull / legs plan as a guided, game-like training experience,
an on-device AI posture checker, and diet / macro tools. Dark and neon green, mobile-first.

> Work in progress. The full README (install, MediaPipe assets, deployment) lands in the final phase.
> The plan, schema and phase breakdown are in [`docs/PHASE-0-PLAN.md`](docs/PHASE-0-PLAN.md).

## Quick start (development)

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

Seeded accounts (password from `SEED_PASSWORD`, default `password`):

| Account | Notes |
|---|---|
| `mujtaba@example.com` | The profile from the plan PDF. The onboarding wizard opens pre-filled and asks for the few answers the PDF does not contain (sex, activity level, fitness level, diet preference, equipment). |
| `admin@example.com` | Fully onboarded, with access to the admin panel. |

Run the tests with `php artisan test`.
