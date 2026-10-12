@props(['title', 'section' => 'index', 'page' => null, 'heading' => null, 'lead' => null])
@php
    $tabs = [
        'index' => ['Overview', 'admin.index', 'admin.index'],
        'users' => ['Users', 'admin.users.index', 'admin.users.*'],
        'memberships' => ['Memberships', 'admin.memberships.index', 'admin.memberships.*|admin.subscriptions.*'],
        'programs' => ['Programs', 'admin.programs.index', 'admin.programs.*|admin.plan.*'],
        'exercises' => ['Exercises', 'admin.exercises.index', 'admin.exercises.*'],
        'posture' => ['Posture rules', 'admin.posture.index', 'admin.posture.*'],
        'foods' => ['Foods', 'admin.foods.index', 'admin.foods.*'],
        'content' => ['App content', 'admin.announcements.index', 'admin.announcements.*|admin.banners.*|admin.help.*|admin.settings.*'],
        'releases' => ['App releases', 'admin.releases.index', 'admin.releases.*'],
        'demos' => ['3D demos', 'admin.demos', 'admin.demos'],
    ];
@endphp
<x-layouts.app :title="$title" :page="$page">
    <div class="mb-5">
        <p class="eyebrow">Admin</p>
        <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">{{ $heading ?? $title }}</h1>
        @if ($lead)<p class="mt-1 max-w-3xl text-sm text-muted">{{ $lead }}</p>@endif
    </div>
    <nav class="-mx-1 mb-6 flex gap-1.5 overflow-x-auto px-1 pb-1" aria-label="Admin sections">
        @foreach ($tabs as $key => [$label, $route, $match])
            <a href="{{ route($route) }}" class="chip whitespace-nowrap {{ request()->routeIs(...explode('|', $match)) ? '!border-neon !text-neon' : '' }}" @if(request()->routeIs(...explode('|', $match))) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    {{ $slot }}
</x-layouts.app>
