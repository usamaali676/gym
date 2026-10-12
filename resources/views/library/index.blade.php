@php
    $active = collect($filters)->filter(fn ($v, $k) => $v !== null && $v !== '' && $v !== false)->except('page')->count();
    $selectClass = 'select !min-h-11 text-sm';
@endphp
<x-layouts.app title="Exercise library" page="library">
    <div class="mb-6">
        <p class="eyebrow">Library</p>
        <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">Exercise library</h1>
        <p class="mt-1 max-w-2xl text-sm text-muted">Every move in your plan plus alternatives, warm-ups and mobility work. Filter by muscle, equipment and difficulty.</p>
    </div>

    <form method="GET" action="{{ route('library.index') }}" class="glass mb-6 space-y-3 p-4" role="search" aria-label="Filter exercises" x-data x-on:change="$el.requestSubmit()">
        <div class="relative">
            <x-icon name="search" class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-muted"/>
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="input !pl-11" placeholder="Search exercises, e.g. bench, curl, squat" aria-label="Search exercises" maxlength="80" x-on:change.stop x-on:keydown.enter.prevent="$el.form.requestSubmit()">
        </div>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <label><span class="sr-only">Muscle group</span>
                <select name="muscle" class="{{ $selectClass }}"><option value="">Muscle</option>@foreach ($muscles as $m)<option value="{{ $m->value }}" @selected(($filters['muscle'] ?? '') === $m->value)>{{ $m->label() }}</option>@endforeach</select></label>
            <label><span class="sr-only">Equipment</span>
                <select name="equipment" class="{{ $selectClass }}"><option value="">Equipment</option>@foreach ($equipment as $key => $label)<option value="{{ $key }}" @selected(($filters['equipment'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label><span class="sr-only">Difficulty</span>
                <select name="difficulty" class="{{ $selectClass }}"><option value="">Level</option>@foreach ($difficulties as $d)<option value="{{ $d->value }}" @selected(($filters['difficulty'] ?? '') === $d->value)>{{ $d->label() }}</option>@endforeach</select></label>
            <label><span class="sr-only">Type</span>
                <select name="kind" class="{{ $selectClass }}"><option value="">Type</option>@foreach ($kinds as $k)<option value="{{ $k->value }}" @selected(($filters['kind'] ?? '') === $k->value)>{{ $k->label() }}</option>@endforeach</select></label>
            <label><span class="sr-only">Movement type</span>
                <select name="mechanic" class="{{ $selectClass }}"><option value="">Movement</option>@foreach ($mechanics as $m)<option value="{{ $m->value }}" @selected(($filters['mechanic'] ?? '') === $m->value)>{{ $m->label() }}</option>@endforeach</select></label>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <label class="flex items-center gap-2 text-sm text-muted"><input type="checkbox" class="check" name="usable" value="1" @checked($filters['usable'])> Only what I can do with my equipment</label>
            <div class="flex items-center gap-3">
                <p class="text-sm text-muted"><span class="num text-ink">{{ $exercises->total() }}</span> {{ \Illuminate\Support\Str::plural('exercise', $exercises->total()) }}</p>
                @if ($active)<a href="{{ route('library.index') }}" class="text-sm font-semibold text-neon underline underline-offset-4">Clear filters</a>@endif
                <noscript><button class="btn btn-primary btn-sm">Apply</button></noscript>
            </div>
        </div>
    </form>

    @if ($exercises->isEmpty())
        <div class="glass p-10 text-center">
            <x-icon name="search" class="mx-auto size-10 text-muted"/>
            <h2 class="mt-3 text-lg font-bold uppercase">No exercises found</h2>
            <p class="mt-1 text-sm text-muted">Try a different search or clear the filters.</p>
            <a class="btn btn-ghost mt-4" href="{{ route('library.index') }}">Clear filters</a>
        </div>
    @else
        <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($exercises as $i => $ex)
                <li>
                    <a href="{{ route('library.show', $ex) }}" class="glass glass-hover flex h-full gap-4 p-4">
                        <x-demo-thumb :exercise="$ex"/>
                        <div class="min-w-0">
                            <h2 class="truncate text-base font-bold">{{ $ex->name }}</h2>
                            <p class="mt-1 flex flex-wrap gap-1.5 text-[0.7rem]">
                                <span class="chip chip-neon">{{ $ex->muscle_group->label() }}</span>
                                @if ($ex->kind->value !== 'plan')<span class="chip">{{ $ex->kind->label() }}</span>@else<span class="chip">In plan</span>@endif
                                <span class="chip">{{ $ex->difficulty->label() }}</span>
                            </p>
                            <p class="mt-2 truncate text-xs text-muted">{{ collect($ex->equipment ?? [])->map(fn ($t) => str_replace('_', ' ', $t))->implode(' · ') }}</p>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
        {{ $exercises->links() }}
    @endif
</x-layouts.app>
