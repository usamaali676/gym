@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#07090A">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body x-data>
    <div class="bg-grid" aria-hidden="true"></div>
    <main id="main" class="page-in mx-auto w-full max-w-3xl px-4 py-8 sm:px-6" tabindex="-1">
        <a href="{{ route('home') }}" class="mb-6 inline-block" aria-label="{{ config('app.name') }} home"><x-logo/></a>
        {{ $slot }}
        <x-credit/>
        <p class="mt-8 text-center text-xs text-muted">Shared from {{ config('app.name') }}. <a href="{{ route('home') }}" class="font-semibold text-neon underline underline-offset-4">Train your own plan</a></p>
    </main>
</body>
</html>
