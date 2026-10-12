<x-layouts.guest title="Create account">
    <h1 class="text-xl font-black uppercase">Create your account</h1>
    <p class="mt-1 text-sm text-muted">Takes a minute. A short setup wizard follows.</p>

    <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4">
        @csrf
        <x-field name="name" label="Name" required autofocus autocomplete="name"/>
        <x-field name="email" type="email" label="Email" required autocomplete="username" inputmode="email"/>
        <x-field name="password" type="password" label="Password" required autocomplete="new-password" hint="At least 8 characters with letters and numbers."/>
        <x-field name="password_confirmation" type="password" label="Confirm password" required autocomplete="new-password"/>
        <button class="btn btn-primary btn-lg w-full">Create account</button>
    </form>
    <p class="mt-6 text-center text-sm text-muted">Already have an account? <a class="text-neon underline underline-offset-4" href="{{ route('login') }}">Log in</a></p>
</x-layouts.guest>
