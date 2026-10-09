@php
    $bodyConfig = [
        'wizard' => false,
        'unit' => old('unit_system', $profile?->unit_system?->value ?? 'metric'),
        'heightCm' => old('height_cm', $profile?->height_cm),
        'weightKg' => old('weight_kg', $profile?->weight_kg),
        'timezone' => $profile?->timezone,
        'equipment' => old('equipment', $profile?->equipment ?? []),
        'allEquipment' => array_keys(config('ironpulse.equipment')),
    ];
    $toggle = fn (string $name, string $label, bool $checked, ?string $hint = null) => compact('name', 'label', 'checked', 'hint');
@endphp
<x-layouts.app title="Profile">
    <div class="mb-6">
        <p class="eyebrow">Your account</p>
        <h1 class="mt-2 text-2xl font-black uppercase sm:text-3xl">Profile and settings</h1>
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        {{-- Account --}}
        <section class="glass p-5 sm:p-6" aria-labelledby="acct-title">
            <h2 id="acct-title" class="text-base font-bold uppercase">Account</h2>
            @if ($user->is_guest)
                <p class="mt-2 rounded-xl border border-line-strong bg-neon/5 p-3 text-sm">Guest account. <a class="font-semibold text-neon underline underline-offset-4" href="{{ route('account.claim') }}">Save it with an email and password</a> to keep your data.</p>
            @endif
            <form method="POST" action="{{ route('profile.update') }}" class="mt-4 space-y-4">
                @csrf @method('PATCH')
                <x-field name="name" id="account-name" label="Name" :value="$user->name" required maxlength="120"/>
                @unless ($user->is_guest)
                    <x-field name="email" type="email" label="Email" :value="$user->email" required inputmode="email"/>
                @endunless
                <button class="btn btn-primary">Save account</button>
            </form>
            @unless ($user->is_guest)
                <hr class="my-6 border-line">
                <h3 class="text-sm font-bold uppercase">Change password</h3>
                <form method="POST" action="{{ route('password.update') }}" class="mt-3 space-y-4">
                    @csrf @method('PUT')
                    <x-field name="current_password" type="password" label="Current password" bag="updatePassword" autocomplete="current-password"/>
                    <x-field name="password" id="new-password" type="password" label="New password" bag="updatePassword" autocomplete="new-password"/>
                    <x-field name="password_confirmation" id="new-password-confirmation" type="password" label="Confirm new password" bag="updatePassword" autocomplete="new-password"/>
                    <button class="btn btn-ghost">Update password</button>
                </form>
            @endunless
        </section>

        {{-- Settings --}}
        <section class="glass p-5 sm:p-6" aria-labelledby="set-title">
            <h2 id="set-title" class="text-base font-bold uppercase">Preferences and reminders</h2>
            <form method="POST" action="{{ route('profile.settings') }}" class="mt-4 space-y-4">
                @csrf @method('PATCH')
                @foreach ([
                    ['sound_enabled', 'Sound cues in workouts', $profile->sound_enabled],
                    ['voice_coach', 'Voice coach (spoken prompts)', $profile->voice_coach],
                    ['leaderboard_opt_in', 'Show me on the leaderboard', $profile->leaderboard_opt_in],
                    ['water_reminders', 'Remind me to drink water', $notifications->water_reminders],
                    ['browser_notifications', 'Browser notifications while the app is open', $notifications->browser_notifications],
                    ['email_digest', 'Weekly email digest', $notifications->email_digest],
                ] as [$name, $label, $checked])
                    <input type="hidden" name="{{ $name }}" value="0">
                    <label class="flex items-center gap-3 text-sm"><input type="checkbox" class="check" name="{{ $name }}" value="1" @checked(old($name, $checked))> {{ $label }}</label>
                @endforeach
                @if (config('ironpulse.coach.enabled'))
                    <input type="hidden" name="ai_coach_opt_in" value="0">
                    <label class="flex items-center gap-3 text-sm"><input type="checkbox" class="check" name="ai_coach_opt_in" value="1" @checked(old('ai_coach_opt_in', $profile->ai_coach_opt_in))> Use the AI coach (sends your questions and basic stats to the AI service)</label>
                @endif
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field name="workout_reminder_at" type="time" label="Workout reminder" :value="$notifications->workout_reminder_at ? substr($notifications->workout_reminder_at, 0, 5) : null"/>
                    <x-field name="creatine_reminder_at" type="time" label="Creatine reminder" :value="$notifications->creatine_reminder_at ? substr($notifications->creatine_reminder_at, 0, 5) : null"/>
                    <x-field name="sleep_reminder_at" type="time" label="Bedtime reminder" :value="$notifications->sleep_reminder_at ? substr($notifications->sleep_reminder_at, 0, 5) : null"/>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field name="water_goal_ml" type="number" label="Water goal (ml)" :value="$profile->water_goal_ml" min="500" max="8000" step="50" hint="Blank = automatic"/>
                    <x-field name="sleep_goal_hours" type="number" label="Sleep goal (h)" :value="$profile->sleep_goal_hours" min="4" max="12" step="0.5"/>
                    <x-field name="creatine_dose_g" type="number" label="Creatine (g)" :value="$profile->creatine_dose_g" min="1" max="20" step="0.5"/>
                </div>
                <button class="btn btn-primary">Save settings</button>
            </form>
        </section>

        {{-- Body profile --}}
        <section class="glass p-5 sm:p-6 lg:col-span-2" aria-labelledby="body-title" x-data="bodyForm(@js($bodyConfig))">
            <h2 id="body-title" class="text-base font-bold uppercase">Body, goal and training</h2>
            <p class="mt-1 text-sm text-muted">Changing your weight or goal here updates your diet targets the next time you recalculate.</p>
            <form method="POST" action="{{ route('profile.body') }}" class="mt-5 space-y-8">
                @csrf @method('PATCH')
                <div class="grid gap-8 lg:grid-cols-2">
                    <div class="space-y-8">
                        @include('profile._fields', ['group' => 'identity', 'wizard' => false])
                        @include('profile._fields', ['group' => 'body', 'wizard' => false])
                    </div>
                    <div class="space-y-8">
                        @include('profile._fields', ['group' => 'goal', 'wizard' => false])
                        @include('profile._fields', ['group' => 'setup', 'wizard' => false])
                    </div>
                </div>
                <button class="btn btn-primary btn-lg">Save profile</button>
            </form>
        </section>

        {{-- Danger zone --}}
        <section class="glass border-danger/30 p-5 sm:p-6 lg:col-span-2" aria-labelledby="del-title" x-data="{ open: {{ $errors->userDeletion->isNotEmpty() ? 'true' : 'false' }} }">
            <h2 id="del-title" class="text-base font-bold uppercase text-[#ff8a8a]">Delete account</h2>
            <p class="mt-1 text-sm text-muted">Permanently removes your account and all your workouts, food logs, photos and measurements.</p>
            <button type="button" class="btn btn-danger mt-4" @click="open = !open" :aria-expanded="open.toString()">Delete my account…</button>
            <form method="POST" action="{{ route('profile.destroy') }}" class="mt-4 max-w-md space-y-4" x-show="open" x-cloak>
                @csrf @method('DELETE')
                @if ($user->is_guest)
                    <x-field name="confirm" label="Type DELETE to confirm" bag="userDeletion" autocomplete="off"/>
                @else
                    <x-field name="password" id="delete-password" type="password" label="Confirm your password" bag="userDeletion" autocomplete="current-password"/>
                @endif
                <button class="btn btn-danger">Permanently delete</button>
            </form>
        </section>
    </div>
</x-layouts.app>
