@php
    $fieldSteps = [
        'name' => 1, 'age' => 1, 'sex' => 1,
        'height_cm' => 2, 'weight_kg' => 2, 'activity_level' => 2, 'fitness_level' => 2,
        'goal' => 3, 'diet_preference' => 3,
        'equipment' => 4, 'training_days' => 4, 'timezone' => 4,
    ];
    $errorStep = collect($errors->keys())->map(fn ($k) => $fieldSteps[explode('.', $k)[0]] ?? 1)->min() ?? 1;
    $steps = [1 => 'You', 2 => 'Body', 3 => 'Goal', 4 => 'Setup'];
    $config = [
        'wizard' => true,
        'errorStep' => $errorStep,
        'unit' => old('unit_system', $profile?->unit_system?->value ?? 'metric'),
        'heightCm' => old('height_cm', $profile?->height_cm),
        'weightKg' => old('weight_kg', $profile?->weight_kg),
        'timezone' => old('timezone', $profile?->timezone !== 'UTC' ? $profile?->timezone : null),
        'equipment' => old('equipment', $profile?->equipment ?? []),
        'allEquipment' => array_keys(config('ironpulse.equipment')),
    ];
@endphp
<x-layouts.guest title="Set up your profile" :wide="true">
    <div x-data="bodyForm(@js($config))">
        <div class="mb-6">
            <p class="eyebrow">Step <span x-text="step">1</span> of 4</p>
            <ol class="mt-3 grid grid-cols-4 gap-2" aria-label="Progress">
                @foreach ($steps as $n => $label)
                    <li class="text-center">
                        <div class="bar" role="presentation"><i :style="{ width: step >= {{ $n }} ? '100%' : '0%' }" style="width:0;transition-duration:.4s"></i></div>
                        <span class="mt-1.5 block text-[0.7rem] font-semibold uppercase tracking-widest" :class="step === {{ $n }} ? 'text-neon' : 'text-muted'">{{ $label }}</span>
                    </li>
                @endforeach
            </ol>
        </div>

        <form method="POST" action="{{ route('onboarding.store') }}" novalidate @submit="if (!stepIsValid()) $event.preventDefault()">
            @csrf
            <section x-ref="step1" x-show="step === 1" aria-labelledby="s1-title">
                <h1 id="s1-title" class="text-xl font-black uppercase">Let us get to know you</h1>
                <p class="mb-6 mt-1 text-sm text-muted">This tunes your calories, targets and plan. You can change everything later.</p>
                @include('profile._fields', ['group' => 'identity', 'wizard' => true])
            </section>
            <section x-ref="step2" x-show="step === 2" x-cloak aria-labelledby="s2-title">
                <h2 id="s2-title" class="text-xl font-black uppercase">Your body</h2>
                <p class="mb-6 mt-1 text-sm text-muted">Used for calorie and protein targets and to track progress.</p>
                @include('profile._fields', ['group' => 'body', 'wizard' => true])
            </section>
            <section x-ref="step3" x-show="step === 3" x-cloak aria-labelledby="s3-title">
                <h2 id="s3-title" class="text-xl font-black uppercase">Your goal</h2>
                <p class="mb-6 mt-1 text-sm text-muted">Pick what you are working toward right now.</p>
                @include('profile._fields', ['group' => 'goal', 'wizard' => true])
            </section>
            <section x-ref="step4" x-show="step === 4" x-cloak aria-labelledby="s4-title">
                <h2 id="s4-title" class="text-xl font-black uppercase">Your setup</h2>
                <p class="mb-6 mt-1 text-sm text-muted">Tell us what you have so swaps and alternatives fit your gym.</p>
                @include('profile._fields', ['group' => 'setup', 'wizard' => true])
            </section>

            <div class="mt-8 flex items-center justify-between gap-3">
                <button type="button" class="btn btn-ghost" @click="prev()" x-show="step > 1" x-cloak><x-icon name="chevron-left" class="size-4"/>Back</button>
                <span x-show="step === 1"></span>
                <button type="button" class="btn btn-primary btn-lg" @click="next()" x-show="step < total">Next <x-icon name="chevron-right" class="size-5"/></button>
                <button type="submit" class="btn btn-primary btn-lg" x-show="step === total" x-cloak>Finish setup <x-icon name="check" class="size-5"/></button>
            </div>
        </form>
    </div>
</x-layouts.guest>
