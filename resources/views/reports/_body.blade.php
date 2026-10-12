@php
    $t = $r['training'];
    $dayClass = [
        'completed' => 'border-neon bg-neon/20 text-neon',
        'skipped' => 'border-amber-400/50 bg-amber-400/10 text-amber-300',
        'missed' => 'border-danger/50 bg-danger/10 text-[#ff8a8a]',
        'in_progress' => 'border-neon/50 text-neon',
        'today' => 'border-neon/50 text-ink',
        'rest' => 'border-line text-muted',
        'upcoming' => 'border-line text-muted',
    ];
    $dayText = ['completed' => 'Done', 'skipped' => 'Skipped', 'missed' => 'Missed', 'in_progress' => 'Started', 'today' => 'Today', 'rest' => 'Rest', 'upcoming' => 'Ahead'];
    $typeLabel = ['est_1rm' => 'est. 1RM', 'max_weight' => 'heaviest', 'max_reps' => 'most reps', 'best_set_volume' => 'best set'];
    $kg = fn ($v) => rtrim(rtrim(number_format($v, 1), '0'), '.');
    $private = ! ($public ?? false);
@endphp

<div class="space-y-4">
    <section class="glass glass-neon p-5" aria-labelledby="rep-training">
        <h2 id="rep-training" class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="dumbbell" class="size-4 text-neon"/>Training</h2>
        <div class="mt-3 flex flex-wrap items-end gap-x-6 gap-y-2">
            <p><span class="num text-4xl text-neon">{{ $t['completed'] }}</span><span class="num text-xl text-muted"> / {{ $t['planned'] }}</span> <span class="text-sm text-muted">planned sessions{{ $t['extra'] ? ' (+'.$t['extra'].' extra)' : '' }}</span></p>
        </div>
        <ol class="mt-4 grid grid-cols-7 gap-1.5 sm:gap-2" aria-label="Each day of the week">
            @foreach ($t['days'] as $d)
                <li class="rounded-xl border px-1 py-2 text-center {{ $dayClass[$d['status']] ?? 'border-line' }}">
                    <p class="text-[0.65rem] font-semibold uppercase tracking-wider">{{ $d['short'] }}</p>
                    <p class="mt-1 truncate text-[0.65rem]">{{ $dayText[$d['status']] ?? '' }}</p>
                    <p class="sr-only">{{ $d['label'] }}</p>
                </li>
            @endforeach
        </ol>
        <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-[0.65rem] uppercase tracking-wider text-muted">Volume</dt><dd class="num mt-1 text-xl">{{ number_format($t['volume_kg']) }}<span class="text-xs text-muted"> kg</span></dd></div>
            <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-[0.65rem] uppercase tracking-wider text-muted">Sets</dt><dd class="num mt-1 text-xl">{{ $t['sets'] }}</dd></div>
            <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-[0.65rem] uppercase tracking-wider text-muted">Time training</dt><dd class="num mt-1 text-xl">{{ $t['minutes'] }}<span class="text-xs text-muted"> min</span></dd></div>
            <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-[0.65rem] uppercase tracking-wider text-muted">Workout streak</dt><dd class="num mt-1 text-xl">{{ $r['streak'] }}</dd></div>
        </dl>
    </section>

    @if ($r['records'])
        <section class="glass p-5" aria-labelledby="rep-prs">
            <h2 id="rep-prs" class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="trophy" class="size-4 text-neon"/>Personal records</h2>
            <ul class="mt-3 divide-y divide-line">
                @foreach ($r['records'] as $pr)
                    <li class="flex items-baseline justify-between gap-3 py-2 text-sm">
                        <span class="min-w-0 truncate font-semibold">{{ $pr['exercise'] }}</span>
                        <span class="shrink-0 text-muted"><strong class="num text-ink">{{ $kg($pr['value']) }}</strong> {{ $pr['type'] === 'max_reps' ? 'reps' : 'kg' }} <span class="text-xs">({{ $typeLabel[$pr['type']] ?? $pr['type'] }}@if($pr['previous']), was {{ $kg($pr['previous']) }}@endif)</span></span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if (($r['posture']['sessions'] ?? 0) > 0)
        <section class="glass p-5" aria-labelledby="rep-posture">
            <h2 id="rep-posture" class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="scan" class="size-4 text-neon"/>Posture Lab</h2>
            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-[0.65rem] uppercase tracking-wider text-muted">Sets checked</dt><dd class="num mt-1 text-xl">{{ $r['posture']['sessions'] }}</dd></div>
                <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-[0.65rem] uppercase tracking-wider text-muted">Average form</dt><dd class="num mt-1 text-xl">{{ $r['posture']['avg_score'] }}<span class="text-xs text-muted"> /100</span></dd></div>
                <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-[0.65rem] uppercase tracking-wider text-muted">Good reps</dt><dd class="num mt-1 text-xl">{{ $r['posture']['good_reps'] }}<span class="text-xs text-muted"> / {{ $r['posture']['total_reps'] }}</span></dd></div>
                @if ($r['posture']['best'])<div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-[0.65rem] uppercase tracking-wider text-muted">Best set</dt><dd class="mt-1 truncate text-sm font-semibold">{{ $r['posture']['best']['exercise'] }}</dd><dd class="num text-xs text-neon">{{ $r['posture']['best']['score'] }}/100</dd></div>@endif
            </dl>
        </section>
    @endif

    @if ($private)
        <section class="glass p-5" aria-labelledby="rep-habits">
            <h2 id="rep-habits" class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="moon" class="size-4 text-neon"/>Food, water, sleep and body</h2>
            <dl class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-3">
                @if ($r['nutrition'] && $r['nutrition']['days_logged'])
                    <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-[0.65rem] uppercase tracking-wider text-muted">Calories (avg of logged days)</dt><dd class="num mt-1 text-xl">{{ number_format($r['nutrition']['avg_kcal']) }}@if($r['nutrition']['target_kcal'])<span class="text-xs text-muted"> / {{ number_format($r['nutrition']['target_kcal']) }}</span>@endif</dd><dd class="text-xs text-muted">{{ $r['nutrition']['days_logged'] }} of {{ $r['nutrition']['days_tracked'] }} days logged</dd></div>
                    <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-[0.65rem] uppercase tracking-wider text-muted">Protein (avg)</dt><dd class="num mt-1 text-xl">{{ $r['nutrition']['avg_protein_g'] }}<span class="text-xs text-muted"> g @if($r['nutrition']['target_protein_g']) / {{ $r['nutrition']['target_protein_g'] }}@endif</span></dd>@if($r['nutrition']['has_plan'])<dd class="text-xs text-muted">On target {{ $r['nutrition']['protein_days'] }} of {{ $r['nutrition']['days_logged'] }} days</dd>@endif</div>
                @else
                    <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-[0.65rem] uppercase tracking-wider text-muted">Food log</dt><dd class="mt-1 text-sm text-muted">Nothing logged this week.</dd></div>
                @endif
                @if ($r['water'])<div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-[0.65rem] uppercase tracking-wider text-muted">Water goal hit</dt><dd class="num mt-1 text-xl">{{ $r['water']['days_hit'] }}<span class="text-xs text-muted"> / {{ $r['water']['days_tracked'] }} days</span></dd><dd class="text-xs text-muted">Average {{ number_format($r['water']['avg_ml']) }} ml a day</dd></div>@endif
                @if ($r['creatine'])<div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-[0.65rem] uppercase tracking-wider text-muted">Creatine</dt><dd class="num mt-1 text-xl">{{ $r['creatine']['days_taken'] }}<span class="text-xs text-muted"> / {{ $r['creatine']['days_tracked'] }} days</span></dd></div>@endif
                @if ($r['sleep'])<div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-[0.65rem] uppercase tracking-wider text-muted">Sleep</dt><dd class="num mt-1 text-xl">{{ $r['sleep']['avg_hours'] ?? '-' }}<span class="text-xs text-muted"> h avg</span></dd><dd class="text-xs text-muted">{{ $r['sleep']['nights_logged'] }} nights logged, goal {{ $r['sleep']['goal'] }} h</dd></div>@endif
                <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-[0.65rem] uppercase tracking-wider text-muted">Body weight</dt><dd class="num mt-1 text-xl">{{ $r['body']['weight_kg'] !== null ? $kg($r['body']['weight_kg']) : '-' }}<span class="text-xs text-muted"> kg</span></dd><dd class="text-xs text-muted">@if($r['body']['change_kg'] !== null){{ $r['body']['change_kg'] > 0 ? '+' : '' }}{{ $kg($r['body']['change_kg']) }} kg this week @else No weigh-in to compare @endif</dd></div>
            </dl>
        </section>
    @endif

    <section class="glass p-5" aria-labelledby="rep-xp">
        <h2 id="rep-xp" class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="bolt" class="size-4 text-neon"/>XP and badges</h2>
        <p class="mt-2 text-sm"><strong class="num text-neon">+{{ number_format($r['xp']['earned']) }} XP</strong> this week · level <span class="num">{{ $r['xp']['level'] }}</span></p>
        @if ($r['badges'])
            <ul class="mt-3 flex flex-wrap gap-2">@foreach ($r['badges'] as $b)<li class="chip">{{ $b['name'] }}</li>@endforeach</ul>
        @endif
    </section>

    @if ($r['highlights'])
        <section class="glass p-5" aria-labelledby="rep-high">
            <h2 id="rep-high" class="text-sm font-bold uppercase">Highlights</h2>
            <ul class="mt-3 space-y-2 text-sm">@foreach ($r['highlights'] as $h)<li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-neon"/>{{ $h }}</li>@endforeach</ul>
        </section>
    @endif

    @if ($private && ! empty($r['focus']))
        <section class="glass p-5" aria-labelledby="rep-focus">
            <h2 id="rep-focus" class="text-sm font-bold uppercase">Focus for next week</h2>
            <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm marker:text-neon">@foreach ($r['focus'] as $f)<li>{{ $f }}</li>@endforeach</ol>
        </section>
    @endif
</div>
