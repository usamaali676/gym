<x-admin.shell title="App releases" lead="Tell installed apps which versions still work. New builds are published in App Store Connect and Play Console; this only controls what already-installed apps show and allow.">
    @if ($errors->any())<p class="mb-4 text-sm text-danger" role="alert">{{ $errors->first() }}</p>@endif
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        @foreach ($policies as $p)
            <form method="POST" action="{{ route('admin.releases.policy', $p->platform) }}" class="glass grid grid-cols-2 gap-3 p-5" aria-label="{{ ucfirst($p->platform) }} policy">
                @csrf @method('PUT')
                <h2 class="col-span-2 text-sm font-bold uppercase">{{ $p->platform === 'ios' ? 'iOS' : 'Android' }}</h2>
                <x-field :id="$p->platform.'-min'" name="min_supported" label="Oldest version allowed" :value="$p->min_supported" maxlength="20" required hint="Older builds must update."/>
                <x-field :id="$p->platform.'-rec'" name="recommended" label="Recommended version" :value="$p->recommended" maxlength="20" hint="Older builds are nudged."/>
                <x-field :id="$p->platform.'-latest'" name="latest" label="Newest in the store" :value="$p->latest" maxlength="20"/>
                <x-field :id="$p->platform.'-store'" name="store_url" label="Store link" :value="$p->store_url" maxlength="255"/>
                <label class="col-span-2"><span class="label">What's new (shown with the update prompt)</span><textarea name="release_notes" rows="3" class="textarea input" maxlength="2000">{{ old('release_notes', $p->release_notes) }}</textarea></label>
                <div class="col-span-2 border-t border-line pt-3">
                    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="maintenance_enabled" value="0"><input type="checkbox" class="check" name="maintenance_enabled" value="1" @checked(old('maintenance_enabled', $p->maintenance_enabled))> Maintenance mode (apps show a notice and pause)</label>
                </div>
                <label class="col-span-2"><span class="label">Maintenance message</span><input name="maintenance_message" class="input" value="{{ old('maintenance_message', $p->maintenance_message) }}" maxlength="500"></label>
                <x-field :id="$p->platform.'-until'" name="maintenance_ends_at" type="datetime-local" label="Expected back (optional)" :value="$p->maintenance_ends_at?->format('Y-m-d\TH:i')" class="col-span-2"/>
                <div class="col-span-2"><button class="btn btn-primary btn-sm">Save {{ $p->platform === 'ios' ? 'iOS' : 'Android' }} policy</button></div>
            </form>
        @endforeach
    </div>

    <section class="mt-8" aria-labelledby="notes-title">
        <h2 id="notes-title" class="text-sm font-bold uppercase">Release notes</h2>
        <div class="glass relative mt-3 overflow-x-auto">
            <table class="w-full min-w-[36rem] text-left text-sm">
                <thead class="text-xs uppercase tracking-wider text-muted"><tr><th class="p-3" scope="col">Version</th><th class="p-3" scope="col">For</th><th class="p-3" scope="col">Notes</th><th class="p-3" scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                    @forelse ($releases as $r)
                        <tr class="border-t border-line align-top">
                            <td class="p-3 font-semibold">{{ $r->version }}@if($r->build_number)<span class="text-xs font-normal text-muted"> ({{ $r->build_number }})</span>@endif</td>
                            <td class="p-3 text-muted">{{ $r->platform }}</td>
                            <td class="p-3">{{ $r->notes }}</td>
                            <td class="whitespace-nowrap p-3 text-right">
                                <form method="POST" action="{{ route('admin.releases.toggle', $r) }}" class="inline">@csrf @method('PATCH')<button class="btn btn-ghost btn-sm">{{ $r->is_published ? 'Hide' : 'Publish' }}</button></form>
                                <form method="POST" action="{{ route('admin.releases.destroy', $r) }}" class="inline" onsubmit="return confirm('Delete these notes?')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm">Delete</button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-4 text-muted">No release notes yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <form method="POST" action="{{ route('admin.releases.store') }}" class="glass mt-4 grid grid-cols-2 items-end gap-3 p-5 md:grid-cols-6">
            @csrf
            <label><span class="label !mb-1">For</span><select name="platform" class="select !min-h-10"><option value="all">All</option><option value="android">Android</option><option value="ios">iOS</option></select></label>
            <x-field id="rel-version" name="version" label="Version" maxlength="20" required class="md:col-span-1"/>
            <x-field id="rel-build" name="build_number" label="Build (optional)" maxlength="20"/>
            <label class="col-span-2"><span class="label !mb-1">Notes</span><input name="notes" class="input !min-h-10" maxlength="2000" required></label>
            <label class="flex items-center gap-2 pb-3 text-sm"><input type="checkbox" class="check" name="is_published" value="1"> Publish now</label>
            <button class="btn btn-primary btn-sm col-span-2 md:col-span-6 md:w-fit">Add release notes</button>
        </form>
    </section>
</x-admin.shell>
