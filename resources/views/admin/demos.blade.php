<x-layouts.app title="3D demo gallery" page="admin-demos">
    <div class="mb-6">
        <p class="eyebrow">Admin</p>
        <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">3D demo gallery</h1>
        <p class="mt-1 text-sm text-muted">Each exercise at four points of its loop. Filter: @foreach (['' => 'All', 'plan' => 'Plan', 'alternative' => 'Alternatives', 'helper' => 'Helpers', 'mobility' => 'Mobility'] as $k => $label)<a class="mx-1 {{ ($kind ?? '') === $k ? 'text-neon underline' : 'underline-offset-4 hover:underline' }}" href="{{ route('admin.demos', $k ? ['kind' => $k] : []) }}">{{ $label }}</a>@endforeach</p>
    </div>
    <ul class="grid grid-cols-1 gap-3 {{ request()->boolean('big') ? '' : 'md:grid-cols-2' }}">
        @foreach ($exercises as $exercise)
            <li class="glass p-3">
                <p class="mb-2 flex items-center justify-between gap-2 text-sm font-bold"><span class="truncate">{{ $exercise->name }}</span><span class="chip text-[0.65rem]">{{ $exercise->demo_key }}</span></p>
                <div class="grid grid-cols-4 gap-2">
                    @foreach ([0.05, 0.3, 0.5, 0.75] as $t)
                        <div class="demo-thumb aspect-square rounded-lg border border-line bg-black/30" data-demo="{{ $exercise->demo_key ?: $exercise->slug }}" data-t="{{ $t }}" data-view="angle" data-regions="{{ json_encode($exercise->target_regions ?? ['primary' => [], 'secondary' => []]) }}"></div>
                    @endforeach
                </div>
            </li>
        @endforeach
    </ul>
</x-layouts.app>
