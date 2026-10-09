@php
    $start = route_if('workout.start');
    $planUrl = route_if('plan.index');
    $dayUrl = $day ? route_if('plan.day', ['day' => $day->day_of_week]) : null;
    $postureUrl = route_if('posture.index');
    $foodUrl = route_if('food.index');
    $dietUrl = route_if('diet.index');
    $isTraining = $day && ! $day->is_rest && in_array($todayStatus, ['today', 'in_progress', 'completed', 'skipped', 'missed'], true);
    $kcalTarget = $targets['calories'] ?? null;
    $proteinTarget = $targets['protein_g'] ?? null;
@endphp
<x-layouts.app title="Dashboard" page="dashboard">
    {{-- Greeting --}}
    <section class="rise mb-6">
        <p class="eyebrow">{{ $now->format('l, j F') }}</p>
        <h1 class="mt-2 text-2xl font-black uppercase sm:text-4xl">{{ $greeting }}, <span class="text-neon text-glow">{{ \Illuminate\Support\Str::of($user->name)->before(' ') }}</span></h1>
        <p class="mt-2 max-w-2xl text-sm text-muted sm:text-base">“{{ $quote }}”</p>
    </section>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {{-- Today's workout --}}
        <article class="glass glass-neon rise relative overflow-hidden p-5 sm:p-7 lg:col-span-2" style="--i:1" aria-labelledby="today-title">
            <div class="pointer-events-none absolute -right-16 -top-16 size-64 rounded-full bg-neon/10 blur-3xl" aria-hidden="true"></div>
            @if (! $day)
                <p class="eyebrow">Today</p>
                <h2 id="today-title" class="mt-2 text-xl font-black uppercase">No plan assigned yet</h2>
                <p class="mt-2 text-sm text-muted">An admin needs to seed the workout plan (<code>php artisan db:seed</code>).</p>
            @elseif (! $isTraining)
                <p class="eyebrow">Today · Recovery</p>
                <h2 id="today-title" class="mt-2 text-2xl font-black uppercase sm:text-3xl">Rest day</h2>
                <p class="mt-3 max-w-lg text-muted">Muscle is built during recovery, not in the gym. Eat enough protein, take your creatine, stretch, and aim for 7 to 8 hours of sleep tonight.</p>
                <div class="mt-5 flex flex-wrap gap-2">
                    @if (route_if('recovery.index'))<a class="btn btn-primary" href="{{ route('recovery.index') }}"><x-icon name="moon" class="size-4"/>Recovery routine</a>@endif
                    @if ($foodUrl)<a class="btn btn-ghost" href="{{ $foodUrl }}"><x-icon name="apple" class="size-4"/>Log food</a>@endif
                    @if ($postureUrl)<a class="btn btn-ghost" href="{{ $postureUrl }}"><x-icon name="scan" class="size-4"/>Posture Lab</a>@endif
                </div>
            @else
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="eyebrow">Today · {{ $day->name }}</p>
                        <h2 id="today-title" class="mt-2 text-3xl font-black uppercase sm:text-5xl">{{ $day->label }} @if ($day->focusLabel())<span class="text-neon text-glow">{{ $day->focusLabel() }}</span>@endif</h2>
                    </div>
                    @if ($todayStatus === 'completed')
                        <span class="chip chip-neon"><x-icon name="check" class="size-4"/>Completed</span>
                    @elseif ($todayStatus === 'skipped')
                        <span class="chip chip-danger">Skipped</span>
                    @endif
                </div>
                <ul class="mt-4 flex flex-wrap gap-2 text-sm">
                    <li class="chip"><x-icon name="list" class="size-4"/>{{ $day->items->count() }} exercises</li>
                    <li class="chip"><x-icon name="repeat" class="size-4"/>{{ $day->totalSets() }} sets</li>
                    <li class="chip"><x-icon name="clock" class="size-4"/>about {{ $dayMinutes }} min</li>
                </ul>
                <ol class="mt-5 grid grid-cols-1 gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
                    @foreach ($day->items->take(6) as $item)
                        <li class="flex items-baseline justify-between gap-3 border-b border-line/60 py-1.5">
                            <span class="truncate">{{ $item->displayName() }}</span>
                            <span class="num shrink-0 text-sm text-neon">{{ $item->sets }}×{{ $item->repsLabel() }}</span>
                        </li>
                    @endforeach
                    @if ($day->items->count() > 6)
                        <li class="py-1.5 text-xs text-muted">+ {{ $day->items->count() - 6 }} more exercises</li>
                    @endif
                </ol>
                <div class="mt-6 flex flex-wrap items-center gap-3">
                    @if ($openSession && $start)
                        <a class="btn btn-primary btn-lg" href="{{ $start }}"><x-icon name="play" class="size-5"/>Resume workout</a>
                    @elseif ($todayStatus === 'completed')
                        @if ($dayUrl)<a class="btn btn-ghost btn-lg" href="{{ $dayUrl }}">Review today</a>@endif
                    @elseif ($start)
                        <a class="btn btn-primary btn-lg" href="{{ $start }}"><x-icon name="play" class="size-5"/>Start workout</a>
                    @endif
                    @if ($dayUrl && $todayStatus !== 'completed')<a class="btn btn-ghost" href="{{ $dayUrl }}">View day <x-icon name="chevron-right" class="size-4"/></a>@endif
                </div>
            @endif
        </article>

        {{-- Weekly progress --}}
        <article class="glass rise p-5 sm:p-6" style="--i:2" aria-labelledby="week-title">
            <h2 id="week-title" class="eyebrow">This week</h2>
            <div class="mt-4 flex items-center gap-5">
                <x-ring :value="$week['target'] > 0 ? $week['done'] / $week['target'] : 0" :size="116" :stroke="10" :label="$week['done'].' of '.$week['target'].' workouts done this week'">
                    <span class="num text-2xl text-neon text-glow"><span data-count-to="{{ $week['done'] }}">{{ $week['done'] }}</span><span class="text-muted">/{{ $week['target'] }}</span></span>
                    <span class="text-[0.65rem] uppercase tracking-widest text-muted">workouts</span>
                </x-ring>
                <p class="text-sm text-muted">
                    @if ($week['target'] > 0 && $week['done'] >= $week['target'])
                        Perfect week. Now recover well.
                    @else
                        {{ max(0, $week['target'] - $week['done']) }} to go. Progress beats perfection.
                    @endif
                </p>
            </div>
            <ol class="mt-5 grid grid-cols-7 gap-1.5" aria-label="Week overview">
                @foreach ($overview as $entry)
                    @php
                        $cell = $entry['day'] ? ($planUrl ? route_if('plan.day', ['day' => $entry['day']->day_of_week]) : null) : null;
                        $statusLabel = str_replace('_', ' ', $entry['status']);
                    @endphp
                    <li>
                        <x-maybe-link :href="$cell" class="flex flex-col items-center gap-1.5 rounded-xl border {{ $entry['is_today'] ? 'border-line-strong bg-neon/5' : 'border-transparent' }} px-0.5 py-2 text-center" title="{{ $entry['date']->format('D j M') }}: {{ $statusLabel }}">
                            <span class="text-[0.65rem] font-semibold uppercase tracking-wider {{ $entry['is_today'] ? 'text-neon' : 'text-muted' }}">{{ $entry['date']->format('D') }}</span>
                            <span class="dot dot-{{ $entry['status'] }}" aria-hidden="true"></span>
                            <span class="text-[0.6rem] font-bold uppercase text-muted">{{ $entry['day']?->label ?? '' }}</span>
                            <span class="sr-only">{{ $entry['date']->format('l') }}: {{ $statusLabel }}</span>
                        </x-maybe-link>
                    </li>
                @endforeach
            </ol>
        </article>
    </div>

    {{-- Stat tiles --}}
    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <article class="glass rise p-5" style="--i:3">
            <p class="eyebrow flex items-center gap-2"><x-icon name="flame" class="size-4"/>Streak</p>
            <p class="num mt-3 text-4xl text-neon text-glow"><span data-count-to="{{ $streak }}">{{ $streak }}</span> <span class="text-base text-muted">{{ \Illuminate\Support\Str::plural('day', $streak) }}</span></p>
            <p class="mt-1 text-xs text-muted">Longest: {{ $user->longest_workout_streak }} · rest days never break it</p>
        </article>

        <article class="glass rise flex items-center gap-4 p-5" style="--i:4">
            <x-ring :value="$xp['pct']" :size="84" :stroke="8" :label="'Level '.$xp['level'].', '.round($xp['pct'] * 100).' percent to the next level'">
                <span class="num text-xl text-neon">{{ $xp['level'] }}</span>
            </x-ring>
            <div class="min-w-0">
                <p class="eyebrow flex items-center gap-2"><x-icon name="bolt" class="size-4"/>Level {{ $xp['level'] }}</p>
                <p class="num mt-1 text-lg"><span data-count-to="{{ $user->xp_total }}">{{ number_format($user->xp_total) }}</span> <span class="text-xs text-muted">XP</span></p>
                <p class="text-xs text-muted">{{ number_format($xp['next_at'] - $user->xp_total) }} XP to level {{ $xp['level'] + 1 }}</p>
            </div>
        </article>

        <article class="glass rise p-5" style="--i:5">
            <p class="eyebrow flex items-center gap-2"><x-icon name="utensils" class="size-4"/>Calories today</p>
            @if ($kcalTarget)
                <p class="num mt-3 text-2xl"><span data-count-to="{{ round($totals['calories']) }}">{{ number_format(round($totals['calories'])) }}</span><span class="text-sm text-muted"> / {{ number_format($kcalTarget) }} kcal</span></p>
                <div class="mt-3"><x-bar :value="$totals['calories']" :max="$kcalTarget" :over="$totals['calories'] > $kcalTarget * 1.1" label="Calories eaten today"/></div>
            @else
                <p class="num mt-3 text-2xl">{{ number_format(round($totals['calories'])) }} <span class="text-sm text-muted">kcal</span></p>
                <p class="mt-2 text-xs text-muted">@if ($dietUrl)<a class="text-neon underline underline-offset-4" href="{{ $dietUrl }}">Set your diet targets</a>@else Diet targets arrive with the diet engine.@endif</p>
            @endif
        </article>

        <article class="glass rise p-5" style="--i:6">
            <p class="eyebrow flex items-center gap-2"><x-icon name="target" class="size-4"/>Protein today</p>
            @if ($proteinTarget)
                <p class="num mt-3 text-2xl"><span data-count-to="{{ round($totals['protein_g']) }}">{{ round($totals['protein_g']) }}</span><span class="text-sm text-muted"> / {{ $proteinTarget }} g</span></p>
                <div class="mt-3"><x-bar :value="$totals['protein_g']" :max="$proteinTarget" label="Protein eaten today"/></div>
                <p class="mt-2 text-xs text-muted">{{ max(0, round($proteinTarget - $totals['protein_g'])) }} g left to hit your goal</p>
            @else
                <p class="num mt-3 text-2xl">{{ round($totals['protein_g']) }} <span class="text-sm text-muted">g</span></p>
                <p class="mt-2 text-xs text-muted">No protein target yet.</p>
            @endif
        </article>
    </div>

    {{-- Water and creatine, one tap each --}}
    <section class="glass mt-4 p-5" aria-labelledby="habits-title" x-data="habits">
        <script type="application/json" id="habit-config">@json($habitConfig)</script>
        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-4">
            <h2 id="habits-title" class="eyebrow flex items-center gap-2"><x-icon name="droplet" class="size-4"/>Water and creatine</h2>
            <p class="text-xs text-muted" role="status" aria-live="polite" x-text="message" x-show="message" x-cloak></p>
        </div>
        <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2">
            <div>
                <p><span class="num text-2xl" x-text="s.water.total_ml"></span> <span class="text-sm text-muted">/ <span x-text="s.water.goal_ml"></span> ml</span> <span class="ml-1 text-xs" :class="s.water.reached ? 'text-neon' : 'text-muted'" x-text="s.water.reached ? 'Goal reached' : (s.water.goal_ml - s.water.total_ml) + ' ml to go'"></span></p>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-white/10" role="progressbar" aria-label="Water today" :aria-valuenow="s.water.pct" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-[#4DA3FF] transition-[width] duration-500 motion-reduce:transition-none" :style="`width: ${Math.min(100, s.water.pct)}%`"></div></div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="btn btn-ghost btn-sm" @click="drink(250)" :disabled="busy">+ Glass (250)</button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="drink(500)" :disabled="busy">+ Bottle (500)</button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="undoDrink()" :disabled="busy || !s.water.entries.length" aria-label="Undo last drink"><x-icon name="rotate" class="size-4"/>Undo</button>
                </div>
            </div>
            <div>
                <button type="button" class="btn w-full sm:w-auto" :class="s.creatine.taken ? 'btn-primary' : 'btn-ghost'" @click="toggleCreatine()" :disabled="busy" :aria-pressed="s.creatine.taken"><x-icon name="pill" class="size-4"/><span x-text="s.creatine.taken ? 'Creatine taken' : 'Take creatine (' + s.creatine.dose_g + ' g)'"></span></button>
                <p class="mt-2 text-xs text-muted"><span class="num text-ink" x-text="s.creatine.current"></span> day streak · longest <span class="num text-ink" x-text="s.creatine.longest"></span>. <a href="{{ route_if('recovery.index') }}" class="text-neon underline underline-offset-4">Sleep and recovery</a></p>
            </div>
        </div>
    </section>

    {{-- Quick actions (a tile appears once its section exists) --}}
    @php
        $actions = collect([
            [$start, 'dumbbell', 'Start workout', 'Guided game mode'],
            [$postureUrl, 'scan', 'Check posture', 'AI form checker'],
            [$foodUrl, 'apple', 'Log food', 'Track protein and macros'],
            [route_if('progress.index'), 'chart', 'Progress', 'Weight and strength'],
        ])->filter(fn ($a) => $a[0]);
    @endphp
    @if ($actions->isNotEmpty())
        <section class="mt-6" aria-labelledby="qa-title">
            <h2 id="qa-title" class="eyebrow mb-3">Quick actions</h2>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach ($actions as [$url, $icon, $label, $sub])
                    <a href="{{ $url }}" class="glass glass-hover flex flex-col gap-2 p-4">
                        <span class="grid size-10 place-items-center rounded-xl border border-line-strong bg-neon/10 text-neon"><x-icon :name="$icon" class="size-5"/></span>
                        <span class="text-sm font-bold uppercase">{{ $label }}</span>
                        <span class="text-xs text-muted">{{ $sub }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.app>
