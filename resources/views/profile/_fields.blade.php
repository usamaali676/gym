{{--
    Body / goal fields shared by the onboarding wizard and the profile page.
    Expects: $profile (?Profile), $user (User), $wizard (bool), $group ('identity'|'body'|'goal'|'setup')
--}}
@php
    $val = fn (string $key, $default = null) => old($key, data_get($profile, $key) instanceof \BackedEnum ? data_get($profile, $key)->value : (data_get($profile, $key) ?? $default));
    $enumOptions = fn (string $enum) => collect($enum::cases());
@endphp

@if ($group === 'identity')
    <div class="space-y-5">
        <x-field name="name" label="Your name" :value="$user->is_guest && $user->name === 'Guest' ? '' : $user->name" required maxlength="120" autocomplete="name"/>
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <x-field name="age" type="number" label="Age" :value="$val('age')" required min="14" max="90" inputmode="numeric"/>
            @unless ($wizard)
                <x-field name="public_name" label="Leaderboard name (optional)" :value="$val('public_name')" maxlength="60" hint="Shown instead of your real name if you join the leaderboard."/>
            @endunless
        </div>
        <fieldset>
            <legend class="label">Sex <span class="normal-case tracking-normal">(used only for calorie maths)</span></legend>
            <div class="grid grid-cols-3 gap-2">
                @foreach ($enumOptions(\App\Enums\Sex::class) as $opt)
                    <label class="tile"><input type="radio" name="sex" value="{{ $opt->value }}" @checked($val('sex') === $opt->value) required><span class="tile-body text-center text-sm font-semibold">{{ $opt->label() }}</span></label>
                @endforeach
            </div>
            @error('sex')<p class="error" role="alert">{{ $message }}</p>@enderror
        </fieldset>
    </div>
@endif

@if ($group === 'body')
    <div class="space-y-5">
        <div class="flex items-center justify-between gap-3">
            <p class="label !mb-0">Units</p>
            <div class="inline-flex rounded-xl border border-line p-1" role="group" aria-label="Units">
                <button type="button" class="btn btn-sm !min-h-9" :class="unit === 'metric' ? 'btn-primary' : 'btn-ghost !border-transparent'" @click="setUnit('metric')" :aria-pressed="(unit === 'metric').toString()">kg · cm</button>
                <button type="button" class="btn btn-sm !min-h-9" :class="unit === 'imperial' ? 'btn-primary' : 'btn-ghost !border-transparent'" @click="setUnit('imperial')" :aria-pressed="(unit === 'imperial').toString()">lb · ft</button>
            </div>
            <input type="hidden" name="unit_system" :value="unit">
        </div>

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
                <p class="label" id="height-label">Height</p>
                <div x-show="unit === 'metric'" class="relative">
                    <input type="number" step="0.1" min="100" max="250" class="input pr-12" x-model="cm" :required="unit === 'metric'" aria-labelledby="height-label" inputmode="decimal">
                    <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted">cm</span>
                </div>
                <div x-show="unit === 'imperial'" x-cloak class="grid grid-cols-2 gap-2">
                    <div class="relative"><input type="number" min="3" max="8" class="input pr-9" x-model="ft" :required="unit === 'imperial'" aria-label="Feet" inputmode="numeric"><span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted">ft</span></div>
                    <div class="relative"><input type="number" min="0" max="11" class="input pr-9" x-model="inch" :required="unit === 'imperial'" aria-label="Inches" inputmode="numeric"><span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted">in</span></div>
                </div>
                <input type="hidden" name="height_cm" :value="heightCm" value="{{ $val('height_cm') }}">
                <p class="hint" x-text="heightHint ? '= ' + heightHint : ''"></p>
                @error('height_cm')<p class="error" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <p class="label" id="weight-label">Weight</p>
                <div x-show="unit === 'metric'" class="relative">
                    <input type="number" step="0.1" min="30" max="300" class="input pr-12" x-model="kg" :required="unit === 'metric'" aria-labelledby="weight-label" inputmode="decimal">
                    <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted">kg</span>
                </div>
                <div x-show="unit === 'imperial'" x-cloak class="relative">
                    <input type="number" step="0.1" min="66" max="660" class="input pr-12" x-model="lb" :required="unit === 'imperial'" aria-labelledby="weight-label" inputmode="decimal">
                    <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted">lb</span>
                </div>
                <input type="hidden" name="weight_kg" :value="weightKg" value="{{ $val('weight_kg') }}">
                <p class="hint" x-text="weightHint ? '= ' + weightHint : ''"></p>
                @error('weight_kg')<p class="error" role="alert">{{ $message }}</p>@enderror
            </div>
        </div>

        <div>
            <label class="label" for="activity_level">Activity level</label>
            <select id="activity_level" name="activity_level" class="select" required>
                <option value="" disabled @selected(! $val('activity_level'))>Choose one…</option>
                @foreach ($enumOptions(\App\Enums\ActivityLevel::class) as $opt)
                    <option value="{{ $opt->value }}" @selected($val('activity_level') === $opt->value)>{{ $opt->label() }}</option>
                @endforeach
            </select>
            <p class="hint">Include your training. A 5-day lifting week is usually "moderately active".</p>
            @error('activity_level')<p class="error" role="alert">{{ $message }}</p>@enderror
        </div>

        <fieldset>
            <legend class="label">Fitness level</legend>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                @foreach ($enumOptions(\App\Enums\FitnessLevel::class) as $opt)
                    <label class="tile"><input type="radio" name="fitness_level" value="{{ $opt->value }}" @checked($val('fitness_level') === $opt->value) required><span class="tile-body text-center text-sm font-semibold">{{ $opt->label() }}</span></label>
                @endforeach
            </div>
            @error('fitness_level')<p class="error" role="alert">{{ $message }}</p>@enderror
        </fieldset>
    </div>
