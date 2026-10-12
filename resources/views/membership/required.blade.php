<x-layouts.app title="Membership required">
    <div class="mx-auto max-w-md">
        <div class="glass glass-neon p-6 text-center sm:p-8">
            <p class="eyebrow">Membership</p>
            <h1 class="mt-2 text-xl font-black uppercase">{{ $feature }} is not in your plan</h1>
            <p class="mt-2 text-sm text-muted">
                {{ $plan ? 'Your current plan ('.$plan->name.') does not include this.' : 'Your current plan does not include this.' }}
                Plans are managed by the gym; ask an admin to change yours, or upgrade from the mobile app.
            </p>
            <a href="{{ route('dashboard') }}" class="btn btn-primary mt-6">Back to dashboard</a>
        </div>
    </div>
</x-layouts.app>
