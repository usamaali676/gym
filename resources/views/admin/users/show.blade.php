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
