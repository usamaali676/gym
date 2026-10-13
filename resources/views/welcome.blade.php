<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#07090A">
    <meta name="description" content="IronPulse turns your push / pull / legs plan into a guided, game-like training experience with an AI posture checker and nutrition tools.">
    <title>{{ config('app.name') }} · Train. Track. Transform.</title>
    <x-pwa-head/>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body x-data>
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[100] focus:rounded-lg focus:bg-neon focus:px-4 focus:py-2 focus:text-[#041006]">Skip to content</a>
    <div class="bg-grid" aria-hidden="true"></div>
    <canvas class="particles" aria-hidden="true"></canvas>

    <header class="mx-auto flex max-w-6xl items-center justify-between px-4 py-5 sm:px-6">
        <x-logo/>
        <nav class="flex items-center gap-2" aria-label="Account">
            @auth
                <a href="{{ route('dashboard') }}" class="btn btn-primary btn-sm">Open app</a>
            @else
                <a href="{{ route('login') }}" class="btn btn-ghost btn-sm">Log in</a>
                <a href="{{ route('register') }}" class="btn btn-primary btn-sm hidden sm:inline-flex">Sign up</a>
            @endauth
        </nav>
    </header>

    <main id="main" tabindex="-1">
        <section class="mx-auto max-w-6xl px-4 pb-16 pt-10 text-center sm:px-6 sm:pt-20">
            <p class="eyebrow rise">Push · Pull · Legs · Recover</p>
            <h1 class="rise mx-auto mt-5 max-w-4xl text-4xl font-black uppercase leading-[1.05] sm:text-6xl lg:text-7xl" style="--i:1">
                Train. Track.<br><span class="text-neon text-glow">Transform.</span>
            </h1>
            <p class="rise mx-auto mt-6 max-w-2xl text-base leading-relaxed text-muted sm:text-lg" style="--i:2">
                Your 5-day plan becomes a guided, game-like session with 3D form demos, an on-device AI posture checker,
                progressive-overload targets and a nutrition coach. Everything runs in your browser. Your camera never leaves your device.
            </p>
            <div class="rise mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row" style="--i:3">
                @auth
                    <a href="{{ route('dashboard') }}" class="btn btn-primary btn-lg">Go to dashboard <x-icon name="chevron-right" class="size-5"/></a>
                @else
                    <a href="{{ route('register') }}" class="btn btn-primary btn-lg">Create free account <x-icon name="chevron-right" class="size-5"/></a>
                    @if ($guestMode)
                        <form method="POST" action="{{ route('guest.start') }}">@csrf
                            <button class="btn btn-ghost btn-lg"><x-icon name="play" class="size-5"/>Try as guest</button>
                        </form>
                    @endif
                @endauth
            </div>
        </section>

        @if ($plan && $plan->days->isNotEmpty())
            <section class="mx-auto max-w-6xl px-4 pb-16 sm:px-6" aria-labelledby="split-heading">
                <h2 id="split-heading" class="eyebrow mb-4 text-center">The weekly split</h2>
                <ol class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                    @foreach ($plan->days as $i => $d)
                        <li class="glass rise p-4 text-center {{ $d->is_rest ? 'opacity-70' : 'glass-hover' }}" style="--i: {{ $i }}">
                            <p class="text-xs font-semibold uppercase tracking-widest text-muted">{{ substr($d->name, 0, 3) }}</p>
                            <p class="mt-2 font-display text-lg font-black {{ $d->is_rest ? 'text-muted' : 'text-neon' }}">{{ $d->label }}</p>
                            <p class="mt-1 text-xs text-muted">{{ $d->focus }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif

        <section class="mx-auto max-w-6xl px-4 pb-24 sm:px-6" aria-labelledby="features-heading">
            <h2 id="features-heading" class="eyebrow mb-6 text-center">Built for lifters</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['dumbbell', 'Game-mode workouts', 'Full-screen guided sessions with countdowns, set tracking, rest timers and a 3D demo for every move.'],
                    ['scan', 'AI posture checker', 'MediaPipe runs on your device. Your skeleton glows green when form is right and red when it is not.'],
                    ['target', 'Progressive overload', 'Beat last week. The app suggests your next weight or rep target after every session.'],
                    ['utensils', 'Diet engine', 'BMR, TDEE and macro targets for cutting, bulking, maintenance or recomposition.'],
                    ['apple', 'Protein and macro log', 'Search 150+ foods, log servings and watch protein, carbs, fat and fibre climb toward your goal.'],
                    ['moon', 'Recovery that counts', 'Creatine streak, sleep log, hydration and mobility timers, because muscle is built during recovery.'],
                ] as $i => [$icon, $title, $copy])
                    <article class="glass glass-hover rise p-6" style="--i: {{ $i }}">
                        <span class="grid size-11 place-items-center rounded-xl border border-line-strong bg-neon/10 text-neon"><x-icon :name="$icon" class="size-6"/></span>
                        <h3 class="mt-4 text-base font-bold uppercase">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-muted">{{ $copy }}</p>
                    </article>
                @endforeach
            </div>
        </section>
    </main>

    <footer class="border-t border-line px-4 py-8 text-center text-xs text-muted">
        <x-credit class="!mt-0 mb-6"/>
        <p>{{ config('app.name') }}. General fitness guidance only, not medical advice.</p>
    </footer>
</body>
</html>
