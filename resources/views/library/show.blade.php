@php
    $primary = $exercise->target_regions['primary'] ?? [];
    $secondary = $exercise->target_regions['secondary'] ?? [];
    $isMobility = $exercise->kind->value === 'mobility';
    $restLabel = $isMobility ? null : ($exercise->rest_max_seconds >= 120
        ? round($exercise->rest_min_seconds / 60, 1).'-'.round($exercise->rest_max_seconds / 60, 1).' min'
        : $exercise->rest_min_seconds.'-'.$exercise->rest_max_seconds.' s');
    $postureUrl = $exercise->postureRules->where('is_active', true)->isNotEmpty() ? route_if('posture.show', ['exercise' => $exercise]) : null;
@endphp
<x-layouts.app :title="$exercise->name" page="exercise">
    <a href="{{ route('library.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><x-icon name="chevron-left" class="size-4"/>Library</a>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-5">
        <article class="glass glass-neon p-5 sm:p-7 lg:col-span-3">
            <p class="eyebrow">{{ $exercise->kind->label() }} · {{ $exercise->muscle_group->label() }}</p>
            <h1 class="mt-2 text-2xl font-black uppercase sm:text-4xl">{{ $exercise->name }}</h1>
            <p class="mt-3 text-muted">{{ $exercise->description }}</p>

            <ul class="mt-4 flex flex-wrap gap-2 text-xs">
                <li class="chip chip-neon">{{ $exercise->mechanic->label() }}</li>
                <li class="chip">{{ $exercise->difficulty->label() }}</li>
                @foreach ($exercise->equipment ?? [] as $tag)<li class="chip">{{ str_replace('_', ' ', $tag) }}</li>@endforeach
                @if ($restLabel)<li class="chip"><x-icon name="timer" class="size-3.5"/>Rest {{ $restLabel }}</li>@endif
                @if ($isMobility && $exercise->default_hold_seconds)<li class="chip"><x-icon name="clock" class="size-3.5"/>{{ $exercise->default_hold_seconds }} s{{ $exercise->per_side ? ' each side' : '' }}</li>@endif
            </ul>

            @unless ($canDo)
                <p class="mt-4 rounded-xl border border-line-strong bg-neon/5 p-3 text-sm"><x-icon name="info" class="mr-1 inline size-4 text-neon"/>This needs equipment that is not in your profile. <a class="font-semibold text-neon underline underline-offset-4" href="{{ route('profile.edit') }}">Update your equipment</a>.</p>
            @endunless

            <div class="demo-stage relative mt-5 grid aspect-[4/3] min-h-[16rem] w-full place-items-center overflow-hidden rounded-2xl border border-line bg-black/30"
                 data-demo="{{ $exercise->demo_key ?: $exercise->slug }}" data-group="{{ $exercise->muscle_group->value }}" @if($exercise->glb_path) data-glb="{{ asset('storage/'.$exercise->glb_path) }}" @endif
                 data-regions="{{ json_encode($exercise->target_regions ?? ['primary' => [], 'secondary' => []]) }}">
                <x-icon name="dumbbell" class="size-16 text-neon/40"/>
            </div>

            <div class="mt-5 flex flex-wrap gap-2">
                @if ($postureUrl)<a href="{{ $postureUrl }}" class="btn btn-primary"><x-icon name="scan" class="size-4"/>Check my posture</a>@endif
            </div>
        </article>

        <aside class="glass p-5 lg:col-span-2" aria-labelledby="muscles-title">
            <h2 id="muscles-title" class="eyebrow">Target muscles</h2>
            <dl class="mt-3 space-y-3 text-sm">
                <div><dt class="label !mb-1">Primary</dt><dd class="flex flex-wrap gap-1.5">@forelse ($primary as $r)<span class="chip chip-neon">{{ $regions[$r] ?? $r }}</span>@empty<span class="text-muted">-</span>@endforelse</dd></div>
                @if ($secondary)<div><dt class="label !mb-1">Assisting</dt><dd class="flex flex-wrap gap-1.5">@foreach ($secondary as $r)<span class="chip">{{ $regions[$r] ?? $r }}</span>@endforeach</dd></div>@endif
            </dl>
        </aside>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <section class="glass p-5" aria-labelledby="cues-title">
            <h2 id="cues-title" class="flex items-center gap-2 text-base font-bold uppercase"><x-icon name="check" class="size-5 text-neon"/>Form cues</h2>
            <ol class="mt-3 space-y-2.5 text-sm">
                @foreach ($exercise->form_cues ?? [] as $n => $cue)
                    <li class="flex gap-3"><span class="num grid size-6 shrink-0 place-items-center rounded-md bg-neon/10 text-neon">{{ $n + 1 }}</span><span>{{ $cue }}</span></li>
                @endforeach
            </ol>
        </section>
        <section class="glass p-5" aria-labelledby="mistakes-title">
            <h2 id="mistakes-title" class="flex items-center gap-2 text-base font-bold uppercase"><x-icon name="alert" class="size-5 text-danger"/>Common mistakes</h2>
            <ul class="mt-3 space-y-2.5 text-sm">
                @foreach ($exercise->common_mistakes ?? [] as $mistake)
                    <li class="flex gap-3"><x-icon name="x" class="mt-0.5 size-4 shrink-0 text-danger"/><span>{{ $mistake }}</span></li>
                @endforeach
            </ul>
            @if ($exercise->breathing)
                <h3 class="mt-5 flex items-center gap-2 text-sm font-bold uppercase"><x-icon name="heart" class="size-4 text-neon"/>Breathing</h3>
                <p class="mt-1 text-sm text-muted">{{ $exercise->breathing }}</p>
            @endif
        </section>
    </div>

    @if ($usedIn->isNotEmpty())
        <section class="mt-4 glass p-5" aria-labelledby="plan-title">
            <h2 id="plan-title" class="text-base font-bold uppercase">In your plan</h2>
            <ul class="mt-3 flex flex-wrap gap-2">
                @foreach ($usedIn as $row)
                    <li><a class="chip chip-neon hover:bg-neon/15" href="{{ route('plan.day', $row->day->day_of_week) }}">{{ $row->day->name }} · {{ $row->sets }} × {{ $row->repsLabel() }}</a></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($related->isNotEmpty())
        <section class="mt-4" aria-labelledby="related-title">
            <h2 id="related-title" class="eyebrow mb-3">Same muscle group</h2>
            <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($related as $r)
                    <li><a href="{{ route('library.show', $r) }}" class="glass glass-hover flex items-center gap-3 p-3"><x-demo-thumb :exercise="$r"/><span class="min-w-0"><span class="block truncate font-semibold">{{ $r->name }}</span><span class="text-xs text-muted">{{ $r->kind->label() }} · {{ $r->difficulty->label() }}</span></span></a></li>
                @endforeach
            </ul>
        </section>
    @endif
</x-layouts.app>
