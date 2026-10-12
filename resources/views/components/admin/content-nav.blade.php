<nav class="mb-5 flex flex-wrap gap-4 text-sm font-semibold" aria-label="App content sections">
    @foreach ([['Announcements', 'admin.announcements.index', 'admin.announcements.*'], ['Banners', 'admin.banners.index', 'admin.banners.*'], ['Help articles', 'admin.help.index', 'admin.help.*'], ['Settings', 'admin.settings.edit', 'admin.settings.*']] as [$label, $route, $match])
        <a href="{{ route($route) }}" class="underline-offset-4 {{ request()->routeIs($match) ? 'text-neon underline' : 'text-muted hover:text-ink' }}" @if(request()->routeIs($match)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
