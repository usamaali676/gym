<?php

namespace App\Services;

use App\Models\Measurement;
use App\Models\NotificationSetting;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProfileService
{
    /**
     * Save the body/goal profile (used by the onboarding wizard and the profile page).
     *
     * @param  array<string, mixed>  $data  validated BodyProfileRequest data
     */
    public function save(User $user, array $data, bool $completeOnboarding = false): Profile
    {
        return DB::transaction(function () use ($user, $data, $completeOnboarding) {
            $profile = $user->profile ?: new Profile(['user_id' => $user->id]);
            $profile->user_id = $user->id;

            $trainingDays = collect($data['training_days'] ?? $profile->trainingDays())
                ->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();

            $profile->fill([
                'age' => $data['age'],
                'sex' => $data['sex'],
                'height_cm' => $data['height_cm'],
                'weight_kg' => $data['weight_kg'],
                'activity_level' => $data['activity_level'],
                'fitness_level' => $data['fitness_level'],
                'goal' => $data['goal'],
                'diet_preference' => $data['diet_preference'],
                'equipment' => array_values($data['equipment']),
                'training_days' => $trainingDays,
                'days_per_week' => count($trainingDays),
                'unit_system' => $data['unit_system'] ?? $profile->unit_system?->value ?? 'metric',
                'timezone' => $data['timezone'] ?? $profile->timezone ?? 'UTC',
                'public_name' => $data['public_name'] ?? $profile->public_name,
            ]);

            $profile->plan_id ??= Plan::default()?->id;
            $profile->onboarding_step = 4;
            $profile->save();

            $user->name = $data['name'];
            if ($completeOnboarding && ! $user->onboarded_at) {
                $user->onboarded_at = now();
            }
            $user->save();
            $user->setRelation('profile', $profile);

            $profile->plan_started_on ??= $user->localToday()->toDateString();
            $profile->save();

            NotificationSetting::firstOrCreate(['user_id' => $user->id]);

            // Keep a body-weight data point so the progress chart starts on day one.
            Measurement::updateOrCreate(
                ['user_id' => $user->id, 'measured_on' => $user->localToday()->toDateString()],
                ['weight_kg' => $data['weight_kg']],
            );

            return $profile;
        });
    }
}
