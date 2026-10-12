@props(['value' => 0, 'max' => 100, 'over' => false, 'label' => null])
@php $pct = $max > 0 ? min(100, round($value / $max * 100, 1)) : 0; @endphp
<div class="bar {{ $over ? 'is-over' : '' }}" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $max }}" aria-valuenow="{{ round($value, 1) }}" @if($label) aria-label="{{ $label }}" @endif>
    <i data-pct="{{ $pct }}"></i>
</div>