@endif

@if ($group === 'goal')
    @php
        $goalCopy = [
            'cutting' => 'Lose fat, keep muscle. Calories below maintenance.',
            'bulking' => 'Add muscle. A small calorie surplus.',
            'maintenance' => 'Hold your weight and keep performing.',
            'recomposition' => 'Build muscle and lose fat together. About maintenance calories.',
        ];
        $dietCopy = ['nonveg' => 'Meat, fish, eggs, dairy', 'eggetarian' => 'Eggs and dairy, no meat', 'vegetarian' => 'Dairy, no meat or eggs', 'vegan' => 'Plants only'];
    @endphp
    <div class="space-y-6">
        <fieldset>
            <legend class="label">Your goal</legend>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach ($enumOptions(\App\Enums\Goal::class) as $opt)
                    <label class="tile"><input type="radio" name="goal" value="{{ $opt->value }}" @checked($val('goal') === $opt->value) required>
                        <span class="tile-body"><span class="text-sm font-bold">{{ $opt->label() }}</span><span class="text-xs text-muted">{{ $goalCopy[$opt->value] }}</span></span></label>
                @endforeach
            </div>
            @error('goal')<p class="error" role="alert">{{ $message }}</p>@enderror
        </fieldset>
        <fieldset>
            <legend class="label">Diet preference</legend>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach ($enumOptions(\App\Enums\DietPreference::class) as $opt)
                    <label class="tile"><input type="radio" name="diet_preference" value="{{ $opt->value }}" @checked($val('diet_preference') === $opt->value) required>
                        <span class="tile-body"><span class="text-sm font-bold">{{ $opt->label() }}</span><span class="text-xs text-muted">{{ $dietCopy[$opt->value] }}</span></span></label>
                @endforeach
            </div>
            @error('diet_preference')<p class="error" role="alert">{{ $message }}</p>@enderror
        </fieldset>
    </div>
@endif

@if ($group === 'setup')
    @php
        $days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $selectedDays = old('training_days', $profile?->trainingDays() ?? [1, 2, 3, 4, 5]);
    @endphp
    <div class="space-y-6">
        <fieldset>
            <div class="mb-2 flex items-center justify-between gap-3">
                <legend class="label !mb-0">Equipment you can use</legend>
                <button type="button" class="text-xs font-semibold text-neon underline underline-offset-4" @click="toggleAllEquipment()" x-text="equipment.length >= allEquipment.length ? 'Clear all' : 'Full gym (select all)'">Full gym (select all)</button>
            </div>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach (config('ironpulse.equipment') as $key => $label)
                    <label class="tile"><input type="checkbox" name="equipment[]" value="{{ $key }}" x-model="equipment"><span class="tile-body text-sm font-semibold">{{ $label }}</span></label>
                @endforeach
            </div>
            <p class="error" x-ref="equipmentError" hidden role="alert">Pick at least one option.</p>
            @error('equipment')<p class="error" role="alert">{{ $message }}</p>@enderror
        </fieldset>
        <fieldset>
            <legend class="label">Days you train</legend>
            <div class="grid grid-cols-7 gap-1.5 sm:gap-2">
                @foreach ($days as $n => $short)
                    <label class="tile"><input type="checkbox" name="training_days[]" value="{{ $n }}" @checked(in_array($n, array_map('intval', (array) $selectedDays), true))><span class="tile-body items-center py-3 text-center text-xs font-bold sm:text-sm">{{ $short }}</span></label>
                @endforeach
            </div>
            <p class="hint">The plan has rest days on Saturday and Sunday. Days you switch off show as rest.</p>
            @error('training_days')<p class="error" role="alert">{{ $message }}</p>@enderror
        </fieldset>
        <input type="hidden" name="timezone" :value="timezone" value="{{ $val('timezone', 'UTC') }}">
    </div>
@endif
