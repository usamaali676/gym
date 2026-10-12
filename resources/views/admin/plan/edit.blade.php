<x-admin.shell :title="'Edit '.$day->name" section="plan" :heading="$day->name.': '.$day->title()">
    <p class="mb-4"><a href="{{ route('admin.plan.index') }}" class="text-sm font-semibold text-neon underline underline-offset-4">Back to the plan</a></p>

    <section class="glass p-5" aria-labelledby="day-title">
        <h2 id="day-title" class="text-sm font-bold uppercase">Day details</h2>
        <form method="POST" action="{{ route('admin.plan.update', $day) }}" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @csrf @method('PATCH')
            <x-field name="name" label="Weekday name" :value="$day->name" maxlength="20" required/>
            <label class="block"><span class="label">Session type</span><select name="session_type" class="select">@foreach ($types as $t)<option value="{{ $t->value }}" @selected(old('session_type', $day->session_type->value) === $t->value)>{{ $t->label() }}</option>@endforeach</select></label>
            <label class="block"><span class="label">Rest day?</span><select name="is_rest" class="select"><option value="0" @selected(! old('is_rest', $day->is_rest))>No, a training day</option><option value="1" @selected(old('is_rest', $day->is_rest))>Yes, rest</option></select></label>
            <x-field name="label" label="Label" :value="$day->label" maxlength="30" required/>
            <x-field name="focus" label="Focus" :value="$day->focus" maxlength="60" required class="lg:col-span-2"/>
            <label class="block sm:col-span-2 lg:col-span-3"><span class="label">Description</span><textarea name="description" rows="2" class="textarea" maxlength="1000">{{ old('description', $day->description) }}</textarea></label>
            <label class="block sm:col-span-2 lg:col-span-3"><span class="label">Recovery note</span><textarea name="recovery_note" rows="2" class="textarea" maxlength="1000">{{ old('recovery_note', $day->recovery_note) }}</textarea></label>
            <div class="sm:col-span-2 lg:col-span-3"><button class="btn btn-primary btn-sm">Save day</button></div>
        </form>
    </section>

    <section class="mt-6" aria-labelledby="items-title">
        <h2 id="items-title" class="mb-3 text-lg font-bold uppercase">Exercises</h2>
        @if ($errors->any() && ! $errors->has('name') && ! $errors->has('label') && ! $errors->has('focus'))
            <p class="mb-3 text-sm text-danger" role="alert">{{ $errors->first() }}</p>
        @endif
        <ol class="space-y-3">
            @forelse ($day->items as $item)
                <li class="glass p-4">
                    <form method="POST" action="{{ route('admin.plan.items.update', $item) }}" class="grid grid-cols-2 items-end gap-3 md:grid-cols-6">
                        @csrf @method('PATCH')
                        <p class="col-span-2 flex items-center gap-2 text-sm font-bold md:col-span-6"><span class="num grid size-6 place-items-center rounded-full bg-neon/15 text-xs text-neon">{{ $loop->iteration }}</span>{{ $item->exercise->name }}<input type="hidden" name="exercise_id" value="{{ $item->exercise_id }}"></p>
                        <label><span class="label !mb-1">Sets</span><input type="number" name="sets" min="1" max="10" class="input !min-h-10" value="{{ $item->sets }}" required></label>
                        <label><span class="label !mb-1">Reps min</span><input type="number" name="reps_min" min="1" max="100" class="input !min-h-10" value="{{ $item->reps_min }}" required></label>
                        <label><span class="label !mb-1">Reps max</span><input type="number" name="reps_max" min="1" max="100" class="input !min-h-10" value="{{ $item->reps_max }}" required></label>
                        <label><span class="label !mb-1">Rest (s)</span><input type="number" name="rest_seconds_override" min="10" max="600" class="input !min-h-10" value="{{ $item->rest_seconds_override }}" placeholder="auto"></label>
                        <label class="col-span-2"><span class="label !mb-1">Focus</span><input type="text" name="focus" maxlength="120" class="input !min-h-10" value="{{ $item->focus }}" required></label>
                        <label class="col-span-2 md:col-span-3"><span class="label !mb-1">Printed name (optional)</span><input type="text" name="label_override" maxlength="120" class="input !min-h-10" value="{{ $item->label_override }}"></label>
                        <label class="col-span-2 md:col-span-3"><span class="label !mb-1">Notes</span><input type="text" name="notes" maxlength="255" class="input !min-h-10" value="{{ $item->notes }}"></label>
                        <div class="col-span-2 flex flex-wrap gap-2 md:col-span-6"><button class="btn btn-ghost btn-sm">Save</button></div>
                    </form>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('admin.plan.items.move', $item) }}">@csrf<input type="hidden" name="direction" value="up"><button class="btn btn-ghost btn-sm" @disabled($loop->first) aria-label="Move {{ $item->exercise->name }} up"><x-icon name="chevron-up" class="size-4"/></button></form>
                        <form method="POST" action="{{ route('admin.plan.items.move', $item) }}">@csrf<input type="hidden" name="direction" value="down"><button class="btn btn-ghost btn-sm" @disabled($loop->last) aria-label="Move {{ $item->exercise->name }} down"><x-icon name="chevron-down" class="size-4"/></button></form>
                        <form method="POST" action="{{ route('admin.plan.items.destroy', $item) }}" onsubmit="return confirm('Remove this exercise from the day? Its progression targets are dropped; past workouts keep it.')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm"><x-icon name="trash" class="size-4"/>Remove</button></form>
                    </div>
                </li>
            @empty
                <li class="glass p-4 text-sm text-muted">No exercises on this day yet.</li>
            @endforelse
        </ol>
    </section>

    <section class="glass mt-6 p-5" aria-labelledby="add-title">
        <h2 id="add-title" class="text-sm font-bold uppercase">Add an exercise</h2>
        <form method="POST" action="{{ route('admin.plan.items.store', $day) }}" class="mt-3 grid grid-cols-2 items-end gap-3 md:grid-cols-6">
            @csrf
            <label class="col-span-2 md:col-span-3"><span class="label !mb-1">Exercise</span>
                <select name="exercise_id" class="select !min-h-10" required><option value="">Choose</option>@foreach ($available as $e)<option value="{{ $e->id }}">{{ $e->name }} ({{ $e->kind->label() }})</option>@endforeach</select></label>
            <label><span class="label !mb-1">Sets</span><input type="number" name="sets" min="1" max="10" class="input !min-h-10" value="3" required></label>
            <label><span class="label !mb-1">Reps min</span><input type="number" name="reps_min" min="1" max="100" class="input !min-h-10" value="8" required></label>
            <label><span class="label !mb-1">Reps max</span><input type="number" name="reps_max" min="1" max="100" class="input !min-h-10" value="12" required></label>
            <label class="col-span-2 md:col-span-3"><span class="label !mb-1">Focus</span><input type="text" name="focus" maxlength="120" class="input !min-h-10" required placeholder="e.g. Upper chest"></label>
            <label class="col-span-2 md:col-span-2"><span class="label !mb-1">Notes</span><input type="text" name="notes" maxlength="255" class="input !min-h-10"></label>
            <button class="btn btn-primary btn-sm md:col-span-1">Add</button>
        </form>
    </section>
</x-admin.shell>
