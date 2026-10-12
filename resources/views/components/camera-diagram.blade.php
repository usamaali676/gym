@props(['view' => 'side'])
{{-- Top-down sketch: where to put the camera relative to the person. The person faces the arrow. --}}
@php
    // camera position and the direction the person faces, in a 200x150 box
    $setups = [
        'side' => ['cam' => [100, 128], 'angle' => 0, 'label' => 'Camera at your side (90°)', 'face' => 'right'],
        'front' => ['cam' => [160, 75], 'angle' => 90, 'label' => 'Camera in front of you', 'face' => 'right'],
        'three_quarter' => ['cam' => [146, 118], 'angle' => 45, 'label' => 'Camera at 45°', 'face' => 'right'],
    ];
    $s = $setups[$view] ?? $setups['side'];
    [$cx, $cy] = $s['cam'];
    // Aim the camera at the person (centre 60,75)
    $dx = 60 - $cx; $dy = 75 - $cy;
    $deg = rad2deg(atan2($dy, $dx));
@endphp
<figure {{ $attributes->merge(['class' => 'text-center']) }}>
    <svg viewBox="0 0 200 150" class="mx-auto h-auto w-full max-w-[16rem]" role="img" aria-label="{{ $s['label'] }}">
        <rect x="1" y="1" width="198" height="148" rx="14" fill="rgba(57,255,20,0.04)" stroke="rgba(57,255,20,0.25)"/>
        {{-- person: head, shoulders and a facing arrow --}}
        <circle cx="60" cy="75" r="9" fill="#39FF14"/>
        <ellipse cx="60" cy="75" rx="5" ry="24" fill="none" stroke="#39FF14" stroke-width="3" transform="rotate(0 60 75)"/>
        <path d="M60 75 L92 75" stroke="#8FA39A" stroke-width="2" stroke-dasharray="3 3"/>
        <path d="M92 75 l-7 -4 m7 4 l-7 4" stroke="#8FA39A" stroke-width="2" fill="none"/>
        <text x="60" y="48" text-anchor="middle" font-size="9" fill="#8FA39A" font-family="Inter, sans-serif">you</text>
        {{-- camera and its field of view --}}
        <g transform="translate({{ $cx }} {{ $cy }}) rotate({{ round($deg, 1) }})">
            <path d="M0 0 L-62 -26 L-62 26 Z" fill="rgba(57,255,20,0.10)" stroke="rgba(57,255,20,0.35)"/>
            <rect x="-4" y="-8" width="14" height="16" rx="3" fill="#E8F5E9"/>
            <circle cx="-4" cy="0" r="3.5" fill="#07090A"/>
        </g>
    </svg>
    <figcaption class="mt-1 text-xs text-muted">{{ $s['label'] }}</figcaption>
</figure>
