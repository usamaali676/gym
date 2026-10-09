@php
    $rules = $plan?->rules ?? [];
    $start = route_if('workout.start');
    $startUrl = $start && ! $day->is_rest ? $start.'?'.http_build_query(['day' => $day->day_of_week, 'date' => $date->toDateString()]) : null;
    $dateStr = $date->toDateString();
    $isCompleted = $status === 'completed';
    $isSkipped = $status === 'skipped';
@endphp
<x-layouts.app :title="$day->name" page="plan-day">
    <div x-data="swapSheet" @keydown.escape.window="close()">
        <a href="{{ route('plan.index', ['week' => $dateStr]) }}" class="mb-4 inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><x-icon name="chevron-left" class="size-4"/>Back to plan</a>

        <header class="glass glass-neon rise relative overflow-hidden p-5 sm:p-7">
            <div class="pointer-events-none absolute -right-16 -top-16 size-64 rounded-full bg-neon/10 blur-3xl" aria-hidden="true"></div>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="eyebrow">{{ $date->format('l, j F') }}</p>
                    <h1 class="mt-2 text-3xl font-black uppercase sm:text-5xl">{{ $day->label }} @if ($day->focusLabel())<span class="text-neon text-glow">{{ $day->focusLabel() }}</span>@endif</h1>
                </div>
                <x-status :status="$status"/>
            </div>

            @unless ($day->is_rest)
                <ul class="mt-4 flex flex-wrap gap-2 text-sm">
                    <li class="chip"><x-icon name="list" class="size-4"/>{{ $views->count() }} exercises</li>
                    <li class="chip"><x-icon name="repeat" class="size-4"/>{{ $day->totalSets() }} sets</li>
                    <li class="chip"><x-icon name="clock" class="size-4"/>about {{ $minutes }} min</li>
                </ul>

                <div class="mt-6 flex flex-wrap items-center gap-3">
                    @if ($startUrl && ! $isCompleted && $canAct)
                        <a class="btn btn-primary btn-lg" href="{{ $startUrl }}"><x-icon name="play" class="size-5"/>{{ $session && $session->isOpen() ? 'Resume workout' : 'Start workout' }}</a>
                    @endif
                    @if ($canAct && ! $isCompleted)
                        <form method="POST" action="{{ route('plan.complete', $day->day_of_week) }}">@csrf
                            <input type="hidden" name="date" value="{{ $dateStr }}">
                            <button class="btn btn-ghost"><x-icon name="check" class="size-4"/>Mark complete</button>
                        </form>
                    @endif
                    @if ($canAct && ! $isCompleted && ! $isSkipped)
                        <form method="POST" action="{{ route('plan.skip', $day->day_of_week) }}">@csrf
                            <input type="hidden" name="date" value="{{ $dateStr }}">
                            <button class="btn btn-ghost"><x-icon name="skip" class="size-4"/>Skip day</button>
                        </form>
                    @endif
                    @if ($isSkipped)
                        <form method="POST" action="{{ route('plan.unskip', $day->day_of_week) }}">@csrf @method('DELETE')
                            <input type="hidden" name="date" value="{{ $dateStr }}">
                            <button class="btn btn-ghost"><x-icon name="rotate" class="size-4"/>Undo skip</button>
                        </form>
                    @endif
                    @if ($isFuture)<p class="text-sm text-muted">This day is in the future. Come back on {{ $date->format('l') }}.</p>@endif
                    @if ($isCompleted)<p class="text-sm text-neon">Done. +{{ $session?->xp_earned ?? 0 }} XP earned.</p>@endif
                </div>
            @endunless
        </header>

        @if ($day->is_rest)
            <section class="glass mt-4 p-6" aria-labelledby="rest-title">
                <h2 id="rest-title" class="text-lg font-black uppercase">Recovery day</h2>
                <p class="mt-2 max-w-2xl text-muted">{{ $day->recovery_note }}</p>
                <ul class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    @isset($rules['creatine'])<li class="glass p-4 text-sm"><x-icon name="pill" class="mb-2 size-5 text-neon"/>Take your {{ $rules['creatine']['grams'] }} g of creatine. {{ $rules['creatine']['note'] }}</li>@endisset
                    @isset($rules['sleep'])<li class="glass p-4 text-sm"><x-icon name="moon" class="mb-2 size-5 text-neon"/>Sleep {{ $rules['sleep']['hours_min'] }}-{{ $rules['sleep']['hours_max'] }} hours tonight.</li>@endisset
                    <li class="glass p-4 text-sm"><x-icon name="droplet" class="mb-2 size-5 text-neon"/>Hydrate and eat your protein target.</li>
                </ul>
                @if (route_if('recovery.index'))<a class="btn btn-primary mt-5" href="{{ route('recovery.index') }}"><x-icon name="stretch" class="size-4"/>Open recovery routine</a>@endif
            </section>
        @else
            <ol class="mt-4 space-y-3" aria-label="Exercises">
                @foreach ($views as $i => $view)
                    @php $ex = $view->exercise; @endphp
                    <li class="glass rise p-4 sm:p-5" style="--i: {{ $i }}">
                        <div class="flex gap-4">
                            <div class="flex flex-col items-center gap-2">
                                <span class="num grid size-8 place-items-center rounded-lg border border-line-strong bg-neon/10 text-lg text-neon">{{ $view->item->position }}</span>
                                <x-demo-thumb :exercise="$ex"/>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-base font-bold sm:text-lg">{{ $view->name() }}</h2>
                                    @if ($view->swapped())<span class="chip chip-neon !text-[0.65rem]"><x-icon name="swap" class="size-3"/>Swapped from {{ $view->original()->name }}</span>@endif
                                </div>
                                <p class="mt-0.5 text-sm text-muted">{{ $view->item->focus }}</p>
                                <ul class="mt-3 flex flex-wrap gap-2 text-xs">
                                    <li class="chip chip-neon"><span class="num text-sm">{{ $view->item->sets }} × {{ $view->item->repsLabel() }}</span></li>
                                    <li class="chip"><x-icon name="timer" class="size-3.5"/>Rest {{ $view->restLabel() }}</li>
                                    <li class="chip">{{ $ex->mechanic->label() }}</li>
                                    <li class="chip">{{ $ex->muscle_group->label() }}</li>
                                </ul>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <a class="btn btn-ghost btn-sm" href="{{ route('library.show', $ex) }}"><x-icon name="info" class="size-4"/>Form tips</a>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="show({{ $view->item->id }}, @js($view->name()))"><x-icon name="swap" class="size-4"/>Swap</button>
                                    @if ($view->swapped())
                                        <button type="button" class="btn btn-ghost btn-sm" @click="revert({{ $view->item->id }})"><x-icon name="rotate" class="size-4"/>Use original</button>
                                    @endif
                                    @if (route_if('posture.show', ['exercise' => $ex]) && $ex->postureRules->isNotEmpty())
                                        <a class="btn btn-ghost btn-sm" href="{{ route('posture.show', $ex) }}"><x-icon name="scan" class="size-4"/>Check posture</a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif

        {{-- Swap sheet --}}
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="swap-title">
            <div class="absolute inset-0 bg-black/75 backdrop-blur-sm" @click="close()" x-transition.opacity></div>
            <div class="glass relative max-h-[85dvh] w-full max-w-lg overflow-y-auto rounded-b-none p-5 sm:rounded-b-[1.1rem]" x-trap.noscroll="open" x-transition>
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div>
                        <p class="eyebrow">Swap exercise</p>
                        <h2 id="swap-title" class="mt-1 text-lg font-black" x-text="itemName"></h2>
                        <p class="mt-1 text-xs text-muted">Alternatives that train the same muscle group with your equipment.</p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm btn-icon" @click="close()" aria-label="Close"><x-icon name="x" class="size-4"/></button>
                </div>
                <p class="error" x-show="error" x-text="error" role="alert"></p>
                <p class="py-6 text-center text-sm text-muted" x-show="loading">Loading alternatives…</p>
                <p class="py-6 text-center text-sm text-muted" x-show="!loading && !error && alternatives.length === 0">No alternatives match your equipment. Add more equipment in your profile.</p>
                <ul class="space-y-2">
                    <template x-for="alt in alternatives" :key="alt.id">
                        <li class="glass p-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold" x-text="alt.name"></p>
                                    <p class="mt-0.5 text-xs text-muted"><span x-text="alt.mechanic"></span> · <span x-text="alt.difficulty"></span> · <span x-text="(alt.equipment || []).join(', ').replaceAll('_', ' ')"></span></p>
                                </div>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="choose(alt.id, false)">Just next time</button>
                                <button type="button" class="btn btn-ghost btn-sm" :disabled="saving" @click="choose(alt.id, true)">Always use this</button>
                            </div>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    </div>
</x-layouts.app>
