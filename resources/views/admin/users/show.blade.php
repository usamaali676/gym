<x-admin.shell :title="$account->is_guest ? 'Guest #'.$account->id : $account->name" section="users" :lead="$account->email ?? 'Guest account with no email'">
    <p class="mb-4"><a href="{{ route('admin.users.index') }}" class="text-sm font-semibold text-neon underline underline-offset-4">All users</a></p>
    <dl class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach ([['Completed workouts', $counts['workouts']], ['Posture sets', $counts['posture']], ['Food entries', $counts['food']], ['Progress photos', $counts['photos']]] as [$l, $v])
            <div class="glass p-4"><dt class="text-xs font-semibold uppercase tracking-wider text-muted">{{ $l }}</dt><dd class="num mt-1 text-2xl">{{ $v }}</dd></div>
        @endforeach
    </dl>
    <p class="mt-2 text-xs text-muted">Only counts are shown. The content of someone's logs, photos and messages is never visible to admins.</p>

    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="glass p-5" aria-labelledby="role-title">
            <h2 id="role-title" class="text-sm font-bold uppercase">Role</h2>
            <p class="mt-2 text-sm text-muted">Level {{ $account->level }} · {{ number_format($account->xp_total) }} XP · joined {{ $account->created_at->format('j M Y') }} · last seen {{ $account->last_seen_at?->diffForHumans() ?? 'never' }}</p>
            @if ($account->is(auth()->user()))
                <p class="mt-3 text-sm text-muted">This is you. Another admin has to change your role.</p>
            @else
                <form method="POST" action="{{ route('admin.users.role', $account) }}" class="mt-3 flex flex-wrap items-end gap-2">
                    @csrf @method('PATCH')
                    <label><span class="label !mb-1">Role</span><select name="role" class="select !min-h-10">@foreach (['user' => 'User', 'admin' => 'Admin'] as $k => $l)<option value="{{ $k }}" @selected($account->role->value === $k)>{{ $l }}</option>@endforeach</select></label>
                    <button class="btn btn-ghost btn-sm">Change role</button>
                </form>
                @error('role')<p class="mt-2 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                @if ($lastAdmin)<p class="mt-2 text-xs text-muted">This is the only admin.</p>@endif
            @endif
        </section>

        <section class="glass p-5 lg:col-span-2" aria-labelledby="mem-title">
            <h2 id="mem-title" class="text-sm font-bold uppercase">Membership</h2>
            <p class="mt-2 text-sm text-muted">Current plan: <strong class="text-ink">{{ $currentPlan?->name ?? 'none' }}</strong>. Access is checked by the server on every request; a change here applies on the member's next action.</p>
            @if ($subscriptions->isNotEmpty())
                <div class="relative mt-3 overflow-x-auto">
                    <table class="w-full min-w-[34rem] text-left text-sm">
                        <thead class="text-xs uppercase tracking-wider text-muted"><tr><th class="py-2 pr-3" scope="col">Plan</th><th class="py-2 pr-3" scope="col">Status</th><th class="py-2 pr-3" scope="col">Source</th><th class="py-2 pr-3" scope="col">Ends</th><th class="py-2" scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody>
                            @foreach ($subscriptions as $s)
                                <tr class="border-t border-line align-top">
                                    <td class="py-2 pr-3 font-semibold">{{ $s->plan->name }}</td>
                                    <td class="py-2 pr-3"><span class="chip {{ $s->isEffective() ? 'chip-neon' : '' }}">{{ ucfirst($s->displayStatus()) }}</span></td>
                                    <td class="py-2 pr-3 text-muted">{{ $s->source }}</td>
                                    <td class="py-2 pr-3 text-muted">{{ $s->ends_at?->format('j M Y') ?? 'No expiry' }}</td>
                                    <td class="py-2 text-right">
                                        @if ($s->isEffective() || $s->status === 'expired')
                                            <form method="POST" action="{{ route('admin.subscriptions.extend', $s) }}" class="mb-1 inline-flex items-end gap-1">@csrf @method('PATCH')<input type="date" name="ends_on" class="input !min-h-9 !w-40" aria-label="New end date" min="{{ now()->addDay()->toDateString() }}"><button class="btn btn-ghost btn-sm">Set end</button></form>
                                        @endif
                                        @if ($s->isEffective())
                                            <form method="POST" action="{{ route('admin.subscriptions.revoke', $s) }}" class="inline" onsubmit="return confirm('Revoke now? Access ends immediately.')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm">Revoke</button></form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <form method="POST" action="{{ route('admin.subscriptions.grant', $account) }}" class="mt-4 flex flex-wrap items-end gap-2">
                @csrf
                <label><span class="label !mb-1">Grant plan</span><select name="plan_id" class="select !min-h-10">@foreach ($plans as $p)<option value="{{ $p->id }}">{{ $p->name }}{{ $p->status !== 'published' ? ' ('.$p->status.')' : '' }}</option>@endforeach</select></label>
                <label><span class="label !mb-1">Until (blank = no expiry)</span><input type="date" name="ends_on" class="input !min-h-10" min="{{ now()->addDay()->toDateString() }}"></label>
                <label><span class="label !mb-1">Type</span><select name="source" class="select !min-h-10"><option value="admin">Support grant</option><option value="promo">Promotional</option></select></label>
                <label class="min-w-40 flex-1"><span class="label !mb-1">Note</span><input type="text" name="notes" class="input !min-h-10" maxlength="190"></label>
                <button class="btn btn-primary btn-sm">Grant</button>
            </form>
            @error('ends_on')<p class="mt-2 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
        </section>

        @unless ($account->is(auth()->user()))
            <section class="glass p-5" aria-labelledby="status-title">
                <h2 id="status-title" class="text-sm font-bold uppercase">Account status</h2>
                @if ($account->isSuspended())
                    <p class="mt-2 text-sm text-muted">Suspended {{ $account->suspended_at?->diffForHumans() }}{{ $account->suspended_reason ? ': '.$account->suspended_reason : '' }}.</p>
                    <form method="POST" action="{{ route('admin.users.status', $account) }}" class="mt-3">@csrf @method('PATCH')<input type="hidden" name="status" value="active"><button class="btn btn-ghost btn-sm">Restore account</button></form>
                @else
                    <p class="mt-2 text-sm text-muted">Suspending signs the account out of the website and every app immediately.</p>
                    <form method="POST" action="{{ route('admin.users.status', $account) }}" class="mt-3 flex flex-wrap items-end gap-2">@csrf @method('PATCH')<input type="hidden" name="status" value="suspended"><label class="min-w-40 flex-1"><span class="label !mb-1">Reason (internal)</span><input type="text" name="reason" class="input !min-h-10" maxlength="190"></label><button class="btn btn-danger btn-sm">Suspend</button></form>
                @endif
                @error('status')<p class="mt-2 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
            </section>
        @endunless

        @unless ($account->is(auth()->user()))
            <section class="glass border-danger/30 p-5" aria-labelledby="del-title">
                <h2 id="del-title" class="text-sm font-bold uppercase text-[#ff8a8a]">Delete account</h2>
                <p class="mt-2 text-sm text-muted">Permanently removes the account and everything it owns, including progress photos on disk. This cannot be undone.</p>
                @php $expected = $account->email ?: 'guest-'.$account->id; @endphp
                <form method="POST" action="{{ route('admin.users.destroy', $account) }}" class="mt-3 space-y-2">
                    @csrf @method('DELETE')
                    <label class="block"><span class="label !mb-1">Type <code class="text-ink">{{ $expected }}</code> to confirm</span><input type="text" name="confirm" class="input !min-h-10" autocomplete="off" required></label>
                    @error('confirm')<p class="text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                    <button class="btn btn-danger btn-sm">Delete account</button>
                </form>
            </section>
        @endunless
    </div>
</x-admin.shell>
