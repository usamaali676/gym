<x-admin.shell title="Programs" lead="Training programs the apps and the website offer. A program is invisible until it is published; the default one is where new members start.">
    @if ($errors->any())<p class="mb-4 text-sm text-danger" role="alert">{{ $errors->first() }}</p>@endif
    <div class="glass relative overflow-x-auto">
        <table class="w-full min-w-[44rem] text-left text-sm">
            <thead class="text-xs uppercase tracking-wider text-muted"><tr><th class="p-3" scope="col">Program</th><th class="p-3" scope="col">Status</th><th class="p-3" scope="col">Training days</th><th class="p-3" scope="col">Phases</th><th class="p-3" scope="col"><span class="sr-only">Edit</span></th></tr></thead>
            <tbody>
                @foreach ($programs as $p)
                    <tr class="border-t border-line">
                        <td class="p-3 font-semibold">{{ $p->name }} @if($p->is_default)<span class="chip chip-neon ml-1">Default</span>@endif @if($p->required_entitlement)<span class="chip ml-1">Premium</span>@endif<span class="block text-xs font-normal text-muted">{{ $p->slug }} · version {{ $p->version }}</span></td>
                        <td class="p-3"><span class="chip {{ $p->status === 'published' ? 'chip-neon' : '' }}">{{ ucfirst($p->status) }}</span></td>
                        <td class="p-3 num">{{ $p->training_days }}</td>
                        <td class="p-3 num">{{ $p->phases_count }}</td>
                        <td class="p-3 text-right"><a class="btn btn-ghost btn-sm" href="{{ route('admin.programs.edit', $p) }}">Open</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <form method="POST" action="{{ route('admin.programs.store') }}" class="glass mt-6 flex flex-wrap items-end gap-3 p-5">
        @csrf
        <h2 class="w-full text-sm font-bold uppercase">New program</h2>
        <x-field name="name" label="Name" class="min-w-56 flex-1" maxlength="120" required/>
        <label class="block min-w-56"><span class="label">Start from</span><select name="copy_from" class="select"><option value="">An empty program</option>@foreach ($programs as $p)<option value="{{ $p->id }}">A copy of {{ $p->name }}</option>@endforeach</select></label>
        <button class="btn btn-primary">Create draft</button>
    </form>
</x-admin.shell>
