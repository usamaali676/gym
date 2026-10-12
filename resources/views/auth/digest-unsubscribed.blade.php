<x-layouts.guest title="Unsubscribed">
    <h1 class="text-xl font-black uppercase">You are unsubscribed</h1>
    <p class="mt-2 text-sm text-muted">The weekly email digest is switched off. You can turn it back on any time under Profile, Preferences and reminders.</p>
    <a href="{{ route('home') }}" class="btn btn-ghost mt-5 w-full">Back to {{ config('app.name') }}</a>
</x-layouts.guest>
