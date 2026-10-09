<x-layouts.guest title="Log in">
    <h1 class="text-xl font-black uppercase">Welcome back</h1>
    <p class="mt-1 text-sm text-muted">Log in to pick up where you left off.</p>

    @if (session('status'))<p class="mt-3 text-sm text-neon" role="status">{{ session('status') }}</p>@endif

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
        @csrf
        <x-field name="email" type="email" label="Email" required autofocus autocomplete="username" inputmode="email"/>
        <x-field name="password" type="password" label="Password" required autocomplete="current-password"/>
        <div class="flex items-center justify-between gap-3">
            <label class="flex items-center gap-2 text-sm text-muted"><input type="checkbox" name="remember" class="check"> Remember me</label>
            @if (Route::has('password.request'))
                <a class="text-sm text-neon underline underline-offset-4" href="{{ route('password.request') }}">Forgot password?</a>
            @endif
        </div>
        <button class="btn btn-primary btn-lg w-full">Log in</button>
    </form>

    @if (config('ironpulse.guest_mode'))
        <div class="my-5 flex items-center gap-3 text-xs uppercase tracking-widest text-muted"><span class="glow-line flex-1"></span>or<span class="glow-line flex-1"></span></div>
        <form method="POST" action="{{ route('guest.start') }}">@csrf
            <button class="btn btn-ghost w-full"><x-icon name="play" class="size-4"/>Continue as guest</button>
        </form>
    @endif
    <p class="mt-6 text-center text-sm text-muted">New here? <a class="text-neon underline underline-offset-4" href="{{ route('register') }}">Create an account</a></p>
</x-layouts.guest>
