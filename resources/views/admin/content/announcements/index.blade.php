<x-admin.shell title="App content" lead="Everything here reaches the Android and iOS apps without an app update.">
    <x-admin.content-nav/>
    <div class="mb-4 flex justify-end"><a href="{{ route('admin.announcements.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="size-4"/>New announcement</a></div>
    <div class="glass relative overflow-x-auto">
        <table class="w-full min-w-[40rem] text-left text-sm">
            <thead class="text-xs uppercase tracking-wider text-muted"><tr><th class="p-3" scope="col">Title</th><th class="p-3" scope="col">Type</th><th class="p-3" scope="col">Status</th><th class="p-3" scope="col">Window</th><th class="p-3" scope="col"><span class="sr-only">Edit</span></th></tr></thead>
            <tbody>
                @forelse ($items as $a)
                    <tr class="border-t border-line">
                        <td class="p-3 font-semibold">{{ $a->title }} @if($a->pinned)<span class="chip ml-1">Pinned</span>@endif</td>
                        <td class="p-3 text-muted">{{ $a->severity }}</td>
                        <td class="p-3"><span class="chip {{ $a->status === 'published' ? 'chip-neon' : '' }}">{{ ucfirst($a->status) }}</span></td>
                        <td class="p-3 text-muted">{{ $a->starts_at?->format('j M') ?? 'now' }} to {{ $a->ends_at?->format('j M Y') ?? 'open' }}</td>
                        <td class="p-3 text-right"><a class="btn btn-ghost btn-sm" href="{{ route('admin.announcements.edit', $a) }}">Edit</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-4 text-muted">No announcements yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $items->links() }}</div>
</x-admin.shell>
