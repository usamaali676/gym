@props(['status'])
@php
    $map = [
        'completed' => ['Completed', 'chip-neon', 'check'],
        'skipped' => ['Skipped', 'chip-danger', 'skip'],
        'in_progress' => ['In progress', 'chip-neon', 'play'],
        'missed' => ['Missed', 'chip-danger', 'x'],
        'today' => ['Today', 'chip-neon', 'target'],
        'upcoming' => ['Upcoming', '', 'clock'],
        'rest' => ['Rest day', '', 'moon'],
    ];
    [$label, $class, $icon] = $map[$status] ?? ['Upcoming', '', 'clock'];
@endphp
<span {{ $attributes->merge(['class' => "chip $class"]) }}><x-icon :name="$icon" class="size-3.5"/>{{ $label }}</span>
