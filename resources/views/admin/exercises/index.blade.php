<x-admin.shell title="Exercises" lead="The exercise library. Exercises used by the plan or by anyone's workout history are hidden when removed, never deleted.">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <label class="min-w-48"><span class="label !mb-1">Search</span><input type="search" name="q" value="{{ $q }}" class="input !min-h-10"></label>
            <label><span class="label !mb-1">Kind</span><select name="kind" class="select !min-h-10"><option value="">All</option>@foreach ($kinds as $k)<option value="{{ $k->value }}" @selected($kind === $k->value)>{{ $k->label() }}</option>@endforeach</select></label>
            <button class="btn btn-ghost btn-sm">Filter</button>
        </form>
        <a href="{{ route('admin.exercises.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="size-4"/>New exercise</a>
    </div>
    <div class="glass relative overflow-x-auto">
        <table class="w-full min-w-[44rem] text-left text-sm">
            <thead class="text-xs uppercase tracking-wider text-muted"><tr><th class="p-3" scope="col">Name</th><th class="p-3" scope="col">Kind</th><th class="p-3" scope="col">Muscle</th><th class="p-3" scope="col">Level</th><th class="p-3" scope="col">In plan</th><th class="p-3" scope="col"><span class="sr-only">Edit</span></th></tr></thead>
            <tbody>
                @foreach ($exercises as $e)
                    <tr class="border-t border-line {{ $e->is_active ? '' : 'opacity-50' }}">
                        <td class="p-3 font-semibold">{{ $e->name }}@unless($e->is_active) <span class="chip !text-[0.6rem]">Hidden</span>@endunless</td>
                        <td class="p-3 text-muted">{{ $e->kind->label() }}</td>
                        <td class="p-3 text-muted">{{ $e->muscle_group->label() }}</td>
                        <td class="p-3 text-muted">{{ $e->difficulty->label() }}</td>
                        <td class="p-3 num">{{ $e->plan_items_count }}</td>
                        <td class="p-3 text-right"><a class="btn btn-ghost btn-sm" href="{{ route('admin.exercises.edit', $e) }}">Edit</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $exercises->links() }}</div>
</x-admin.shell>
