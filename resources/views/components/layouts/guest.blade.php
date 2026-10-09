@props(['title' => null, 'wide' => false, 'page' => null])
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
</head>
<body @if($page) data-page="{{ $page }}" @endif x-data>
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[100] focus:rounded-lg focus:bg-neon focus:px-4 focus:py-2 focus:text-[#041006]">Skip to content</a>
    <div class="bg-grid" aria-hidden="true"></div>
    <canvas class="particles" aria-hidden="true"></canvas>
    <main id="main" class="page-in mx-auto flex min-h-dvh w-full {{ $wide ? 'max-w-3xl' : 'max-w-md' }} flex-col justify-center px-4 py-10" tabindex="-1">
        <a href="{{ route('home') }}" class="mb-8 self-center" aria-label="{{ config('app.name') }} home"><x-logo size="lg"/></a>
        <div class="glass glass-neon p-6 sm:p-8">
            <x-flash/>
            {{ $slot }}
        </div>
    </main>
</body>
</html>
