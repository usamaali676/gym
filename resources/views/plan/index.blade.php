@php
    $rules = $plan?->rules ?? [];
    $fmtRest = fn (array $r) => $r['max'] >= 120 ? round($r['min'] / 60, 1).'-'.round($r['max'] / 60, 1).' min' : $r['min'].'-'.$r['max'].' s';
@endphp
<x-layouts.app title="Plan" page="plan">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Week {{ $weekNumber }} · {{ $monday->format('j M') }} to {{ $sunday->format('j M') }}</p>
            <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">{{ $plan?->name ?? 'Your plan' }}</h1>
            <p class="mt-1 text-sm text-muted">{{ $progress['done'] }} of {{ $progress['target'] }} training days done this week.</p>
        </div>
        <nav class="flex items-center gap-2" aria-label="Week navigation">
            <a class="btn btn-ghost btn-sm btn-icon" href="{{ route('plan.index', ['week' => $prevWeek]) }}" aria-label="Previous week"><x-icon name="chevron-left" class="size-4"/></a>
            @unless ($isThisWeek)<a class="btn btn-ghost btn-sm" href="{{ route('plan.index') }}">This week</a>@endunless
            <a class="btn btn-ghost btn-sm btn-icon" href="{{ route('plan.index', ['week' => $nextWeek]) }}" aria-label="Next week"><x-icon name="chevron-right" class="size-4"/></a>
        </nav>
    </div>

    <div class="mb-6"><x-bar :value="$progress['done']" :max="max(1, $progress['target'])" label="Training days completed this week"/></div>

    <ol class="space-y-3">
        @foreach ($overview as $i => $entry)
            @php
                $d = $entry['day'];
                $views = $d && ! $d->is_rest ? $plans->effectiveItems($user, $d) : collect();
                $url = $d ? route('plan.day', ['day' => $d->day_of_week, 'date' => $entry['date']->toDateString()]) : null;
            @endphp
            <li class="rise" style="--i: {{ $i }}">
                <x-maybe-link :href="$url" class="glass glass-hover flex gap-4 p-4 sm:p-5 {{ $entry['is_today'] ? 'glass-neon' : '' }} {{ $entry['status'] === 'rest' ? 'opacity-80' : '' }}">
                    <div class="flex w-14 shrink-0 flex-col items-center justify-center rounded-xl border {{ $entry['is_today'] ? 'border-line-strong bg-neon/10' : 'border-line bg-black/20' }} py-2 text-center">
                        <span class="sr-only">{{ $entry['date']->format('l') }}</span>
                        <span aria-hidden="true" class="text-[0.65rem] font-bold uppercase tracking-widest {{ $entry['is_today'] ? 'text-neon' : 'text-muted' }}">{{ $entry['date']->format('D') }}</span>
                        <span class="num text-2xl leading-none">{{ $entry['date']->format('j') }}</span>
                        <span class="text-[0.6rem] uppercase text-muted">{{ $entry['date']->format('M') }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-black uppercase sm:text-xl">{{ $d?->label }} @if ($d?->focusLabel())<span class="text-neon">{{ $d->focusLabel() }}</span>@endif</h2>
                            <x-status :status="$entry['status']"/>
                        </div>
                        @if ($views->isNotEmpty())
                            <p class="mt-1 text-xs text-muted">{{ $views->count() }} exercises · {{ $d->totalSets() }} sets · about {{ $plans->estimatedMinutes($d) }} min</p>
                            <p class="mt-2 hidden flex-wrap gap-1.5 sm:flex">
                                @foreach ($views->take(5) as $v)<span class="chip !text-[0.7rem]">{{ $v->name() }}</span>@endforeach
                                @if ($views->count() > 5)<span class="chip !text-[0.7rem] text-muted">+{{ $views->count() - 5 }}</span>@endif
                            </p>
                        @else
                            <p class="mt-1 text-sm text-muted">{{ $d?->recovery_note ?? 'Rest and recover.' }}</p>
                        @endif
                    </div>
                    <x-icon name="chevron-right" class="hidden size-5 self-center text-muted sm:block"/>
                </x-maybe-link>
            </li>
        @endforeach
    </ol>

    <section class="mt-10" aria-labelledby="rules-title">
        <h2 id="rules-title" class="eyebrow mb-3">The rules that make the plan work</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if (isset($rules['progressive_overload']))
                <article class="glass p-5">
                    <h3 class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="target" class="size-4 text-neon"/>Progressive overload</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted">{{ $rules['progressive_overload']['note'] }}</p>
                    <p class="mt-3 flex flex-wrap gap-2 text-xs"><span class="chip chip-neon">+{{ implode('-', $rules['progressive_overload']['add_reps']) }} reps</span><span class="chip chip-neon">or +{{ implode('-', $rules['progressive_overload']['add_weight_kg']) }} kg</span><span class="chip">every week</span></p>
                </article>
            @endif
            <article class="glass p-5">
                <h3 class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="timer" class="size-4 text-neon"/>Rest between sets</h3>
                <p class="mt-2 text-sm leading-relaxed text-muted">Rest timers in the workout use these defaults.</p>
                <p class="mt-3 flex flex-wrap gap-2 text-xs">
                    @isset($restRules['compound'])<span class="chip chip-neon">Compound lifts {{ $fmtRest($restRules['compound']) }}</span>@endisset
                    @isset($restRules['isolation'])<span class="chip chip-neon">Isolation {{ $fmtRest($restRules['isolation']) }}</span>@endisset
                </p>
            </article>
            @if (isset($rules['creatine']))
                <article class="glass p-5">
                    <h3 class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="pill" class="size-4 text-neon"/>Creatine</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted">{{ $rules['creatine']['grams'] }} g daily. {{ $rules['creatine']['note'] }}</p>
                </article>
            @endif
            @if (isset($rules['sleep']))
                <article class="glass p-5">
                    <h3 class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="moon" class="size-4 text-neon"/>Sleep</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted">{{ $rules['sleep']['hours_min'] }}-{{ $rules['sleep']['hours_max'] }} hours every night. {{ $rules['sleep']['note'] }}</p>
                </article>
            @endif
        </div>
    </section>
</x-layouts.app>
