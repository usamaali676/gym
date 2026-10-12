@php
    $fmtDuration = fn (int $s) => $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    $xpLabels = ['set_complete' => 'Sets completed', 'personal_record' => 'Personal records', 'workout_complete' => 'Workout bonus'];
    $title = $session->planDay?->title() ?? 'Workout';
    $share = [
        'name' => \Illuminate\Support\Str::of($user->name)->before(' ')->toString(),
        'title' => $title,
        'date' => $session->scheduled_for->format('j F Y'),
        'duration' => $fmtDuration($session->active_seconds),
        'volume' => (int) round($session->volume_kg),
        'sets' => $session->sets_completed,
        'xp' => $session->xp_earned,
        'streak' => $streak,
        'level' => $levelAfter,
        'prs' => $prs->take(3)->map(fn ($p) => $p['name'].($p['weight'] ? ' '.rtrim(rtrim(number_format($p['weight'], 2), '0'), '.').' kg x '.$p['reps'] : ''))->values(),
    ];
@endphp
<x-layouts.app title="Workout complete" page="workout-summary">
    <canvas id="confetti" class="pointer-events-none fixed inset-0 z-40 size-full" aria-hidden="true"></canvas>

    <section class="glass glass-neon rise relative overflow-hidden p-6 text-center sm:p-10">
        <div class="pointer-events-none absolute -top-24 left-1/2 size-72 -translate-x-1/2 rounded-full bg-neon/15 blur-3xl" aria-hidden="true"></div>
        <p class="eyebrow">{{ $session->scheduled_for->format('l, j F') }}</p>
        <h1 class="mt-3 text-3xl font-black uppercase text-glow sm:text-5xl">Workout complete</h1>
        <p class="mt-2 text-muted">{{ $title }}</p>

        <dl class="mx-auto mt-8 grid max-w-3xl grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="glass p-4"><dt class="label !mb-1">Duration</dt><dd class="num text-3xl text-neon">{{ $fmtDuration($session->active_seconds) }}</dd></div>
            <div class="glass p-4"><dt class="label !mb-1">Volume</dt><dd class="num text-3xl text-neon"><span data-count-to="{{ round($session->volume_kg) }}">{{ number_format(round($session->volume_kg)) }}</span><span class="text-base text-muted"> kg</span></dd></div>
            <div class="glass p-4"><dt class="label !mb-1">Exercises</dt><dd class="num text-3xl text-neon">{{ $done }}<span class="text-base text-muted">/{{ $total }}</span></dd></div>
            <div class="glass p-4"><dt class="label !mb-1">Sets</dt><dd class="num text-3xl text-neon"><span data-count-to="{{ $session->sets_completed }}">{{ $session->sets_completed }}</span></dd></div>
        </dl>
    </section>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <article class="glass rise p-5" style="--i:1" aria-labelledby="xp-title">
            <h2 id="xp-title" class="eyebrow flex items-center gap-2"><x-icon name="bolt" class="size-4"/>XP earned</h2>
            <p class="num mt-3 text-5xl text-neon text-glow">+<span data-count-to="{{ $session->xp_earned }}">{{ $session->xp_earned }}</span></p>
            <ul class="mt-3 space-y-1.5 text-sm">
                @foreach ($xpBreakdown as $type => $amount)
                    <li class="flex justify-between border-b border-line/60 pb-1.5"><span class="text-muted">{{ $xpLabels[$type] ?? $type }}</span><span class="num text-neon">+{{ $amount }}</span></li>
                @endforeach
            </ul>
            <div class="mt-4">
                <div class="flex justify-between text-xs"><span class="font-semibold">Level {{ $xp['level'] }}</span><span class="text-muted">{{ number_format($xp['into']) }} / {{ number_format($xp['needed']) }} XP</span></div>
                <div class="mt-1.5"><x-bar :value="$xp['into']" :max="$xp['needed']" label="Progress to the next level"/></div>
            </div>
            @if ($levelUp)<p class="chip chip-neon mt-4"><x-icon name="sparkles" class="size-4"/>Level up! You are now level {{ $levelAfter }}</p>@endif
        </article>

        <article class="glass rise p-5" style="--i:2" aria-labelledby="pr-title">
            <h2 id="pr-title" class="eyebrow flex items-center gap-2"><x-icon name="trophy" class="size-4"/>Personal records</h2>
            @forelse ($prs as $pr)
                <div class="mt-3 rounded-xl border border-line-strong bg-neon/5 p-3">
                    <p class="font-bold">{{ $pr['name'] }}</p>
                    <p class="num text-neon">{{ $pr['weight'] ? rtrim(rtrim(number_format($pr['weight'], 2), '0'), '.').' kg × '.$pr['reps'] : $pr['reps'].' reps' }}</p>
                    @if ($pr['previous'])<p class="text-xs text-muted">Previous best (est. 1RM): {{ rtrim(rtrim(number_format($pr['previous'], 1), '0'), '.') }} kg</p>@endif
                </div>
            @empty
                <p class="mt-3 text-sm text-muted">No new records this time. Beat last week's numbers next session and they will come.</p>
            @endforelse
            @if ($badges->isNotEmpty())
                <h3 class="eyebrow mt-5 flex items-center gap-2"><x-icon name="star" class="size-4"/>New badges</h3>
                <ul class="mt-2 flex flex-wrap gap-2">@foreach ($badges as $b)<li class="chip chip-neon"><x-icon :name="$b->icon" class="size-4"/>{{ $b->name }}</li>@endforeach</ul>
            @endif
        </article>

        <article class="glass rise p-5" style="--i:3" aria-labelledby="streak-title">
            <h2 id="streak-title" class="eyebrow flex items-center gap-2"><x-icon name="flame" class="size-4"/>Streak</h2>
            <p class="num mt-3 text-5xl text-neon text-glow"><span data-count-to="{{ $streak }}">{{ $streak }}</span> <span class="text-lg text-muted">{{ \Illuminate\Support\Str::plural('day', $streak) }}</span></p>
            <p class="mt-1 text-sm text-muted">Rest days never break it. Keep showing up on your training days.</p>
            <div class="mt-5 flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" id="share-btn" data-share="{{ json_encode($share) }}"><x-icon name="share" class="size-4"/>Share card</button>
                <a class="btn btn-ghost" href="{{ route('dashboard') }}">Dashboard</a>
            </div>
            <p id="share-status" class="mt-2 text-xs text-muted" role="status"></p>
        </article>
    </div>

    <section class="glass rise mt-4 p-5" style="--i:4" aria-labelledby="log-title">
        <h2 id="log-title" class="eyebrow">Your sets and next targets</h2>
        <ul class="mt-3 divide-y divide-line/60">
            @foreach ($exercises as $ex)
                <li class="py-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="font-bold">{{ $ex['name'] }} @if ($ex['status'] === 'skipped')<span class="chip chip-danger !text-[0.65rem]">Skipped</span>@endif</p>
                        <p class="num text-sm text-neon">
                            @forelse ($ex['sets'] as $set){{ $loop->first ? '' : ' · ' }}{{ $set->reps !== null ? ($set->weight ? rtrim(rtrim(number_format($set->weight, 2), '0'), '.').' × '.$set->reps : 'BW × '.$set->reps) : $set->duration_seconds.' s' }}@if ($set->is_pr) <x-icon name="star" class="inline size-3"/>@endif @empty <span class="text-muted">no sets</span> @endforelse
                        </p>
                    </div>
                    @if ($ex['target'])
                        <p class="mt-1 text-xs text-muted"><span class="font-semibold text-ink">Next time:</span> {{ $ex['target']->target_weight_kg ? rtrim(rtrim(number_format($ex['target']->target_weight_kg, 2), '0'), '.').' kg × ' : '' }}{{ $ex['target']->target_reps }} reps. {{ $ex['target']->reason }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
</x-layouts.app>
