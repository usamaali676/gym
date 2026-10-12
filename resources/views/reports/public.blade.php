<x-layouts.public :title="$r['name'].'\'s week'">
    <div class="mb-6">
        <p class="eyebrow">Weekly report</p>
        <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">{{ $r['name'] }}'s week</h1>
        <p class="mt-1 text-sm text-muted">{{ $r['period']['label'] }}</p>
    </div>
    @include('reports._body', ['r' => $r, 'public' => true])
</x-layouts.public>
