@php
    $creatineRule = $rules['creatine'] ?? null;
    $sleepRule = $rules['sleep'] ?? null;
    $types = ['warmup' => 'Warm-up', 'cooldown' => 'Cool-down', 'recovery' => 'Recovery'];
@endphp
<x-layouts.app title="Recovery" page="recovery">
    <script type="application/json" id="habit-config">@json($habitConfig)</script>

    <div class="mb-6">
        <p class="eyebrow">Recovery</p>
        <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">Recover like you train</h1>
        <p class="mt-1 max-w-2xl text-sm text-muted">{{ $sleepRule['note'] ?? 'Muscle is built during recovery, not in the gym.' }} Water, creatine and sleep take a minute a day.</p>
    </div>

    <div x-data="habits" class="space-y-4">
        <p class="text-sm text-muted" role="status" aria-live="polite" x-text="message" x-show="message"></p>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            {{-- Water --}}
            <section class="glass glass-neon p-5" aria-labelledby="water-title">
                <h2 id="water-title" class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="droplet" class="size-4 text-neon"/>Water</h2>
                <div class="mt-4 flex items-center gap-4">
                    <div class="relative size-28 shrink-0" role="img" :aria-label="`${s.water.total_ml} of ${s.water.goal_ml} millilitres, ${s.water.pct} percent`">
                        <svg viewBox="0 0 100 100" class="size-full -rotate-90" aria-hidden="true">
                            <circle cx="50" cy="50" r="44" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="9"/>
                            <circle cx="50" cy="50" r="44" fill="none" stroke="#4DA3FF" stroke-width="9" stroke-linecap="round" :stroke-dasharray="waterDash" class="transition-[stroke-dasharray] duration-500 motion-reduce:transition-none"/>
                        </svg>
                        <div class="absolute inset-0 grid place-items-center text-center"><div><p class="num text-xl leading-none" x-text="s.water.pct + '%'"></p><p class="mt-0.5 text-[0.6rem] uppercase tracking-wider text-muted">of goal</p></div></div>
                    </div>
                    <div class="min-w-0">
                        <p><span class="num text-2xl" x-text="s.water.total_ml"></span> <span class="text-sm text-muted">/ <span x-text="s.water.goal_ml"></span> ml</span></p>
                        <p class="mt-1 text-xs" :class="s.water.reached ? 'text-neon' : 'text-muted'" x-text="s.water.reached ? 'Goal reached. Nice.' : (s.water.goal_ml - s.water.total_ml) + ' ml to go'"></p>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" class="btn btn-ghost btn-sm" @click="drink(250)" :disabled="busy">+ Glass (250)</button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="drink(500)" :disabled="busy">+ Bottle (500)</button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="undoDrink()" :disabled="busy || !s.water.entries.length" aria-label="Undo last drink"><x-icon name="rotate" class="size-4"/>Undo</button>
                </div>
                <form class="mt-3 flex items-end gap-2" @submit.prevent="drink(custom)">
                    <label class="min-w-0 flex-1"><span class="label !mb-1">Other amount (ml)</span><input type="number" min="10" max="2000" step="10" class="input !min-h-10" x-model.number="custom"></label>
                    <button class="btn btn-ghost btn-sm" :disabled="busy">Add</button>
                </form>
            </section>

            {{-- Creatine --}}
            <section class="glass p-5" aria-labelledby="creatine-title">
                <h2 id="creatine-title" class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="pill" class="size-4 text-neon"/>Creatine</h2>
                <p class="mt-2 text-sm text-muted">{{ $creatineRule ? ($creatineRule['grams'].' g daily. '.$creatineRule['note']) : 'Take it daily; timing does not matter.' }}</p>
                <button type="button" class="btn mt-4 w-full" :class="s.creatine.taken ? 'btn-primary' : 'btn-ghost'" @click="toggleCreatine()" :disabled="busy" :aria-pressed="s.creatine.taken">
                    <x-icon name="check" class="size-5"/><span x-text="s.creatine.taken ? 'Taken today' : 'Mark as taken (' + s.creatine.dose_g + ' g)'"></span></button>
                <div class="mt-4 grid grid-cols-2 gap-3 text-center">
                    <div class="rounded-xl border border-line bg-white/[0.03] p-3"><p class="num text-2xl" x-text="s.creatine.current"></p><p class="text-[0.65rem] uppercase tracking-wider text-muted">day streak</p></div>
                    <div class="rounded-xl border border-line bg-white/[0.03] p-3"><p class="num text-2xl" x-text="s.creatine.longest"></p><p class="text-[0.65rem] uppercase tracking-wider text-muted">longest</p></div>
                </div>
                <ol class="mt-4 flex justify-between gap-1" aria-label="Last 14 days">
                    <template x-for="h in s.history" :key="h.date">
                        <li class="grid justify-items-center gap-1 text-[0.6rem] text-muted"><span class="size-3.5 rounded-full border" :class="h.creatine ? 'border-neon bg-neon' : 'border-line'" :title="h.date + (h.creatine ? ': taken' : ': not taken')"></span><span x-text="dayLabel(h.date)" aria-hidden="true"></span><span class="sr-only" x-text="h.date + (h.creatine ? ' taken' : ' not taken')"></span></li>
                    </template>
                </ol>
            </section>

            {{-- Sleep --}}
            <section class="glass p-5" aria-labelledby="sleep-title">
                <h2 id="sleep-title" class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="moon" class="size-4 text-neon"/>Sleep</h2>
                <p class="mt-2 text-sm text-muted">Goal <span class="num text-ink" x-text="s.sleep.goal"></span> h{{ $sleepRule ? ' (the plan says '.$sleepRule['hours_min'].'-'.$sleepRule['hours_max'].' h)' : '' }}. <span x-show="s.sleep.average !== null">Last 7 nights: <span class="num text-ink" x-text="s.sleep.average"></span> h.</span></p>
                <form class="mt-3 space-y-3" @submit.prevent="saveSleep()">
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block"><span class="label !mb-1">Last night (h)</span><input type="number" min="0" max="16" step="0.25" class="input !min-h-10" x-model.number="sleepForm.hours" required></label>
                        <label class="block"><span class="label !mb-1">Quality</span>
                            <select class="select !min-h-10" x-model.number="sleepForm.quality"><option value="1">1 - poor</option><option value="2">2</option><option value="3">3 - okay</option><option value="4">4</option><option value="5">5 - great</option></select></label>
                        <label class="block"><span class="label !mb-1">Bedtime</span><input type="time" class="input !min-h-10" x-model="sleepForm.bedtime"></label>
                        <label class="block"><span class="label !mb-1">Woke up</span><input type="time" class="input !min-h-10" x-model="sleepForm.wake_time"></label>
                    </div>
                    <button class="btn btn-primary w-full btn-sm" :disabled="busy">Save sleep</button>
                </form>
                <ol class="mt-4 flex h-12 items-end justify-between gap-1" aria-label="Hours slept, last 14 nights">
                    <template x-for="h in s.history" :key="h.date">
                        <li class="flex-1 rounded-sm" :class="h.sleep === null ? 'bg-white/5' : (h.sleep >= s.sleep.goal ? 'bg-neon/70' : 'bg-amber-400/60')" :style="`height: ${Math.max(8, sleepBar(h.sleep))}%`" :title="h.date + (h.sleep === null ? ': no entry' : ': ' + h.sleep + ' h')"><span class="sr-only" x-text="h.date + (h.sleep === null ? ' no entry' : ' ' + h.sleep + ' hours')"></span></li>
                    </template>
                </ol>
            </section>
        </div>

        <section class="glass flex flex-wrap items-center gap-3 p-4" aria-labelledby="mob-title">
            <h2 id="mob-title" class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="stretch" class="size-4 text-neon"/>Mobility today</h2>
            <form class="flex items-center gap-2" @submit.prevent="saveMobility()">
                <label class="flex items-center gap-2 text-sm"><input type="number" min="0" max="240" class="input !min-h-10 !w-24" x-model.number="mobility" aria-label="Minutes of mobility work today"> minutes</label>
                <button class="btn btn-ghost btn-sm" :disabled="busy">Save</button>
            </form>
        </section>
    </div>

    {{-- Routine generator --}}
    @if ($routine)
        <section class="mt-8" aria-labelledby="routine-title">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="routine-title" class="text-lg font-bold uppercase">{{ $routine['title'] }}</h2>
                    <p class="mt-1 text-sm text-muted">About {{ $routine['minutes'] }} minutes · {{ count($routine['items']) }} moves, picked for the muscles that day trains.</p>
                </div>
                <form method="GET" action="{{ route('recovery.index') }}" class="flex flex-wrap items-end gap-2" x-data x-on:change="$el.requestSubmit()">
                    <label><span class="label !mb-1">Day</span>
                        <select name="day" class="select !min-h-10">@foreach ($days as $d)<option value="{{ $d->day_of_week }}" @selected($dayNo === $d->day_of_week)>{{ $d->name }}@if($d->is_rest) (rest)@endif</option>@endforeach</select></label>
                    <label><span class="label !mb-1">Routine</span>
                        <select name="type" class="select !min-h-10">@foreach ($types as $k => $label)<option value="{{ $k }}" @selected($type === $k)>{{ $label }}</option>@endforeach</select></label>
                    <noscript><button class="btn btn-ghost btn-sm">Show</button></noscript>
                </form>
            </div>
            <ol class="grid grid-cols-1 gap-3 md:grid-cols-2">
                @foreach ($routine['items'] as $i => $item)
                    @php $ex = $item['exercise']; @endphp
                    <li class="glass flex gap-4 p-4">
                        <x-demo-thumb :exercise="$ex"/>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-muted">Step {{ $i + 1 }}</p>
                            <h3 class="truncate text-base font-bold">{{ $ex->name }}</h3>
                            <p class="mt-0.5 text-sm text-muted">{{ $item['hold_seconds'] }} s{{ $item['per_side'] ? ' each side' : '' }} · {{ $ex->mobility_type === 'static' ? 'hold the stretch' : 'keep it moving' }}</p>
                            <a href="{{ route('library.show', $ex) }}" class="mt-1 inline-block text-xs font-semibold text-neon underline underline-offset-4">How to do it</a>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif
</x-layouts.app>
