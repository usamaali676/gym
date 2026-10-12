@php
    $guidance = $rule->guidance ?? [];
    $camera = $guidance['camera'] ?? ['title' => 'Set up your camera', 'steps' => [], 'note' => null];
    $backUrl = $lab['urls']['workout'] ?? route('library.show', $exercise);
    $backLabel = $lab['urls']['workout'] ? 'Back to workout' : 'Exercise page';
@endphp
<x-layouts.app :title="'Posture · '.$exercise->name" page="posture">
    <script type="application/json" id="lab-config">@json($lab)</script>

    <nav class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted" aria-label="Breadcrumb">
        <a href="{{ route('posture.index') }}" class="inline-flex items-center gap-1 hover:text-ink"><x-icon name="chevron-left" class="size-4"/>Posture Lab</a>
        <a href="{{ $backUrl }}" class="hover:text-ink">{{ $backLabel }}</a>
    </nav>

    <div x-data="postureLab" class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <section class="space-y-4 lg:col-span-2" aria-labelledby="lab-title">
            <header class="glass glass-neon p-5 sm:p-6">
                <p class="eyebrow">{{ $rule->tier->label() }} · {{ $rule->camera_view->label() }}</p>
                <h1 id="lab-title" class="mt-2 text-2xl font-black uppercase sm:text-3xl">{{ $exercise->name }}</h1>
                <p class="mt-2 text-sm text-muted">{{ $exercise->description }}</p>
            </header>

            {{-- ===================================================== Stage ===================================================== --}}
            <div x-ref="stage" x-show="['loading','position','countdown','live'].includes(phase)" x-cloak
                 class="relative w-full overflow-hidden rounded-2xl border border-line-strong bg-black"
                 style="aspect-ratio: 4 / 3" role="region" aria-label="Camera view with skeleton overlay">
                <video x-ref="video" class="absolute inset-0 h-full w-full object-contain" :class="mirror && 'scale-x-[-1]'" playsinline muted autoplay aria-hidden="true"></video>
                <canvas x-ref="overlay" class="absolute inset-0 h-full w-full" aria-hidden="true"></canvas>

                {{-- loading --}}
                <div x-show="phase === 'loading'" class="absolute inset-0 grid place-items-center bg-black/80 p-6 text-center">
                    <div>
                        <div class="mx-auto size-10 animate-spin rounded-full border-2 border-neon/30 border-t-neon motion-reduce:animate-none" aria-hidden="true"></div>
                        <p class="mt-4 font-semibold" role="status" x-text="loadingText"></p>
                        <p class="mt-1 text-sm text-muted">Allow the camera if your browser asks. The model is loaded from this site, not from the internet.</p>
                    </div>
                </div>

                {{-- status / coaching pill (top left) --}}
                <div class="pointer-events-none absolute left-3 right-3 top-3 sm:right-72" aria-live="polite">
                    <p x-show="phase === 'position' && statusMessage" x-text="statusMessage" class="inline-flex max-w-full items-start gap-2 rounded-xl bg-black/70 px-3 py-2 text-sm font-semibold backdrop-blur"></p>
                    <p x-show="phase === 'live'" class="inline-flex items-center gap-2 rounded-xl bg-black/70 px-3 py-2 text-sm font-semibold backdrop-blur">
                        <span class="size-2 rounded-full" :class="paused ? 'bg-muted' : 'bg-neon animate-pulse motion-reduce:animate-none'" aria-hidden="true"></span>
                        <span x-text="paused ? 'Paused' : phaseLabel"></span>
                        <span class="num text-muted" x-text="clock"></span>
                    </p>
                </div>

                {{-- toggles (top right on larger screens; on phones they sit under the video) --}}
                <div class="absolute right-2 top-2 hidden flex-wrap justify-end gap-1.5 sm:flex">@include('posture._toggles')</div>

                {{-- countdown --}}
                <div x-show="phase === 'countdown'" class="absolute inset-0 grid place-items-center bg-black/55" role="status">
                    <p class="font-display text-8xl font-black text-neon drop-shadow-[0_0_24px_rgba(57,255,20,0.8)]" x-text="count"></p>
                </div>

                {{-- paused --}}
                <div x-show="phase === 'live' && paused" class="absolute inset-0 grid place-items-center bg-black/60">
                    <button type="button" class="btn btn-primary" @click="resume()"><x-icon name="play" class="size-5"/>Resume</button>
                </div>

                {{-- coaching cue (top, under the status pill, so it never hides the legs) --}}
                <div x-show="phase === 'live' && cue" x-transition.opacity class="pointer-events-none absolute inset-x-3 top-14 z-10 flex justify-center sm:top-16">
                    <div class="flex w-fit max-w-full items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-bold backdrop-blur sm:px-4 sm:py-2.5 sm:text-lg"
                         :class="{ 'bg-neon/90 text-[#041006]': cue?.tone === 'ok', 'bg-danger/90 text-white': cue?.tone === 'bad', 'bg-amber-400/90 text-black': cue?.tone === 'warn' }" role="status">
                        <span x-show="cue?.tone === 'ok'" aria-hidden="true"><x-icon name="check" class="size-5"/></span>
                        <span x-show="cue?.tone !== 'ok'" aria-hidden="true"><x-icon name="alert" class="size-5"/></span>
                        <span x-text="cue?.text"></span>
                    </div>
                </div>

                {{-- live HUD (bottom) --}}
                <div x-show="phase === 'live'" class="pointer-events-none absolute inset-x-0 bottom-0 p-2.5 sm:p-4">
                    <div class="flex items-end justify-between gap-3">
                        <div class="rounded-xl bg-black/70 px-3 py-1.5 backdrop-blur sm:px-4 sm:py-2" aria-live="polite">
                            <template x-if="!isTimed">
                                <div>
                                    <p class="num text-4xl leading-none text-neon sm:text-6xl" x-text="reps"></p>
                                    <p class="mt-1 text-[0.6rem] font-semibold uppercase tracking-wider text-muted sm:text-xs"><span x-text="good"></span> clean · <span x-text="bad"></span> flagged</p>
                                </div>
                            </template>
                            <template x-if="isTimed">
                                <div>
                                    <p class="num text-4xl leading-none text-neon sm:text-6xl"><span x-text="holdSeconds.toFixed(1)"></span><span class="text-xl sm:text-2xl">s</span></p>
                                    <p class="mt-1 text-[0.6rem] font-semibold uppercase tracking-wider text-muted sm:text-xs">Hold time</p>
                                </div>
                            </template>
                        </div>
                        <div class="rounded-xl bg-black/70 px-3 py-1.5 text-right backdrop-blur sm:px-4 sm:py-2">
                            <p class="num text-2xl leading-none sm:text-3xl" :class="score >= 80 ? 'text-neon' : (score < 60 ? 'text-danger' : 'text-amber-300')" x-text="score"></p>
                            <p class="mt-1 text-[0.6rem] font-semibold uppercase tracking-wider text-muted sm:text-xs">Form</p>
                        </div>
                    </div>
                    <div x-show="!isTimed" class="mt-2 h-1.5 overflow-hidden rounded-full bg-white/15 sm:mt-3" role="progressbar" aria-label="Rep progress" :aria-valuenow="Math.round(progress * 100)" aria-valuemin="0" aria-valuemax="100">
                        <div class="h-full rounded-full bg-neon transition-[width] duration-100 motion-reduce:transition-none" :style="`width: ${progress * 100}%`"></div>
                    </div>
                </div>

                <p x-show="phase !== 'loading'" class="pointer-events-none absolute bottom-1 left-3 hidden text-[0.65rem] text-white/50 sm:block" x-text="fps ? `${fps} fps · ${delegate}` : ''" x-cloak></p>
            </div>

            {{-- stage controls --}}
            <div x-show="['position','countdown','live'].includes(phase)" x-cloak class="flex flex-wrap gap-1.5 sm:hidden" aria-label="Camera options">@include('posture._toggles')</div>
            <div x-show="['position','countdown','live'].includes(phase)" x-cloak class="flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" x-show="phase === 'position' && positionSeconds >= 5" @click="startNow()"><x-icon name="play" class="size-5"/>Start anyway</button>
                <button type="button" class="btn btn-ghost" x-show="phase === 'live' && !paused" @click="pause()"><x-icon name="pause" class="size-5"/>Pause</button>
                <button type="button" class="btn btn-ghost" x-show="phase === 'live' && paused" @click="resume()"><x-icon name="play" class="size-5"/>Resume</button>
                <button type="button" class="btn btn-primary" x-show="phase === 'live'" @click="finish()"><x-icon name="check" class="size-5"/>Finish set</button>
                <button type="button" class="btn btn-ghost" x-show="phase === 'live'" @click="restart()"><x-icon name="rotate" class="size-5"/>Restart</button>
                <p class="self-center text-sm text-muted" x-show="phase === 'position'" x-text="statusMessage || 'Looking for you…'"></p>
            </div>

            {{-- ===================================================== Intro ===================================================== --}}
            <div x-show="phase === 'intro' || phase === 'error'" class="glass p-5 sm:p-6">
                <template x-if="problem">
                    <div class="mb-5 rounded-xl border border-danger/40 bg-danger/10 p-4" role="alert">
                        <p class="flex items-center gap-2 font-semibold"><x-icon name="alert" class="size-5 text-danger"/>Cannot start the camera</p>
                        <p class="mt-1 text-sm" x-text="problem.message"></p>
                    </div>
                </template>

                <div class="grid grid-cols-1 items-start gap-6 md:grid-cols-5">
                    <div class="md:col-span-3">
                        <h2 class="flex items-center gap-2 text-lg font-bold uppercase"><x-icon name="camera" class="size-5 text-neon"/>{{ $camera['title'] }}</h2>
                        <ol class="mt-3 space-y-2.5 text-sm">
                            @foreach ($camera['steps'] as $n => $step)
                                <li class="flex gap-3"><span class="num grid size-6 shrink-0 place-items-center rounded-md bg-neon/10 text-neon">{{ $n + 1 }}</span><span>{{ $step }}</span></li>
                            @endforeach
                        </ol>
                        @if (!empty($camera['note']))
                            <p class="mt-3 rounded-xl border border-line bg-white/[0.03] p-3 text-sm text-muted"><x-icon name="info" class="mr-1 inline size-4 text-neon"/>{{ $camera['note'] }}</p>
                        @endif
                    </div>
                    <x-camera-diagram :view="$rule->camera_view->value" class="md:col-span-2"/>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-4 border-t border-line pt-5 md:grid-cols-2">
                    <div>
                        <h3 class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="lock" class="size-4 text-neon"/>Your camera stays private</h3>
                        <p class="mt-2 text-sm text-muted">The pose model runs on this device and is served from this site. Video is never recorded, uploaded or sent to a third party. When you finish a set only numbers are saved: reps, form score and which checks failed.</p>
                    </div>
                    <div>
                        <h3 class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="eye" class="size-4 text-neon"/>What it looks at</h3>
                        <ul class="mt-2 list-inside list-disc space-y-0.5 text-sm text-muted">
                            @foreach ($guidance['tracks'] ?? [] as $item)<li>{{ \Illuminate\Support\Str::ucfirst($item) }}</li>@endforeach
                        </ul>
                    </div>
                </div>

                <p class="mt-4 rounded-xl border border-line-strong bg-neon/5 p-3 text-sm"><x-icon name="info" class="mr-1 inline size-4 text-neon"/><strong>How accurate is it?</strong> {{ $guidance['accuracy'] ?? '' }}</p>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button type="button" class="btn btn-primary" @click="start()" :disabled="!canStart"><x-icon name="camera" class="size-5"/><span x-text="phase === 'error' ? 'Try again' : 'Start camera'"></span></button>
                    <a href="{{ $backUrl }}" class="btn btn-ghost">{{ $backLabel }}</a>
                    @unless ($assetsReady)<p class="text-sm text-danger">The pose model is not installed on this server.</p>@endunless
                </div>
            </div>

            {{-- ===================================================== Summary ===================================================== --}}
            <div x-show="phase === 'summary'" x-cloak class="glass glass-neon p-5 sm:p-6" aria-labelledby="summary-title">
                <h2 id="summary-title" class="text-xl font-black uppercase">Set summary</h2>

                <template x-if="summary && !hasResult">
                    <p class="mt-3 text-muted">No reps were counted, so nothing was saved. Check the camera setup on the right, make sure your whole body is in frame, and try again.</p>
                </template>

                <template x-if="summary && hasResult">
                    <div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-xs font-semibold uppercase tracking-wider text-muted" x-text="isTimed ? 'Hold' : 'Reps'"></dt><dd class="num mt-1 text-2xl"><span x-text="isTimed ? summary.hold_seconds + ' s' : summary.total_reps"></span></dd></div>
                            <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-xs font-semibold uppercase tracking-wider text-muted">Clean</dt><dd class="num mt-1 text-2xl text-neon" x-text="summary.good_reps"></dd></div>
                            <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-xs font-semibold uppercase tracking-wider text-muted">Form score</dt><dd class="num mt-1 text-2xl" :class="summary.form_score_avg >= 80 ? 'text-neon' : (summary.form_score_avg < 60 ? 'text-danger' : 'text-amber-300')" x-text="summary.form_score_avg"></dd></div>
                            <div class="rounded-xl border border-line bg-white/[0.03] p-3"><dt class="text-xs font-semibold uppercase tracking-wider text-muted">Lowest</dt><dd class="num mt-1 text-2xl" x-text="summary.form_score_min"></dd></div>
                        </dl>

                        <template x-if="summary.rep_log.length">
                            <div class="mt-5">
                                <h3 class="text-sm font-bold uppercase">Rep by rep</h3>
                                <ol class="mt-2 flex flex-wrap gap-1.5" aria-label="Form score for each rep">
                                    <template x-for="r in summary.rep_log" :key="r.n">
                                        <li class="grid h-14 w-9 place-items-end rounded-md border text-center" :class="r.good ? 'border-neon/40 bg-neon/10' : 'border-danger/50 bg-danger/10'" :title="`Rep ${r.n}: score ${r.score}${r.errors.length ? ' - ' + r.errors.map(errorLabel).join(', ') : ''}`">
                                            <span class="num block w-full pb-0.5 text-xs" :class="r.good ? 'text-neon' : 'text-danger'" x-text="r.score"></span>
                                            <span class="sr-only" x-text="`Rep ${r.n}, ${r.good ? 'clean' : 'flagged'}`"></span>
                                        </li>
                                    </template>
                                </ol>
                            </div>
                        </template>

                        <div class="mt-5" x-show="errorList.length">
                            <h3 class="text-sm font-bold uppercase">What to work on</h3>
                            <ul class="mt-2 space-y-2">
                                <template x-for="e in errorList" :key="e.id">
                                    <li class="flex gap-3 rounded-xl border border-line bg-white/[0.03] p-3 text-sm">
                                        <span class="num grid size-7 shrink-0 place-items-center rounded-md bg-danger/15 text-danger" x-text="e.n"></span>
                                        <span><strong x-text="e.label"></strong><br><span class="text-muted" x-text="e.advice"></span></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <p class="mt-5 rounded-xl border border-neon/30 bg-neon/10 p-3 text-sm font-semibold" x-show="!errorList.length"><x-icon name="check" class="mr-1 inline size-4 text-neon"/>No form faults were flagged. Nice set.</p>
                    </div>
                </template>

                <div class="mt-5 rounded-xl border border-line p-3 text-sm" role="status" aria-live="polite">
                    <p x-show="saveState === 'saving'" class="text-muted">Saving your set…</p>
                    <p x-show="saveState === 'saved'" class="flex flex-wrap items-center gap-2"><x-icon name="check" class="size-4 text-neon"/>Saved to your history.
                        <span class="chip chip-neon" x-show="xp && xp.total > 0"><x-icon name="bolt" class="size-3.5"/>+<span x-text="xp?.total"></span> XP</span></p>
                    <p x-show="saveState === 'empty'" class="text-muted">Nothing to save.</p>
                    <div x-show="saveState === 'failed'" class="flex flex-wrap items-center gap-3">
                        <p class="text-danger" x-text="saveError"></p>
                        <button type="button" class="btn btn-ghost btn-sm" @click="save()">Try saving again</button>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap gap-2">
                    <button type="button" class="btn btn-primary" @click="again()"><x-icon name="rotate" class="size-5"/>Another set</button>
                    <a href="{{ $backUrl }}" class="btn btn-ghost">{{ $backLabel }}</a>
                    <a href="{{ route('posture.index') }}" class="btn btn-ghost">Posture Lab</a>
                </div>
            </div>
        </section>

        {{-- ===================================================== Sidebar ===================================================== --}}
        <aside class="space-y-4" aria-label="Exercise guide">
            <section class="glass p-5" x-show="phase !== 'intro' && phase !== 'error'" x-cloak aria-labelledby="setup-title">
                <h2 id="setup-title" class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="camera" class="size-4 text-neon"/>Camera setup</h2>
                <x-camera-diagram :view="$rule->camera_view->value" class="mt-3"/>
                <p class="mt-2 text-xs text-muted">{{ $camera['note'] ?? '' }}</p>
            </section>

            <section class="glass p-5" aria-labelledby="cues-title">
                <h2 id="cues-title" class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="check" class="size-4 text-neon"/>Form cues</h2>
                <ol class="mt-3 space-y-2 text-sm">
                    @foreach ($exercise->form_cues ?? [] as $n => $cue)
                        <li class="flex gap-3"><span class="num grid size-6 shrink-0 place-items-center rounded-md bg-neon/10 text-neon">{{ $n + 1 }}</span><span>{{ $cue }}</span></li>
                    @endforeach
                </ol>
            </section>

            <section class="glass p-5" aria-labelledby="checks-title">
                <h2 id="checks-title" class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="alert" class="size-4 text-danger"/>The skeleton turns red when</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($rule->config['checks'] ?? [] as $check)
                        <li class="flex items-start gap-2"><span class="mt-1.5 size-1.5 shrink-0 rounded-full {{ ($check['severity'] ?? 'major') === 'major' ? 'bg-danger' : 'bg-amber-400' }}" aria-hidden="true"></span><span><strong>{{ $check['label'] }}</strong><span class="sr-only"> ({{ $check['severity'] ?? 'major' }})</span><br><span class="text-muted">{{ $check['message'] }}</span></span></li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-muted"><span class="mr-1 inline-block size-1.5 rounded-full bg-danger align-middle"></span>major, makes the rep count as flagged · <span class="mr-1 inline-block size-1.5 rounded-full bg-amber-400 align-middle"></span>minor, lowers the score</p>
            </section>

            @if ($history->isNotEmpty())
                <section class="glass p-5" aria-labelledby="history-title">
                    <h2 id="history-title" class="flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="clock" class="size-4 text-neon"/>Your last sets</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($history as $s)
                            <li class="flex items-center justify-between gap-2">
                                <span class="min-w-0"><span class="block truncate">{{ $s->started_at->format('j M, H:i') }}</span><span class="text-xs text-muted">{{ $s->hold_seconds ? $s->hold_seconds.' s hold' : $s->good_reps.'/'.$s->total_reps.' clean' }}</span></span>
                                <span class="chip {{ $s->form_score_avg >= 80 ? 'chip-neon' : ($s->form_score_avg < 60 ? 'chip-danger' : '') }}"><span class="num text-sm">{{ $s->form_score_avg }}</span></span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </aside>
    </div>
</x-layouts.app>
