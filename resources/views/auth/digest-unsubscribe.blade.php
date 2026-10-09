<x-layouts.guest title="Unsubscribe">
    <h1 class="text-xl font-black uppercase">Stop the weekly email?</h1>
    <p class="mt-2 text-sm text-muted">You will no longer get the weekly report by email. The report stays available in the app.</p>
    <form method="POST" action="{{ $action }}" class="mt-5">@csrf<button class="btn btn-primary w-full">Unsubscribe</button></form>
    <a href="{{ route('home') }}" class="btn btn-ghost mt-3 w-full">Keep the emails</a>
</x-layouts.guest>
