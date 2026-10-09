<x-layouts.game :title="$config['session']['title']">
    <script type="application/json" id="game-config">{!! json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>

    <p id="game-loading" class="grid min-h-dvh place-items-center text-sm text-muted" role="status">Loading your workout…</p>
    <noscript><p class="p-8 text-center">Game mode needs JavaScript. Enable it to train, or <a class="text-neon underline" href="{{ route('plan.index') }}">go back to the plan</a>.</p></noscript>

    <div id="game" x-data="workoutGame" x-cloak class="flex min-h-dvh flex-col" @keydown.escape.window="ui.quit = !ui.quit">

        {{-- ============================== top bar ============================== --}}
        <header class="sticky top-0 z-30 border-b border-line bg-bg0/85 pt-[env(safe-area-inset-top)] backdrop-blur-xl">
            <div class="mx-auto flex max-w-6xl items-center gap-2 px-3 py-2.5 sm:px-5">
                <button type="button" class="btn btn-ghost btn-sm btn-icon" @click="ui.quit = true" aria-label="Quit or pause menu"><x-icon name="x" class="size-4"/></button>
                <div class="min-w-0 flex-1">
                    <p class="eyebrow truncate !text-[0.6rem]" x-text="cfg.session.title"></p>
                    <p class="truncate text-sm font-bold sm:text-base"><span class="num text-neon" x-text="`${idx + 1}/${exercises.length}`"></span> <span x-text="current.name"></span></p>
                </div>
                <p class="num text-xl text-neon text-glow sm:text-2xl" x-text="sessionLabel" role="timer" aria-label="Workout time"></p>
                <button type="button" class="btn btn-ghost btn-sm btn-icon" @click="toggleSound()" :aria-pressed="(!muted).toString()" aria-label="Sound effects">
                    <x-icon name="volume" class="size-4" x-show="!muted"/><x-icon name="volume-off" class="size-4" x-show="muted" x-cloak/>
                </button>
                <button type="button" class="btn btn-ghost btn-sm btn-icon" @click="togglePause()" :aria-pressed="paused.toString()" aria-label="Pause workout">
                    <x-icon name="pause" class="size-4" x-show="!paused"/><x-icon name="play" class="size-4" x-show="paused" x-cloak/>
                </button>
            </div>
            <div class="mx-auto flex max-w-6xl gap-1 px-3 pb-2 sm:px-5" aria-hidden="true">
                <template x-for="(p, i) in segments" :key="i">
                    <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-white/10" :class="i === idx && 'ring-1 ring-neon/70'">
                        <i class="block h-full rounded-full bg-neon shadow-[0_0_8px_#39ff14] transition-[width] duration-500" :style="`width:${p * 100}%`"></i>
                    </span>
                </template>
            </div>
            <p x-show="!online || pending > 0" x-cloak class="border-t border-line bg-danger/10 px-3 py-1 text-center text-xs text-[#ff9a9a]" role="status">
                <span x-show="!online">You are offline. </span><span x-text="pending > 0 ? `${pending} set${pending === 1 ? '' : 's'} will sync when you reconnect.` : ''"></span>
            </p>
        </header>

        {{-- ============================== main ============================== --}}
        <main class="mx-auto grid w-full max-w-6xl flex-1 grid-cols-1 gap-4 px-3 py-4 pb-36 sm:px-5 lg:grid-cols-[1.1fr_1fr] lg:pb-8">

            {{-- Left: 3D demo + guidance --}}
            <section aria-label="Exercise demonstration and form guidance" class="min-w-0 space-y-3">
                <div class="glass overflow-hidden">
                    <div class="relative aspect-[4/3] max-h-[44vh] w-full lg:max-h-[56vh]" x-show="ui.demoOpen">
                        <div x-ref="stage" class="h-full w-full"></div>
                        <p x-show="!demo.supported" class="absolute inset-0 grid place-items-center p-6 text-center text-sm text-muted">3D preview needs WebGL, which this browser does not offer. Your workout works the same without it.</p>
                        <span class="chip absolute left-2 top-2 !text-[0.65rem]" x-show="demo.supported"><x-icon name="rotate" class="size-3"/>Drag to rotate</span>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line p-2.5" x-show="demo.supported">
                        <div class="flex items-center gap-1" role="group" aria-label="Camera angle">
                            <template x-for="c in [['front', 'Front'], ['side', 'Side'], ['angle', '45°']]" :key="c[0]">
                                <button type="button" class="btn btn-ghost btn-sm !min-h-9 !px-3 !text-[0.65rem]" :class="demo.cam === c[0] && '!border-neon !text-neon'" @click="setCam(c[0])" :aria-pressed="(demo.cam === c[0]).toString()" x-text="c[1]"></button>
                            </template>
                        </div>
                        <div class="flex items-center gap-1" role="group" aria-label="Playback speed">
                            <template x-for="v in [0.25, 0.5, 1, 1.5]" :key="v">
                                <button type="button" class="btn btn-ghost btn-sm !min-h-9 !px-2.5 !text-[0.65rem]" :class="demo.speed === v && '!border-neon !text-neon'" @click="setSpeed(v)" :aria-pressed="(demo.speed === v).toString()" x-text="v === 0.25 ? 'Slow-mo' : `${v}×`"></button>
                            </template>
                            <button type="button" class="btn btn-ghost btn-sm btn-icon !min-h-9" @click="toggleDemo()" :aria-label="demo.playing ? 'Pause demo' : 'Play demo'"><x-icon name="pause" class="size-4" x-show="demo.playing"/><x-icon name="play" class="size-4" x-show="!demo.playing" x-cloak/></button>
                        </div>
                    </div>
                </div>

                <div class="glass p-4">
                    <div role="tablist" aria-label="Exercise guidance" class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1">
                        <template x-for="t in [['form', 'Form cues'], ['mistakes', 'Common mistakes'], ['breathing', 'Breathing'], ['muscles', 'Muscles']]" :key="t[0]">
                            <button type="button" role="tab" class="chip shrink-0 !min-h-9 cursor-pointer !px-3" :class="ui.tab === t[0] && 'chip-neon !bg-neon/15'" :aria-selected="(ui.tab === t[0]).toString()" @click="ui.tab = t[0]" x-text="t[1]"></button>
                        </template>
                    </div>
                    <div class="mt-3 text-sm" role="tabpanel">
                        <ol x-show="ui.tab === 'form'" class="space-y-2">
                            <template x-for="(c, i) in current.cues" :key="i"><li class="flex gap-3"><span class="num grid size-6 shrink-0 place-items-center rounded-md bg-neon/10 text-neon" x-text="i + 1"></span><span x-text="c"></span></li></template>
                        </ol>
                        <ul x-show="ui.tab === 'mistakes'" x-cloak class="space-y-2">
                            <template x-for="(m, i) in current.mistakes" :key="i"><li class="flex gap-3"><x-icon name="x" class="mt-0.5 size-4 shrink-0 text-danger"/><span x-text="m"></span></li></template>
                        </ul>
                        <p x-show="ui.tab === 'breathing'" x-cloak class="leading-relaxed text-muted" x-text="current.breathing || 'Breathe steadily and never hold your breath for long.'"></p>
                        <div x-show="ui.tab === 'muscles'" x-cloak class="space-y-3">
                            <div><p class="label !mb-1">Primary</p><div class="flex flex-wrap gap-1.5"><template x-for="r in current.regions.primary" :key="r"><span class="chip chip-neon" x-text="cfg.regions[r] || r"></span></template></div></div>
                            <div x-show="current.regions.secondary.length"><p class="label !mb-1">Assisting</p><div class="flex flex-wrap gap-1.5"><template x-for="r in current.regions.secondary" :key="r"><span class="chip" x-text="cfg.regions[r] || r"></span></template></div></div>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a class="btn btn-ghost btn-sm" x-show="current.postureUrl" :href="current.postureUrl" @click="persist(true)"><x-icon name="scan" class="size-4"/>Check my posture</a>
                        <button type="button" class="btn btn-ghost btn-sm" x-show="current.canSwap && current.sets.length === 0" @click="openSwap()"><x-icon name="swap" class="size-4"/>Swap exercise</button>
                        <button type="button" class="btn btn-ghost btn-sm" @click="toggleMetronome()" :aria-pressed="metro.on.toString()"><x-icon name="music" class="size-4"/><span x-text="metro.on ? 'Tempo on' : 'Tempo beat'"></span></button>
                        <button type="button" class="btn btn-ghost btn-sm" @click="toggleVoice()" :aria-pressed="voiceOn.toString()"><x-icon name="mic" class="size-4"/><span x-text="voiceOn ? 'Voice coach on' : 'Voice coach'"></span></button>
                    </div>
                    <div x-show="metro.on" x-cloak class="mt-3 flex items-center gap-3 rounded-xl border border-line p-3">
                        <span class="size-4 rounded-full bg-neon/30 transition" :class="metro.beat === 2 ? '!bg-neon shadow-[0_0_14px_#39ff14] scale-125' : metro.beat === 1 ? '!bg-neon/70' : ''" aria-hidden="true"></span>
                        <label class="flex-1 text-xs text-muted">Tempo <span class="num text-neon" x-text="metro.bpm"></span> beats/min
                            <input type="range" min="30" max="120" step="5" class="mt-1 block w-full accent-[#39ff14]" :value="metro.bpm" @input="setBpm($event.target.value)" aria-label="Metronome beats per minute"></label>
                    </div>
                </div>
            </section>

            {{-- Right: tracker --}}
            <section class="min-w-0" aria-labelledby="tracker-title">
                <div class="glass glass-neon p-4 sm:p-5 lg:sticky lg:top-28">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="eyebrow" x-text="`${current.groupLabel} · ${current.mechanic}`"></p>
                            <h1 id="tracker-title" class="mt-1 text-xl font-black leading-tight sm:text-2xl" x-text="current.name"></h1>
                            <p class="mt-0.5 text-sm text-muted" x-show="current.focus" x-text="current.focus"></p>
                            <p class="chip chip-neon mt-2 !text-[0.65rem]" x-show="current.swappedFrom" x-cloak><x-icon name="swap" class="size-3"/><span x-text="`Swapped from ${current.swappedFrom}`"></span></p>
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm shrink-0" @click="ui.jump = true" aria-label="Exercises"><x-icon name="list" class="size-4"/><span class="hidden sm:inline">Exercises</span></button>
                    </div>

                    <ul class="mt-3 flex flex-wrap gap-2 text-xs">
                        <li class="chip chip-neon"><span class="num text-sm" x-text="`${current.targetSets} × ${current.repsMin}-${current.repsMax}`"></span></li>
                        <li class="chip"><x-icon name="timer" class="size-3.5"/>Rest <span x-text="current.restLabel"></span></li>
                        <li class="chip" x-show="lastText" x-cloak>Last: <span x-text="lastText"></span></li>
                    </ul>
                    <p class="mt-2.5 rounded-lg border border-line bg-neon/5 px-3 py-2 text-xs leading-relaxed" x-text="current.reason"></p>

                    {{-- set indicators --}}
                    <ol class="mt-4 flex flex-wrap gap-2" aria-label="Sets">
                        <template x-for="n in setSlots" :key="n">
                            <li class="grid min-h-12 min-w-14 flex-1 place-items-center rounded-xl border px-2 py-1 text-center transition"
                                :class="current.sets.some(s => s.number === n + 1) ? 'border-neon bg-neon/15 text-neon' : (n === setIdx ? 'border-neon/80 bg-neon/5 text-ink shadow-[0_0_14px_rgba(57,255,20,.3)]' : 'border-line text-muted')">
                                <span class="num text-sm" x-show="current.sets.some(s => s.number === n + 1)" x-text="(() => { const s = current.sets.find(s => s.number === n + 1); return s ? (s.reps !== null ? `${s.weight ?? 'BW'}×${s.reps}` : `${s.duration}s`) : ''; })()"></span>
                                <span class="num text-sm" x-show="!current.sets.some(s => s.number === n + 1)" x-text="`Set ${n + 1}`"></span>
                                <x-icon name="star" class="size-3 text-neon" x-show="current.sets.some(s => s.number === n + 1 && s.isPr)" x-cloak/>
                            </li>
                        </template>
                    </ol>

                    {{-- weight --}}
                    <div class="mt-4">
                        <label class="label" for="weight-input">Weight (kg)</label>
                        <div class="flex items-center gap-2">
                            <button type="button" class="btn btn-ghost btn-icon" @click="bumpWeight(-1)" aria-label="Decrease weight by 2.5 kilograms"><x-icon name="minus" class="size-5"/></button>
                            <input id="weight-input" type="number" inputmode="decimal" min="0" max="1000" step="0.5" x-model.number="weight" placeholder="BW" class="input num !text-2xl text-center">
                            <button type="button" class="btn btn-ghost btn-icon" @click="bumpWeight(1)" aria-label="Increase weight by 2.5 kilograms"><x-icon name="plus" class="size-5"/></button>
                        </div>
                    </div>

                    {{-- reps (or hold timer) --}}
                    <div class="mt-4" x-show="!current.isTimed">
                        <p class="label text-center">Reps <span class="normal-case tracking-normal">(tap once per rep)</span></p>
                        <div class="flex items-center justify-center gap-4">
                            <button type="button" class="btn btn-ghost btn-icon" @click="bumpReps(-1)" aria-label="Remove one rep"><x-icon name="minus" class="size-5"/></button>
                            <button type="button" @click="tapRep()" class="grid size-40 place-items-center rounded-full border-2 border-neon/70 bg-neon/5 shadow-[0_0_44px_rgba(57,255,20,.2)] transition active:scale-95 active:bg-neon/15 sm:size-44" aria-live="off" :aria-label="`${reps} target ${current.repsMin}-${current.repsMax}, tap to add a rep`">
                                <span class="flex flex-col items-center leading-none"><span class="num text-8xl text-neon text-glow" x-text="reps"></span> <span class="mt-1 text-[0.65rem] uppercase tracking-widest text-muted" x-text="`target ${current.repsMin}-${current.repsMax}`"></span></span>
                            </button>
                            <button type="button" class="btn btn-ghost btn-icon" @click="bumpReps(1)" aria-label="Add one rep"><x-icon name="plus" class="size-5"/></button>
                        </div>
                        <div class="mt-3 flex flex-wrap justify-center gap-1.5" aria-label="Set the rep count directly">
                            <template x-for="r in repChips" :key="r"><button type="button" class="chip cursor-pointer !min-h-9 !px-3.5" :class="reps === r && 'chip-neon !bg-neon/20'" @click="setReps(r)" x-text="r"></button></template>
                        </div>
                    </div>

                    <div class="mt-4 text-center" x-show="current.isTimed" x-cloak>
                        <p class="label">Hold timer</p>
                        <p class="num text-7xl text-neon text-glow" x-text="holdRunningLabel" role="timer"></p>
                        <div class="bar mx-auto mt-2 max-w-xs"><i :style="`width:${holdProgress * 100}%`" style="transition:none"></i></div>
                        <button type="button" class="btn btn-ghost mt-3" @click="toggleHold()"><x-icon name="timer" class="size-4"/><span x-text="workStartedAt === null ? 'Start timer' : 'Stop timer'"></span></button>
                    </div>

                    <button type="button" class="btn btn-primary btn-lg mt-5 hidden w-full lg:flex" @click="completeSet()" :disabled="phase !== 'work' || paused">
                        <x-icon name="check" class="size-5"/><span x-text="isLastSet ? 'Complete last set' : 'Complete set'"></span>
                    </button>
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs">
                        <button type="button" class="font-semibold text-muted underline-offset-4 hover:text-ink hover:underline" @click="ui.skip = true">Skip this exercise</button>
                        <span class="text-muted"><span class="num text-ink" x-text="totalSetsDone"></span> sets · <span class="num text-ink" x-text="volume.toLocaleString()"></span> kg</span>
                    </div>
                </div>
            </section>
        </main>

        {{-- Sticky action bar (phones) --}}
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-bg0/90 px-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pt-3 backdrop-blur-xl lg:hidden">
            <button type="button" class="btn btn-primary btn-lg w-full" @click="completeSet()" :disabled="phase !== 'work' || paused">
                <x-icon name="check" class="size-5"/><span x-text="isLastSet ? 'Complete last set' : 'Complete set'"></span>
            </button>
        </div>

        <p class="sr-only" role="status" aria-live="polite" x-text="announce"></p>

        {{-- ============================== overlays ============================== --}}

        {{-- Ready / countdown --}}
        <div x-show="phase === 'ready' || phase === 'intro'" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-bg0/97 p-6 text-center" role="dialog" aria-modal="true" aria-labelledby="ready-title">
            <div x-show="phase === 'ready'" class="mx-auto max-w-md">
                <p class="eyebrow">Ready?</p>
                <h2 id="ready-title" class="mt-3 text-3xl font-black uppercase sm:text-4xl" x-text="cfg.session.title"></h2>
                <p class="mt-3 text-muted"><span x-text="exercises.length"></span> exercises · <span x-text="exercises.reduce((n, e) => n + e.targetSets, 0)"></span> sets. Rest timers, form cues and a 3D demo guide every move.</p>
                <button type="button" class="btn btn-primary btn-lg mt-8 w-full animate-pulse-neon" @click="begin()"><x-icon name="play" class="size-5"/><span x-text="totalSetsDone > 0 ? 'Resume workout' : 'Start workout'"></span></button>
                <a :href="cfg.urls.plan" class="mt-4 inline-block text-sm text-muted underline underline-offset-4">Not now</a>
            </div>
            <div x-show="phase === 'intro'" x-cloak aria-live="assertive">
                <template x-for="n in [introCount]" :key="n"><p class="intro-num num text-[9rem] leading-none text-neon text-glow sm:text-[13rem]" x-text="n"></p></template>
            </div>
        </div>

        {{-- Rest --}}
        <div x-show="phase === 'rest' && !paused" x-cloak class="fixed inset-0 z-40 overflow-y-auto bg-bg0/97 backdrop-blur-md" role="dialog" aria-modal="true" aria-labelledby="rest-title">
            <div class="mx-auto flex min-h-dvh max-w-md flex-col items-center justify-center gap-5 p-6 text-center">
                <p id="rest-title" class="eyebrow">Rest</p>
                <div class="relative grid place-items-center">
                    <svg width="230" height="230" viewBox="0 0 230 230" class="-rotate-90 overflow-visible" aria-hidden="true">
                        <circle cx="115" cy="115" r="90" fill="none" stroke-width="10" class="gauge-track" style="stroke: rgba(255,255,255,.08)"/>
                        <circle cx="115" cy="115" r="90" fill="none" stroke-width="10" stroke-linecap="round" style="stroke:#39ff14;filter:drop-shadow(0 0 7px rgba(57,255,20,.85));transition:stroke-dashoffset .3s linear" :stroke-dasharray="2 * Math.PI * 90" :stroke-dashoffset="restRingOffset"/>
                    </svg>
                    <p class="num absolute text-6xl text-neon text-glow" x-text="restLabel" role="timer" aria-label="Rest time remaining"></p>
                </div>
                <div class="flex w-full gap-2">
                    <button type="button" class="btn btn-ghost flex-1" @click="addRest(15)"><x-icon name="plus" class="size-4"/>15 s</button>
                    <button type="button" class="btn btn-primary flex-1" @click="skipRest()"><x-icon name="skip" class="size-4"/>Skip rest</button>
                </div>
                <div class="glass w-full p-4 text-left" x-show="nextExercise">
                    <p class="eyebrow !text-[0.6rem]">Next up</p>
                    <p class="mt-1 text-lg font-black" x-text="nextTitle"></p>
                    <p class="text-sm text-muted" x-show="nextPos && nextPos.idx === idx" x-text="current.name"></p>
                    <p class="mt-1 text-sm text-neon" x-text="nextTarget"></p>
                    <div class="mt-3 flex items-center gap-2">
                        <button type="button" class="btn btn-ghost btn-sm btn-icon" @click="bumpNextWeight(-1)" aria-label="Lower the next weight"><x-icon name="minus" class="size-4"/></button>
                        <p class="num flex-1 text-center text-xl"><span x-text="nextWeightLabel"></span> <span class="text-sm text-muted">kg</span></p>
                        <button type="button" class="btn btn-ghost btn-sm btn-icon" @click="bumpNextWeight(1)" aria-label="Raise the next weight"><x-icon name="plus" class="size-4"/></button>
                    </div>
                </div>
                <button type="button" class="text-sm text-muted underline underline-offset-4" @click="undoLastSet()" x-show="prevPos">Undo the set I just logged</button>
            </div>
        </div>

        {{-- Paused --}}
        <div x-show="paused && phase !== 'ready' && phase !== 'intro'" x-cloak class="fixed inset-0 z-[45] grid place-items-center bg-bg0/97 p-6 text-center" role="dialog" aria-modal="true" aria-labelledby="paused-title">
            <div class="max-w-sm">
                <x-icon name="pause" class="mx-auto size-12 text-neon"/>
                <h2 id="paused-title" class="mt-3 text-3xl font-black uppercase">Paused</h2>
                <p class="mt-2 text-muted">Your workout and rest timers are on hold.</p>
                <button type="button" class="btn btn-primary btn-lg mt-6 w-full" @click="togglePause()"><x-icon name="play" class="size-5"/>Resume</button>
            </div>
        </div>

        {{-- Finish --}}
        <div x-show="phase === 'finish'" x-cloak class="fixed inset-0 z-40 grid place-items-center overflow-y-auto bg-bg0/97 p-6 text-center" role="dialog" aria-modal="true" aria-labelledby="finish-title">
            <div class="mx-auto w-full max-w-md">
                <x-icon name="trophy" class="mx-auto size-14 text-neon"/>
                <h2 id="finish-title" class="mt-3 text-3xl font-black uppercase text-glow">All sets done!</h2>
                <dl class="mt-6 grid grid-cols-3 gap-2">
                    <div class="glass p-3"><dt class="label !mb-0">Sets</dt><dd class="num text-2xl text-neon" x-text="totalSetsDone"></dd></div>
                    <div class="glass p-3"><dt class="label !mb-0">Volume</dt><dd class="num text-2xl text-neon" x-text="`${volume.toLocaleString()}`"></dd></div>
                    <div class="glass p-3"><dt class="label !mb-0">Time</dt><dd class="num text-2xl text-neon" x-text="sessionLabel"></dd></div>
                </dl>
                <p class="error mt-4" x-show="error" x-text="error" role="alert"></p>
                <button type="button" class="btn btn-primary btn-lg mt-6 w-full" @click="finishWorkout()" :disabled="ui.finishing"><x-icon name="check" class="size-5"/><span x-text="ui.finishing ? 'Saving…' : 'Finish and see results'"></span></button>
                <button type="button" class="btn btn-ghost mt-3 w-full" @click="addExtraSet()"><x-icon name="plus" class="size-4"/>Add an extra set</button>
            </div>
        </div>

        {{-- Quit menu --}}
        <div x-show="ui.quit" x-cloak class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="quit-title" x-data="{ confirmAbandon: false }" x-effect="if (!ui.quit) confirmAbandon = false">
            <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="ui.quit = false"></div>
            <div class="glass relative w-full max-w-sm rounded-b-none p-5 sm:rounded-b-[1.1rem]" x-trap.noscroll="ui.quit">
                <h2 id="quit-title" class="text-lg font-black uppercase">Leave this workout?</h2>
                <p class="mt-1 text-sm text-muted">Your progress is saved automatically, so you can come back to this exact spot.</p>
                <div class="mt-4 space-y-2" x-show="!confirmAbandon">
                    <button type="button" class="btn btn-primary w-full" @click="ui.quit = false">Keep training</button>
                    <button type="button" class="btn btn-ghost w-full" @click="saveAndExit()">Save and leave for now</button>
                    <button type="button" class="btn btn-ghost w-full" x-show="totalSetsDone > 0" @click="ui.quit = false; phase = 'finish'">Finish the workout here</button>
                    <button type="button" class="btn btn-danger w-full" @click="confirmAbandon = true">Abandon this workout</button>
                </div>
                <div class="mt-4 space-y-2" x-show="confirmAbandon" x-cloak>
                    <p class="rounded-lg border border-danger/40 bg-danger/10 p-3 text-sm">Abandoning discards this session. Sets you already logged stay in your history but the day will not count as complete.</p>
                    <button type="button" class="btn btn-danger w-full" @click="abandon()">Yes, abandon workout</button>
                    <button type="button" class="btn btn-ghost w-full" @click="confirmAbandon = false">Go back</button>
                    <p class="error" x-show="error" x-text="error" role="alert"></p>
                </div>
            </div>
        </div>

        {{-- Skip exercise --}}
        <div x-show="ui.skip" x-cloak class="fixed inset-0 z-[60] grid place-items-center p-4" role="alertdialog" aria-modal="true" aria-labelledby="skip-title">
            <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="ui.skip = false"></div>
            <div class="glass relative w-full max-w-sm p-5" x-trap.noscroll="ui.skip">
                <h2 id="skip-title" class="text-lg font-black uppercase">Skip <span x-text="current.name"></span>?</h2>
                <p class="mt-1 text-sm text-muted">Machine taken or something hurts? Skipping moves you on. Sets you already did are kept.</p>
                <div class="mt-4 flex gap-2"><button type="button" class="btn btn-ghost flex-1" @click="ui.skip = false">Cancel</button><button type="button" class="btn btn-danger flex-1" @click="skipExercise()">Skip it</button></div>
            </div>
        </div>

        {{-- Jump list --}}
        <div x-show="ui.jump" x-cloak class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="jump-title">
            <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="ui.jump = false"></div>
            <div class="glass relative max-h-[85dvh] w-full max-w-md overflow-y-auto rounded-b-none p-4 sm:rounded-b-[1.1rem]" x-trap.noscroll="ui.jump">
                <div class="mb-3 flex items-center justify-between"><h2 id="jump-title" class="text-lg font-black uppercase">Exercises</h2><button type="button" class="btn btn-ghost btn-sm btn-icon" @click="ui.jump = false" aria-label="Close"><x-icon name="x" class="size-4"/></button></div>
                <ol class="space-y-2">
                    <template x-for="(ex, i) in exercises" :key="ex.id">
                        <li><button type="button" class="glass glass-hover flex w-full items-center gap-3 p-3 text-left" :class="i === idx && '!border-neon'" :disabled="ex.status === 'skipped'" @click="jumpTo(i)">
                            <span class="num grid size-8 shrink-0 place-items-center rounded-lg border border-line-strong text-neon" x-text="i + 1"></span>
                            <span class="min-w-0 flex-1"><span class="block truncate font-semibold" x-text="ex.name"></span><span class="text-xs text-muted" x-text="ex.status === 'skipped' ? 'Skipped' : `${ex.sets.length}/${ex.targetSets} sets`"></span></span>
                            <x-icon name="check" class="size-4 text-neon" x-show="ex.status === 'done' || ex.sets.length >= ex.targetSets"/>
                        </button></li>
                    </template>
                </ol>
            </div>
        </div>

        {{-- Swap --}}
        <div x-show="ui.swap" x-cloak class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="swap-title">
            <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="ui.swap = false"></div>
            <div class="glass relative max-h-[85dvh] w-full max-w-md overflow-y-auto rounded-b-none p-4 sm:rounded-b-[1.1rem]" x-trap.noscroll="ui.swap">
                <div class="mb-1 flex items-center justify-between"><h2 id="swap-title" class="text-lg font-black uppercase">Swap exercise</h2><button type="button" class="btn btn-ghost btn-sm btn-icon" @click="ui.swap = false" aria-label="Close"><x-icon name="x" class="size-4"/></button></div>
                <p class="mb-3 text-xs text-muted">Same muscle group, using your equipment. Only available before you log a set.</p>
                <p class="error" x-show="ui.swapError" x-text="ui.swapError" role="alert"></p>
                <p class="py-6 text-center text-sm text-muted" x-show="ui.swapLoading">Loading…</p>
                <ul class="space-y-2">
                    <template x-for="alt in ui.alternatives" :key="alt.id">
                        <li><button type="button" class="glass glass-hover w-full p-3 text-left" :disabled="ui.swapLoading" @click="chooseSwap(alt.id)"><span class="block font-semibold" x-text="alt.name"></span><span class="text-xs text-muted" x-text="`${alt.mechanic} · ${alt.difficulty}`"></span></button></li>
                    </template>
                </ul>
            </div>
        </div>

        {{-- Toast --}}
        <div class="pointer-events-none fixed inset-x-0 top-20 z-[70] flex justify-center px-4" aria-live="polite">
            <p x-show="ui.toastText" x-transition.opacity x-cloak class="glass max-w-sm px-4 py-2.5 text-center text-sm font-semibold" :class="ui.toastKind === 'pr' ? '!border-neon text-neon shadow-[0_0_24px_rgba(57,255,20,.4)]' : ui.toastKind === 'warn' ? '!border-danger/60 text-[#ff9a9a]' : ''" x-text="ui.toastText"></p>
        </div>
    </div>
</x-layouts.game>
