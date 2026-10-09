<x-layouts.guest title="Reset password">
    <h1 class="text-xl font-black uppercase">Reset password</h1>
    <p class="mt-1 text-sm text-muted">Enter your email and we will send a reset link.</p>
    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
        @csrf
        <x-field name="email" type="email" label="Email" required autofocus inputmode="email"/>
        <button class="btn btn-primary btn-lg w-full">Email reset link</button>
    </form>
    <p class="mt-6 text-center text-sm"><a class="text-neon underline underline-offset-4" href="{{ route('login') }}">Back to log in</a></p>
</x-layouts.guest>
