<x-layouts.guest title="Confirm password">
    <h1 class="text-xl font-black uppercase">Confirm password</h1>
    <p class="mt-1 text-sm text-muted">This is a secure area. Please confirm your password to continue.</p>
    <form method="POST" action="{{ route('password.confirm') }}" class="mt-6 space-y-4">
        @csrf
        <x-field name="password" type="password" label="Password" required autofocus autocomplete="current-password"/>
        <button class="btn btn-primary btn-lg w-full">Confirm</button>
    </form>
</x-layouts.guest>
