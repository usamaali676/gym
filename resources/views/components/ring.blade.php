@props(['value' => 0, 'size' => 120, 'stroke' => 10, 'label' => null])
@php
    $r = ($size - $stroke) / 2;
    $c = $size / 2;
@endphp
<div class="gauge" style="width: {{ $size }}px; height: {{ $size }}px" data-ring="{{ round(max(0, min(1, $value)), 4) }}" @if($label) role="img" aria-label="{{ $label }}" @endif>
    <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 {{ $size }} {{ $size }}" aria-hidden="true">
        <circle class="gauge-track" cx="{{ $c }}" cy="{{ $c }}" r="{{ $r }}" stroke-width="{{ $stroke }}" fill="none"/>
        <circle class="gauge-value" cx="{{ $c }}" cy="{{ $c }}" r="{{ $r }}" stroke-width="{{ $stroke }}" fill="none"/>
    </svg>
    <div class="gauge-label">{{ $slot }}</div>
</div>
