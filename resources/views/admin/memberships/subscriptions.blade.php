<x-admin.shell title="Subscriptions" section="memberships" lead="Every membership ever granted or bought. Grant or revoke access from a member's page.">
    <p class="mb-4"><a href="{{ route('admin.memberships.index') }}" class="text-sm font-semibold text-neon underline underline-offset-4">All plans</a></p>
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <label class="min-w-48 flex-1"><span class="label !mb-1">Member name or email</span><input type="search" name="q" value="{{ $q }}" class="input !min-h-10"></label>
        <label><span class="label !mb-1">Status</span><select name="status" class="select !min-h-10">@foreach (['' => 'All', 'effective' => 'Has access now', 'trialing' => 'Trialing', 'active' => 'Active', 'canceled' => 'Canceled', 'expired' => 'Expired', 'revoked' => 'Revoked'] as $k => $l)<option value="{{ $k }}" @selected(($status ?? '') === $k)>{{ $l }}</option>@endforeach</select></label>
        <button class="btn btn-ghost btn-sm">Filter</button>
    </form>
    <div class="glass relative overflow-x-auto">
        <table class="w-full min-w-[48rem] text-left text-sm">
            <thead class="text-xs uppercase tracking-wider text-muted"><tr><th class="p-3" scope="col">Member</th><th class="p-3" scope="col">Plan</th><th class="p-3" scope="col">Status</th><th class="p-3" scope="col">Source</th><th class="p-3" scope="col">Ends</th></tr></thead>
            <tbody>
                @forelse ($subscriptions as $s)
                    <tr class="border-t border-line">
                        <td class="p-3"><a class="font-semibold underline-offset-4 hover:underline" href="{{ route('admin.users.show', $s->user_id) }}">{{ $s->user->is_guest ? 'Guest #'.$s->user_id : $s->user->name }}</a><span class="block text-xs text-muted">{{ $s->user->email }}</span></td>
                        <td class="p-3">{{ $s->plan->name }}</td>
                        <td class="p-3"><span class="chip {{ $s->isEffective() ? 'chip-neon' : '' }}">{{ ucfirst($s->displayStatus()) }}</span></td>
                        <td class="p-3 text-muted">{{ $s->source }}</td>
                        <td class="p-3 text-muted">{{ $s->ends_at?->format('j M Y') ?? 'No expiry' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-4 text-muted">No subscriptions match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $subscriptions->links() }}</div>
</x-admin.shell>
