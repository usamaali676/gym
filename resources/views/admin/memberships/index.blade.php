<x-admin.shell title="Memberships" lead="Plans decide what each member can open. The mobile apps and the website both read them from here, and the server checks them on every request.">
    @if ($errors->has('plan'))<p class="mb-4 text-sm text-danger" role="alert">{{ $errors->first('plan') }}</p>@endif
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.subscriptions.index') }}" class="btn btn-ghost btn-sm">Subscriptions</a>
        <a href="{{ route('admin.memberships.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="size-4"/>New plan</a>
    </div>
    <div class="glass relative overflow-x-auto">
        <table class="w-full min-w-[44rem] text-left text-sm">
            <thead class="text-xs uppercase tracking-wider text-muted"><tr><th class="p-3" scope="col">Plan</th><th class="p-3" scope="col">Price</th><th class="p-3" scope="col">Status</th><th class="p-3" scope="col">Active members</th><th class="p-3" scope="col"><span class="sr-only">Edit</span></th></tr></thead>
            <tbody>
                @foreach ($plans as $plan)
                    <tr class="border-t border-line">
                        <td class="p-3 font-semibold">{{ $plan->name }} @if($plan->is_default)<span class="chip chip-neon ml-1">Default</span>@endif<span class="block text-xs font-normal text-muted">{{ $plan->slug }} · {{ collect($plan->entitlements)->filter()->count() }} features</span></td>
                        <td class="p-3">{{ $plan->priceLabel() }}@if($plan->billing_interval !== 'none')<span class="text-xs text-muted"> / {{ $plan->billing_interval }}</span>@endif</td>
                        <td class="p-3"><span class="chip {{ $plan->status === 'published' ? 'chip-neon' : '' }}">{{ ucfirst($plan->status) }}</span></td>
                        <td class="p-3 num">{{ $plan->active_subscribers }}</td>
                        <td class="p-3 text-right"><a class="btn btn-ghost btn-sm" href="{{ route('admin.memberships.edit', $plan) }}">Edit</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-muted">Anyone without an active subscription is on the default plan. It starts out granting every feature, so nothing changes for existing members until you restrict it.</p>
</x-admin.shell>
