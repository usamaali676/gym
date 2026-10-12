<x-admin.shell title="App content" section="content" lead="Everything here reaches the Android and iOS apps without an app update.">
    <x-admin.content-nav/>
    <div class="mb-4 flex justify-end"><a href="{{ route('admin.banners.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="size-4"/>New banner</a></div>
    <div class="glass relative overflow-x-auto">
        <table class="w-full min-w-[40rem] text-left text-sm">
            <thead class="text-xs uppercase tracking-wider text-muted"><tr><th class="p-3" scope="col">Title</th><th class="p-3" scope="col">Where</th><th class="p-3" scope="col">Status</th><th class="p-3" scope="col">Window</th><th class="p-3" scope="col"><span class="sr-only">Edit</span></th></tr></thead>
            <tbody>
                @forelse ($items as $b)
                    <tr class="border-t border-line">
                        <td class="p-3 font-semibold">{{ $b->title }}<span class="block text-xs font-normal text-muted">{{ $b->image_path ? 'with image' : 'text only' }}</span></td>
                        <td class="p-3 text-muted">{{ $b->placement }}</td>
                        <td class="p-3"><span class="chip {{ $b->status === 'published' ? 'chip-neon' : '' }}">{{ ucfirst($b->status) }}</span></td>
                        <td class="p-3 text-muted">{{ $b->starts_at?->format('j M') ?? 'now' }} to {{ $b->ends_at?->format('j M Y') ?? 'open' }}</td>
                        <td class="p-3 text-right"><a class="btn btn-ghost btn-sm" href="{{ route('admin.banners.edit', $b) }}">Edit</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-4 text-muted">No banners yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $items->links() }}</div>
</x-admin.shell>
