@props(['title' => null, 'page' => 'workout'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#07090A">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @if (file_exists(public_path('manifest.webmanifest')))
        <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
        <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-page="{{ $page }}" class="min-h-dvh">
    <div class="bg-grid" aria-hidden="true"></div>
    {{ $slot }}
</body>
</html>
