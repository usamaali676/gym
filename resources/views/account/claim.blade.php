<x-layouts.app title="Save your progress">
    <div class="mx-auto max-w-md">
        <div class="glass glass-neon p-6 sm:p-8">
            <h1 class="text-xl font-black uppercase">Save your progress</h1>
            <p class="mt-1 text-sm text-muted">Add an email and password to keep this account. Your workouts, food log and badges stay exactly as they are.</p>
            <form method="POST" action="{{ route('account.claim.store') }}" class="mt-6 space-y-4">
                @csrf
                <x-field name="email" type="email" label="Email" required autofocus autocomplete="username" inputmode="email"/>
                <x-field name="password" type="password" label="Password" required autocomplete="new-password" hint="At least 8 characters with letters and numbers."/>
                <x-field name="password_confirmation" type="password" label="Confirm password" required autocomplete="new-password"/>
                <button class="btn btn-primary btn-lg w-full">Save account</button>
            </form>
        </div>
    </div>
</x-layouts.app>
