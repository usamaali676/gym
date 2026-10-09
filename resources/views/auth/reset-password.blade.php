<x-layouts.guest title="Choose a new password">
    <h1 class="text-xl font-black uppercase">New password</h1>
    <form method="POST" action="{{ route('password.store') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-field name="email" type="email" label="Email" :value="$request->email" required autofocus autocomplete="username"/>
        <x-field name="password" type="password" label="New password" required autocomplete="new-password"/>
        <x-field name="password_confirmation" type="password" label="Confirm password" required autocomplete="new-password"/>
        <button class="btn btn-primary btn-lg w-full">Reset password</button>
    </form>
</x-layouts.guest>
