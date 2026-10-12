@props(['title' => null, 'page' => null, 'bare' => false])
@php
    $user = auth()->user();
    $center = $navItems->first(fn ($i) => ($i['tab'] ?? null) === 'center');
    $plainTabs = $navItems->filter(fn ($i) => ($i['tab'] ?? false) === true)->values();
    $leftTabs = $plainTabs->take(2);
    $rightTabs = $plainTabs->slice(2, 1);
    $xpProgress = $navXp;
    $streak = $navStreak;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#07090A">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <x-pwa-head/>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{ $head ?? '' }}
</head>
<body @if($page) data-page="{{ $page }}" @endif x-data="appShell" @keydown.escape.window="moreOpen = false">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[100] focus:rounded-lg focus:bg-neon focus:px-4 focus:py-2 focus:text-[#041006]">Skip to content</a>
    <div class="bg-grid" aria-hidden="true"></div>
    <canvas class="particles" aria-hidden="true"></canvas>

    {{-- Desktop sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-30 hidden flex-col border-r border-line bg-bg0/80 backdrop-blur-xl transition-[width] duration-300 lg:flex"
           :class="collapsed ? 'w-[4.75rem]' : 'w-64'" aria-label="Sidebar">
        <div class="flex h-16 items-center px-4">
            <a href="{{ route('dashboard') }}" aria-label="{{ config('app.name') }} home"><x-logo :icon-only="false" x-show="!collapsed"/></a>
            <a href="{{ route('dashboard') }}" x-show="collapsed" x-cloak aria-label="{{ config('app.name') }} home" class="mx-auto"><x-logo :icon-only="true"/></a>
        </div>
        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-2" aria-label="Primary">
            @foreach ($navItems as $item)
                <a href="{{ $item['url'] }}" class="nav-link" @if($item['active']) aria-current="page" @endif :title="collapsed ? @js($item['label']) : null" aria-label="{{ $item['label'] }}">
                    <x-icon :name="$item['icon']" class="size-5"/>
                    <span x-show="!collapsed" class="truncate">{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="space-y-2 border-t border-line p-3">
            <a href="{{ route('profile.edit') }}" class="flex items-center gap-3 rounded-xl p-2 hover:bg-neon/5">
                <span class="grid size-9 shrink-0 place-items-center rounded-full border border-line-strong bg-surface2 font-display text-sm font-bold text-neon">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                <span x-show="!collapsed" class="min-w-0 leading-tight">
                    <span class="block truncate text-sm font-semibold">{{ $user->name }}</span>
                    <span class="block text-xs text-muted">{{ $user->is_guest ? 'Guest account' : 'Level '.$xpProgress['level'] }}</span>
                </span>
            </a>
            <div class="flex gap-2">
                <form method="POST" action="{{ route('logout') }}" class="flex-1">@csrf
                    <button class="btn btn-ghost btn-sm w-full" aria-label="Log out"><x-icon name="logout" class="size-4"/><span x-show="!collapsed">Log out</span></button>
                </form>
                <button type="button" class="btn btn-ghost btn-sm btn-icon" @click="toggleSidebar()" :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'" :aria-expanded="(!collapsed).toString()">
                    <x-icon name="chevron-left" class="size-4 transition-transform" ::class="collapsed && 'rotate-180'"/>
                </button>
            </div>
        </div>
    </aside>

    <div class="min-h-dvh transition-[padding] duration-300" :class="collapsed ? 'lg:pl-[4.75rem]' : 'lg:pl-64'">
        {{-- Top bar --}}
        <header class="sticky top-0 z-20 border-b border-line bg-bg0/70 backdrop-blur-xl">
            <div class="mx-auto flex h-14 max-w-7xl items-center justify-between gap-3 px-4 sm:px-6 lg:h-16 lg:px-8">
                <a href="{{ route('dashboard') }}" class="lg:hidden" aria-label="{{ config('app.name') }} home"><x-logo/></a>
                <p class="hidden font-display text-sm font-bold uppercase tracking-[0.2em] text-muted lg:block">{{ $title }}</p>
                <div class="flex items-center gap-2">
                    <span class="chip" title="Workout streak (training days in a row)"><x-icon name="flame" class="size-4 text-neon"/><span class="num text-neon">{{ $streak }}</span><span class="sr-only">day streak</span></span>
                    <a href="{{ Route::has('badges.index') ? route('badges.index') : route('profile.edit') }}" class="chip chip-neon" title="{{ number_format($xpProgress['into']) }} / {{ number_format($xpProgress['needed']) }} XP to next level">
                        <x-icon name="bolt" class="size-4"/><span class="num">LV {{ $xpProgress['level'] }}</span>
                    </a>
                </div>
            </div>
            @if ($user->is_guest)
                <div class="border-t border-line bg-neon/5">
                    <p class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 px-4 py-2 text-xs sm:px-6 lg:px-8">
                        <span class="text-muted">You are using a guest account. Save it so your progress follows you to any device.</span>
                        <a class="font-semibold text-neon underline underline-offset-4" href="{{ route('account.claim') }}">Save my progress</a>
                    </p>
                </div>
            @endif
        </header>

        <main id="main" class="page-in mx-auto max-w-7xl px-4 pb-32 pt-6 sm:px-6 lg:px-8 lg:pb-14 {{ $bare ? '' : '' }}" tabindex="-1">
            <x-flash/>
            {{ $slot }}
        </main>
    </div>

    {{-- Mobile bottom tab bar --}}
    <nav class="tabbar lg:hidden" aria-label="Primary mobile">
        <div class="mx-auto grid max-w-lg grid-cols-5 items-end px-1">
            @foreach ($leftTabs as $item)
                <a href="{{ $item['url'] }}" class="tab" @if($item['active']) aria-current="page" @endif><x-icon :name="$item['icon']" class="size-6"/><span>{{ $item['label'] }}</span></a>
            @endforeach
            @for ($i = $leftTabs->count(); $i < 2; $i++) <span aria-hidden="true"></span> @endfor
            @if ($center)
                <a href="{{ $center['url'] }}" class="tab tab-center" aria-label="{{ $center['label'] }}" @if($center['active']) aria-current="page" @endif><x-icon :name="$center['icon']" class="size-7"/></a>
            @else
                <span aria-hidden="true"></span>
            @endif
            @foreach ($rightTabs as $item)
                <a href="{{ $item['url'] }}" class="tab" @if($item['active']) aria-current="page" @endif><x-icon :name="$item['icon']" class="size-6"/><span>{{ $item['label'] }}</span></a>
            @endforeach
            @for ($i = $rightTabs->count(); $i < 1; $i++) <span aria-hidden="true"></span> @endfor
            <button type="button" class="tab" @click="moreOpen = true" :aria-expanded="moreOpen.toString()" aria-haspopup="dialog"><x-icon name="grid" class="size-6"/><span>More</span></button>
        </div>
    </nav>

    {{-- Mobile "More" sheet --}}
    <div x-show="moreOpen" x-cloak class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="All sections">
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="moreOpen = false" x-transition.opacity></div>
        <div class="glass absolute inset-x-0 bottom-0 max-h-[85dvh] overflow-y-auto rounded-b-none p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
             x-show="moreOpen" x-transition:enter="transition duration-200" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
             x-transition:leave="transition duration-150" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full" x-trap.noscroll="moreOpen">
            <div class="mb-3 flex items-center justify-between">
                <p class="eyebrow">Everything</p>
                <button type="button" class="btn btn-ghost btn-sm btn-icon" @click="moreOpen = false" aria-label="Close menu"><x-icon name="x" class="size-4"/></button>
            </div>
            <div class="grid grid-cols-3 gap-2">
                @foreach ($navItems as $item)
                    <a href="{{ $item['url'] }}" class="glass glass-hover flex flex-col items-center gap-1.5 px-2 py-3 text-center text-xs font-medium {{ $item['active'] ? 'text-neon' : '' }}" @if($item['active']) aria-current="page" @endif>
                        <x-icon :name="$item['icon']" class="size-6"/>{{ $item['label'] }}
                    </a>
                @endforeach
            </div>
            <x-install-app class="mt-3"/>
            <form method="POST" action="{{ route('logout') }}" class="mt-3">@csrf
                <button class="btn btn-ghost w-full"><x-icon name="logout" class="size-4"/>Log out</button>
            </form>
        </div>
    </div>
    @if ($reminderConfig)
        <script type="application/json" id="reminder-config">@json($reminderConfig)</script>
    @endif
    {{ $scripts ?? '' }}
</body>
</html>
