<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#07090A">
    <title>Offline · {{ config('app.name') }}</title>
    {{-- Self-contained on purpose: this page is cached at install time and must render with nothing else available. --}}
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100dvh; display: grid; place-items: center; padding: 1.5rem; background: #07090A; color: #E8F5E9; font: 400 1rem/1.5 system-ui, sans-serif; }
        main { max-width: 26rem; text-align: center; }
        .mark { width: 4rem; height: 4rem; margin: 0 auto 1.25rem; border-radius: 1rem; background: #39FF14; color: #041006; display: grid; place-items: center; }
        h1 { margin: 0 0 .5rem; font-size: 1.6rem; letter-spacing: .04em; text-transform: uppercase; }
        p { margin: 0 0 1rem; color: #9db5a2; }
        a, button { display: inline-flex; align-items: center; justify-content: center; min-height: 2.75rem; padding: 0 1.25rem; border: 0; border-radius: .75rem; background: #39FF14; color: #041006; font: 700 .95rem system-ui, sans-serif; text-decoration: none; cursor: pointer; }
        a.alt { background: transparent; color: #E8F5E9; border: 1px solid #2a3a2f; margin-left: .5rem; }
        :focus-visible { outline: 2px solid #39FF14; outline-offset: 3px; }
    </style>
</head>
<body>
    <main role="main">
        <div class="mark" aria-hidden="true"><svg viewBox="0 0 24 24" width="32" height="32" fill="currentColor"><path d="M13.2 2 4.5 13.4c-.4.5 0 1.2.6 1.2H10l-1 7.1c-.1.8.9 1.2 1.4.6l8.7-11.4c.4-.5 0-1.2-.6-1.2H14l1-7.1c.1-.8-.9-1.2-1.4-.6Z"/></svg></div>
        <h1>You are offline</h1>
        <p>This page is not saved on your device. Pages you opened recently (today's workout, your plan, the exercise library) still work, and sets you log mid-workout are kept and sent when you reconnect.</p>
        <button type="button" onclick="location.reload()">Try again</button>
        <a class="alt" href="{{ url('/dashboard') }}">Dashboard</a>
    </main>
</body>
</html>
