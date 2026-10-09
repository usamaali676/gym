@props(['exercise', 'size' => 'md'])
{{-- Static 3D pose thumbnail. The page script swaps the icon for a rendered canvas when it scrolls into view. --}}
<div {{ $attributes->merge(['class' => 'demo-thumb grid shrink-0 place-items-center overflow-hidden rounded-xl border border-line bg-black/30 '.($size === 'lg' ? 'size-28' : 'size-16')]) }}
     data-demo="{{ $exercise->demo_key ?: $exercise->slug }}" data-group="{{ $exercise->muscle_group->value }}"
     data-regions="{{ json_encode($exercise->target_regions ?? ['primary' => [], 'secondary' => []]) }}" aria-hidden="true">
    <x-icon name="dumbbell" class="{{ $size === 'lg' ? 'size-10' : 'size-6' }} text-neon/50"/>
</div>
