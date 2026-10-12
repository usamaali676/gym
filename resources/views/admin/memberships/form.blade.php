@php
    $isNew = ! $plan->exists;
    $intervals = ['none' => 'One-off / free', 'week' => 'Weekly', 'month' => 'Monthly', 'year' => 'Yearly', 'lifetime' => 'Lifetime'];
    $granted = old('entitlements', $plan->entitlements ?? []);
@endphp
<x-admin.shell :title="$isNew ? 'New plan' : 'Edit '.$plan->name" section="memberships" :heading="$isNew ? 'New plan' : $plan->name">
    <p class="mb-4"><a href="{{ route('admin.memberships.index') }}" class="text-sm font-semibold text-neon underline underline-offset-4">All plans</a></p>
    @if ($errors->any())<p class="mb-4 text-sm text-danger" role="alert">{{ $errors->first() }}</p>@endif

    <form method="POST" action="{{ $isNew ? route('admin.memberships.store') : route('admin.memberships.update', $plan) }}" class="space-y-6">
        @csrf @if(! $isNew) @method('PATCH') @endif

        <section class="glass grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 lg:grid-cols-3" aria-label="Details">
            <x-field name="name" label="Name" :value="$plan->name" maxlength="80" required/>
            <x-field name="slug" label="Slug" :value="$plan->slug" maxlength="80" hint="Leave empty to derive it from the name."/>
            <label class="block"><span class="label">Status</span>
                <select name="status" class="select">@foreach (['draft' => 'Draft (hidden)', 'published' => 'Published (visible, can be bought)', 'archived' => 'Archived (hidden, members keep it)'] as $k => $l)<option value="{{ $k }}" @selected(old('status', $plan->status) === $k)>{{ $l }}</option>@endforeach</select></label>
            <label class="block sm:col-span-2 lg:col-span-3"><span class="label">Description</span><textarea name="description" rows="2" class="textarea input" maxlength="1000">{{ old('description', $plan->description) }}</textarea></label>
            <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_default" value="0"><input type="checkbox" class="check" name="is_default" value="1" @checked(old('is_default', $plan->is_default)) @disabled($plan->is_default)> Default plan (everyone without a subscription)</label>
            @if($plan->is_default)<input type="hidden" name="is_default" value="1">@endif
            <x-field name="sort_order" type="number" label="Order on the plans screen" :value="$plan->sort_order" min="0" max="999"/>
        </section>

        <section class="glass p-5" aria-label="Pricing and billing">
            <h2 class="text-sm font-bold uppercase">Price and billing</h2>
            <p class="mt-1 text-xs text-muted">Shown for information. Apps display the price from Apple / Google for the store product below, and the server never trusts a price sent by an app.</p>
            <div class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-4">
                <x-field name="price" type="number" label="Price" :value="number_format(($plan->price_minor ?? 0) / 100, 2, '.', '')" step="0.01" min="0" required/>
                <x-field name="currency" label="Currency" :value="$plan->currency" maxlength="3" required/>
                <label class="block"><span class="label">Billing</span><select name="billing_interval" class="select">@foreach ($intervals as $k => $l)<option value="{{ $k }}" @selected(old('billing_interval', $plan->billing_interval) === $k)>{{ $l }}</option>@endforeach</select></label>
                <x-field name="interval_count" type="number" label="Every N periods" :value="$plan->interval_count" min="1" max="12" required/>
                <x-field name="duration_days" type="number" label="Fixed duration (days)" :value="$plan->duration_days" min="1" max="3650" hint="For passes that do not renew."/>
                <x-field name="trial_days" type="number" label="Free trial (days)" :value="$plan->trial_days" min="0" max="365" required/>
                <x-field name="grace_days" type="number" label="Grace after failed renewal (days)" :value="$plan->grace_days" min="0" max="30" required/>
                <label class="flex items-center gap-2 self-end pb-3 text-sm"><input type="hidden" name="auto_renews" value="0"><input type="checkbox" class="check" name="auto_renews" value="1" @checked(old('auto_renews', $plan->auto_renews))> Renews automatically</label>
            </div>
        </section>

        <section class="glass p-5" aria-label="Included features">
            <h2 class="text-sm font-bold uppercase">What this plan unlocks</h2>
            <p class="mt-1 text-xs text-muted">Checked features are enforced by the server on every request, from the website and from the apps.</p>
            <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach ($entitlements as $e)
                    <label class="flex items-start gap-2 text-sm"><input type="hidden" name="entitlements[{{ $e->value }}]" value="0"><input type="checkbox" class="check mt-0.5" name="entitlements[{{ $e->value }}]" value="1" @checked($granted[$e->value] ?? false)><span><span class="font-semibold">{{ $e->label() }}</span><span class="block text-xs text-muted">{{ $e->description() }}</span></span></label>
                @endforeach
            </div>
            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($limits as $key => $label)
                    <x-field :name="'limits['.$key.']'" type="number" :label="$label" :value="$plan->limits[$key] ?? null" min="0" max="100000" hint="Leave empty for no limit."/>
                @endforeach
            </div>
            <label class="mt-4 block"><span class="label">Selling points (one per line, max 8)</span><textarea name="features" rows="4" class="textarea input" maxlength="1500">{{ old('features', implode("\n", $plan->features ?? [])) }}</textarea></label>
        </section>

        <section class="glass p-5" aria-label="Store products">
            <h2 class="text-sm font-bold uppercase">Store products</h2>
            <p class="mt-1 text-xs text-muted">Subscriptions sold inside the apps use Apple / Google billing. Enter the product id you created in App Store Connect / Play Console so a verified purchase can be matched to this plan.</p>
            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <x-field name="store_product_ids[apple]" label="Apple product id" :value="$plan->store_product_ids['apple'] ?? null" maxlength="190"/>
                <x-field name="store_product_ids[google]" label="Google Play product id" :value="$plan->store_product_ids['google'] ?? null" maxlength="190"/>
            </div>
        </section>

        <button class="btn btn-primary">{{ $isNew ? 'Create plan' : 'Save plan' }}</button>
    </form>

    @unless ($isNew || $plan->is_default)
        <form method="POST" action="{{ route('admin.memberships.destroy', $plan) }}" class="mt-8" onsubmit="return confirm('Archive this plan? It stops being offered; current members keep it until their membership ends.')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm"><x-icon name="trash" class="size-4"/>Archive plan</button></form>
    @endunless
</x-admin.shell>
