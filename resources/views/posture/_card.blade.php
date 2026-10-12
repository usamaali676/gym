@php $ex = $rule->exercise; @endphp
<li>
    <a href="{{ route('posture.show', $ex) }}" class="glass glass-hover flex h-full gap-4 p-4 {{ $highlight ? 'glass-neon' : '' }}">
        <x-demo-thumb :exercise="$ex"/>
        <div class="min-w-0 flex-1">
            <h3 class="truncate text-base font-bold">{{ $ex->name }}</h3>
            <p class="mt-0.5 text-xs text-muted">{{ $ex->muscle_group->label() }}</p>
            <ul class="mt-2 flex flex-wrap gap-1.5 text-[0.7rem]">
                <li class="chip"><x-icon name="camera" class="size-3.5"/>{{ $rule->camera_view->label() }}</li>
                <li class="chip {{ $rule->tier->value === 'full' ? 'chip-neon' : '' }}">{{ $rule->tier->label() }}</li>
            </ul>
        </div>
        <x-icon name="chevron-right" class="mt-1 size-5 shrink-0 self-center text-muted"/>
    </a>
</li>
