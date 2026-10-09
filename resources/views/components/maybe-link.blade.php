@props(['href' => null])
@if ($href)<a href="{{ $href }}" {{ $attributes }}>{{ $slot }}</a>@else<div {{ $attributes }}>{{ $slot }}</div>@endif
