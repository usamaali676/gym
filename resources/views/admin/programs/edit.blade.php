<x-admin.shell :title="$plan->name" section="programs" :lead="'Version '.$plan->version.' · '.$plan->days->where('is_rest', false)->count().' training days'">
    <p class="mb-4 flex flex-wrap gap-4 text-sm font-semibold"><a href="{{ route('admin.programs.index') }}" class="text-neon underline underline-offset-4">All programs</a><a href="{{ route('admin.plan.index', ['plan' => $plan->id]) }}" class="text-neon underline underline-offset-4">Edit days and exercises</a></p>
    @if ($errors->any())<p class="mb-4 text-sm text-danger" role="alert">{{ $errors->first() }}</p>@endif

    <form method="POST" action="{{ route('admin.programs.update', $plan) }}" class="glass grid grid-cols-1 gap-3 p-5 md:grid-cols-2" aria-label="Program settings">
        @csrf @method('PATCH')
        <x-field name="name" label="Name" :value="$plan->name" maxlength="120" required/>
        <label class="block"><span class="label">Status</span><select name="status" class="select">@foreach (['draft' => 'Draft (hidden from apps and the website)', 'published' => 'Published', 'archived' => 'Archived (hidden)'] as $k => $l)<option value="{{ $k }}" @selected(old('status', $plan->status) === $k)>{{ $l }}</option>@endforeach</select></label>
        <label class="block md:col-span-2"><span class="label">Description</span><textarea name="description" rows="3" class="textarea input" maxlength="2000">{{ old('description', $plan->description) }}</textarea></label>
        <label class="block"><span class="label">Who can open it</span><select name="required_entitlement" class="select"><option value="">Everyone</option>@foreach ($gates as $g)<option value="{{ $g->value }}" @selected(old('required_entitlement', $plan->required_entitlement) === $g->value)>Members whose plan includes: {{ $g->label() }}</option>@endforeach</select></label>
        <div class="flex items-end gap-2 pb-3">
            <input type="hidden" name="is_default" value="{{ $plan->is_default ? 1 : 0 }}">
            @unless ($plan->is_default)<label class="flex items-center gap-2 text-sm"><input type="checkbox" class="check" name="is_default" value="1" @checked(old('is_default'))> Make this the default program</label>@else<span class="chip chip-neon">Default program</span>@endunless
        </div>
        <div class="md:col-span-2"><button class="btn btn-primary">Save program</button>
            <p class="mt-2 text-xs text-muted">Changes do not rewrite anyone's workout history: finished sessions keep the exercises, sets and weights they were done with.</p></div>
    </form>

    <section class="mt-8" aria-labelledby="phases-title">
        <h2 id="phases-title" class="text-sm font-bold uppercase">Sprints and phases</h2>
        <p class="mt-1 max-w-3xl text-sm text-muted">Optional. Week numbers count from the day a member started the program. With none defined, the apps show the plain week and day view. Nothing is invented for you: the seeded program has no phases.</p>
        <div class="mt-3 space-y-3">
            @foreach ($plan->phases as $phase)
                <form method="POST" action="{{ route('admin.programs.phases.update', $phase) }}" class="glass grid grid-cols-2 items-end gap-2 p-4 md:grid-cols-12">
                    @csrf @method('PATCH')
                    <label class="md:col-span-2"><span class="label !mb-1">Type</span><select name="kind" class="select !min-h-10">@foreach (['sprint', 'phase'] as $k)<option value="{{ $k }}" @selected($phase->kind === $k)>{{ ucfirst($k) }}</option>@endforeach</select></label>
                    <label class="md:col-span-3"><span class="label !mb-1">Name</span><input name="name" class="input !min-h-10" value="{{ $phase->name }}" maxlength="80" required></label>
                    <label class="md:col-span-1"><span class="label !mb-1">From wk</span><input type="number" name="starts_week" class="input !min-h-10" value="{{ $phase->starts_week }}" min="1" required></label>
                    <label class="md:col-span-1"><span class="label !mb-1">To wk</span><input type="number" name="ends_week" class="input !min-h-10" value="{{ $phase->ends_week }}" min="1" placeholder="end"></label>
                    <label class="md:col-span-2"><span class="label !mb-1">Status</span><select name="status" class="select !min-h-10">@foreach (['draft', 'published'] as $k)<option value="{{ $k }}" @selected($phase->status === $k)>{{ ucfirst($k) }}</option>@endforeach</select></label>
                    <div class="col-span-2 flex gap-2 md:col-span-3"><button class="btn btn-ghost btn-sm">Save</button><button class="btn btn-danger btn-sm" form="del-phase-{{ $phase->id }}">Remove</button></div>
                    <label class="col-span-2 md:col-span-12"><span class="label !mb-1">Summary</span><input name="summary" class="input !min-h-10" value="{{ $phase->summary }}" maxlength="1000"></label>
                </form>
                <form id="del-phase-{{ $phase->id }}" method="POST" action="{{ route('admin.programs.phases.destroy', $phase) }}" onsubmit="return confirm('Remove this phase?')">@csrf @method('DELETE')</form>
            @endforeach
            <form method="POST" action="{{ route('admin.programs.phases.store', $plan) }}" class="glass grid grid-cols-2 items-end gap-2 p-4 md:grid-cols-12">
                @csrf
                <h3 class="col-span-2 text-xs font-bold uppercase tracking-wider text-muted md:col-span-12">Add a sprint or phase</h3>
                <label class="md:col-span-2"><span class="label !mb-1">Type</span><select name="kind" class="select !min-h-10"><option value="phase">Phase</option><option value="sprint">Sprint</option></select></label>
                <label class="md:col-span-4"><span class="label !mb-1">Name</span><input name="name" class="input !min-h-10" maxlength="80" required></label>
                <label class="md:col-span-1"><span class="label !mb-1">From wk</span><input type="number" name="starts_week" class="input !min-h-10" min="1" value="1" required></label>
                <label class="md:col-span-1"><span class="label !mb-1">To wk</span><input type="number" name="ends_week" class="input !min-h-10" min="1" placeholder="end"></label>
                <label class="md:col-span-2"><span class="label !mb-1">Status</span><select name="status" class="select !min-h-10"><option value="draft">Draft</option><option value="published">Published</option></select></label>
                <button class="btn btn-primary btn-sm md:col-span-2">Add</button>
                <label class="col-span-2 md:col-span-12"><span class="label !mb-1">Summary</span><input name="summary" class="input !min-h-10" maxlength="1000"></label>
            </form>
        </div>
    </section>
</x-admin.shell>
