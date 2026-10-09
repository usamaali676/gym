<x-admin.shell title="Users" lead="Search accounts, change roles and remove accounts. Guests are accounts created by Try as guest.">
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <label class="min-w-48 flex-1"><span class="label !mb-1">Search name or email</span><input type="search" name="q" value="{{ $q }}" class="input !min-h-10" placeholder="Search"></label>
        <label><span class="label !mb-1">Show</span><select name="filter" class="select !min-h-10">@foreach (['' => 'Everyone', 'members' => 'Members', 'guests' => 'Guests', 'admins' => 'Admins'] as $k => $l)<option value="{{ $k }}" @selected(($filter ?? '') === $k)>{{ $l }}</option>@endforeach</select></label>
        <button class="btn btn-ghost btn-sm">Filter</button>
    </form>
    <div class="glass relative overflow-x-auto">
        <table class="w-full min-w-[40rem] text-left text-sm">
            <thead class="text-xs uppercase tracking-wider text-muted"><tr><th class="p-3" scope="col">Account</th><th class="p-3" scope="col">Role</th><th class="p-3" scope="col">Level</th><th class="p-3" scope="col">Last seen</th><th class="p-3" scope="col"><span class="sr-only">Open</span></th></tr></thead>
            <tbody>
                @forelse ($users as $u)
                    <tr class="border-t border-line">
                        <td class="p-3"><p class="font-semibold">{{ $u->is_guest ? 'Guest #'.$u->id : $u->name }}</p><p class="text-xs text-muted">{{ $u->email ?? 'no email' }}</p></td>
                        <td class="p-3">@if($u->role->value === 'admin')<span class="chip !border-neon !text-neon">Admin</span>@else<span class="text-muted">{{ $u->is_guest ? 'Guest' : 'Member' }}</span>@endif</td>
                        <td class="p-3 num">{{ $u->level }}</td>
                        <td class="p-3 text-muted">{{ $u->last_seen_at?->diffForHumans() ?? 'never' }}</td>
                        <td class="p-3 text-right"><a class="btn btn-ghost btn-sm" href="{{ route('admin.users.show', $u) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-4 text-muted">No accounts match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $users->links() }}</div>
</x-admin.shell>
