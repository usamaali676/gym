@php
    $tierMeta = [
        'full' => ['Full-rule lifts', 'Free-weight lifts with the most complete checks: joint angles, torso position and bar-path cues.', 'chip-neon'],
        'simplified' => ['Machines and cables', 'Simpler rules: rep counting, range of motion and whether your torso stays still. The machine hides parts of you from the camera.', ''],
        'generic' => ['Warm-up helpers', 'Quick generic checks for bodyweight moves.', ''],
    ];
@endphp
<x-layouts.app title="Posture Lab" page="library">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="eyebrow">AI Posture Lab</p>
            <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">Check your form with your camera</h1>
            <p class="mt-1 max-w-2xl text-sm text-muted">A pose model runs in your browser, draws your skeleton, turns the bones red where your form drifts and counts clean reps. Your camera feed stays on this device: nothing is recorded or uploaded, only a numeric summary of each set is saved.</p>
        </div>
    </div>

    @unless ($assetsReady)
        <div class="mb-6 rounded-2xl border border-danger/40 bg-danger/10 p-4 text-sm" role="alert">
            <p class="flex items-center gap-2 font-semibold"><x-icon name="alert" class="size-5 text-danger"/>The pose model files are not installed on this server</p>
            <p class="mt-1 text-muted">An admin needs to run <code class="rounded bg-black/40 px-1.5 py-0.5 text-ink">npm run vendor:mediapipe</code> once. See the README, section "Posture Lab assets". Until then the Lab pages explain how to set up but cannot start the camera.</p>
        </div>
    @endunless

    <dl class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4" aria-label="Last 30 days">
        @foreach ([['Sets', $stats['sets'], 'repeat'], ['Clean reps', $stats['good_reps'], 'check'], ['Reps checked', $stats['total_reps'], 'target'], ['Avg form score', $stats['sets'] ? $stats['avg_score'] : '-', 'star']] as [$label, $value, $icon])
            <div class="glass p-4">
                <dt class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted"><x-icon :name="$icon" class="size-4 text-neon"/>{{ $label }}</dt>
                <dd class="num mt-1 text-2xl text-ink">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($today->isNotEmpty())
        <section class="mb-8" aria-labelledby="today-title">
            <h2 id="today-title" class="mb-3 flex items-center gap-2 text-lg font-bold uppercase"><x-icon name="target" class="size-5 text-neon"/>Today's lifts</h2>
            <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($today as $rule)
                    @include('posture._card', ['rule' => $rule, 'highlight' => true])
                @endforeach
            </ul>
        </section>
    @endif

    @foreach ($tierMeta as $key => [$title, $blurb, $chip])
        @continue($tiers[$key]->isEmpty())
        <section class="mb-8" aria-labelledby="tier-{{ $key }}">
            <h2 id="tier-{{ $key }}" class="text-lg font-bold uppercase">{{ $title }} <span class="num ml-1 text-sm text-muted">{{ $tiers[$key]->count() }}</span></h2>
            <p class="mb-3 mt-1 max-w-3xl text-sm text-muted">{{ $blurb }}</p>
            <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($tiers[$key] as $rule)
                    @include('posture._card', ['rule' => $rule, 'highlight' => false])
                @endforeach
            </ul>
        </section>
    @endforeach

    <section class="glass p-5" aria-labelledby="recent-title">
        <h2 id="recent-title" class="flex items-center gap-2 text-lg font-bold uppercase"><x-icon name="clock" class="size-5 text-neon"/>Recent sets</h2>
        @forelse ($recent as $s)
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line py-3 last:border-0">
                <div class="min-w-0">
                    <a href="{{ route('posture.show', $s->exercise) }}" class="font-semibold hover:text-neon">{{ $s->exercise->name }}</a>
                    <p class="text-xs text-muted">{{ $s->started_at->diffForHumans() }} · {{ $s->hold_seconds !== null && $s->hold_seconds > 0 ? $s->hold_seconds.' s hold' : $s->good_reps.' of '.$s->total_reps.' reps clean' }}</p>
                </div>
                <span class="chip {{ $s->form_score_avg >= 80 ? 'chip-neon' : ($s->form_score_avg < 60 ? 'chip-danger' : '') }}"><span class="num text-sm">{{ $s->form_score_avg }}</span> form score</span>
            </div>
        @empty
            <p class="mt-3 text-sm text-muted">No sets yet. Pick an exercise above, set up your camera and try your first one.</p>
        @endforelse
    </section>
</x-layouts.app>
