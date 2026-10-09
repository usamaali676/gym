@props(['protein' => 0, 'carbs' => 0, 'fat' => 0, 'calories' => 0, 'size' => 168, 'label' => 'Daily calories'])
{{-- Calories split by macro: protein and carbs give 4 kcal per gram, fat 9. Colour is never the only cue: the legend carries numbers. --}}
@php
    $kP = $protein * 4; $kC = $carbs * 4; $kF = $fat * 9;
    $total = max(1, $kP + $kC + $kF);
    $r = 42; $circ = 2 * M_PI * $r;
    $parts = [['protein', $kP, '#39FF14'], ['carbs', $kC, '#FFC83D'], ['fat', $kF, '#4DA3FF']];
    $offset = 0;
@endphp
<div class="relative mx-auto" style="width: {{ $size }}px; height: {{ $size }}px" role="img"
     aria-label="{{ $label }}: {{ number_format($calories) }} kilocalories. Protein {{ round($kP / $total * 100) }} percent, carbohydrate {{ round($kC / $total * 100) }} percent, fat {{ round($kF / $total * 100) }} percent.">
    <svg viewBox="0 0 100 100" class="size-full -rotate-90" aria-hidden="true">
        <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="11"/>
        @foreach ($parts as [$name, $kcal, $color])
            @php $len = $kcal / $total * $circ; @endphp
            <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="{{ $color }}" stroke-width="11"
                    stroke-dasharray="{{ round(max(0, $len - 1.2), 2) }} {{ round($circ - max(0, $len - 1.2), 2) }}" stroke-dashoffset="{{ round(-$offset, 2) }}"/>
            @php $offset += $len; @endphp
        @endforeach
    </svg>
    <div class="absolute inset-0 grid place-items-center text-center">
        <div>
            <p class="num text-3xl leading-none">{{ number_format($calories) }}</p>
            <p class="mt-1 text-[0.65rem] font-semibold uppercase tracking-wider text-muted">kcal</p>
        </div>
    </div>
</div>
