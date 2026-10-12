<x-admin.shell title="App content" section="content" lead="Everything here reaches the Android and iOS apps without an app update.">
    <x-admin.content-nav/>
    <div class="mb-4 flex justify-end"><a href="{{ route('admin.help.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="size-4"/>New article</a></div>
    <div class="glass relative overflow-x-auto">
        <table class="w-full min-w-[36rem] text-left text-sm">
            <thead class="text-xs uppercase tracking-wider text-muted"><tr><th class="p-3" scope="col">Article</th><th class="p-3" scope="col">Category</th><th class="p-3" scope="col">Status</th><th class="p-3" scope="col"><span class="sr-only">Edit</span></th></tr></thead>
            <tbody>
                @forelse ($items as $h)
                    <tr class="border-t border-line">
                        <td class="p-3 font-semibold">{{ $h->title }}<span class="block text-xs font-normal text-muted">{{ $h->slug }}</span></td>
                        <td class="p-3 text-muted">{{ $h->category }}</td>
                        <td class="p-3"><span class="chip {{ $h->status === 'published' ? 'chip-neon' : '' }}">{{ ucfirst($h->status) }}</span></td>
                        <td class="p-3 text-right"><a class="btn btn-ghost btn-sm" href="{{ route('admin.help.edit', $h) }}">Edit</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="p-4 text-muted">No help articles yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $items->links() }}</div>
</x-admin.shell>
