<link rel="manifest" href="{{ route('pwa.manifest') }}">
<link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
{{-- Lets the service worker know whether to keep or drop its cached pages (they belong to the signed-in user). --}}
<meta name="ip-auth" content="{{ auth()->check() ? '1' : '0' }}">
<meta name="ip-sw" content="{{ asset('sw.js') }}">
