@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'hint' => null, 'bag' => 'default', 'id' => null])
@php
    $id ??= 'f-'.str_replace(['[', ']', '.'], '-', $name);
    $hasError = $errors->getBag($bag)->has($name);
@endphp
<div {{ $attributes->only('class') }}>
    @if($label)<label class="label" for="{{ $id }}">{{ $label }}</label>@endif
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
           @unless($type === 'password') value="{{ old($name, $value) }}" @endunless
           class="input" @if($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @elseif($hint) aria-describedby="{{ $id }}-hint" @endif
           {{ $attributes->except('class') }}>
    @if($hint && ! $hasError)<p class="hint" id="{{ $id }}-hint">{{ $hint }}</p>@endif
    @if($hasError)<p class="error" id="{{ $id }}-error" role="alert">{{ $errors->getBag($bag)->first($name) }}</p>@endif
</div>
