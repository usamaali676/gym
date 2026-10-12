<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\NotificationSetting;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Development accounts. The password comes from SEED_PASSWORD (default "password");
 * set it explicitly before seeding anything that is reachable from the internet.
 *
 * - Mujtaba Ahssan: the profile from the plan PDF. Sex, activity, fitness level, diet preference and
 *   equipment are not in the PDF, so the onboarding wizard opens pre-filled and asks for them.
 * - Admin: fully onboarded, can open the admin panel.
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('SEED_PASSWORD', 'password');
        $plan = Plan::default();
        $defaults = json_decode(file_get_contents(database_path('seeders/data/plan.json')), true, 512, JSON_THROW_ON_ERROR)['default_profile'];

        $user = User::firstOrNew(['email' => 'mujtaba@example.com']);
        $user->fill(['name' => $defaults['name'], 'password' => $password])->save();

        Profile::updateOrCreate(['user_id' => $user->id], [
            'plan_id' => $plan?->id,
            'age' => $defaults['age'],
            'height_cm' => $defaults['height_cm'],
            'weight_kg' => $defaults['weight_kg'],
            'goal' => $defaults['goal'],
            'days_per_week' => $defaults['days_per_week'],
            'training_days' => $defaults['training_days'],
            'plan_started_on' => now()->startOfWeek()->toDateString(),
        ]);

        $admin = User::firstOrNew(['email' => 'admin@example.com']);
        $admin->fill(['name' => 'Admin', 'password' => $password]);
        $admin->role = Role::Admin;
        $admin->onboarded_at ??= now();
        $admin->save();

        Profile::updateOrCreate(['user_id' => $admin->id], [
            'plan_id' => $plan?->id,
            'age' => 30,
            'sex' => 'unspecified',
            'height_cm' => 175,
            'weight_kg' => 75,
            'activity_level' => 'moderate',
            'fitness_level' => 'intermediate',
            'goal' => 'maintenance',
            'diet_preference' => 'nonveg',
            'equipment' => array_keys(config('ironpulse.equipment')),
            'days_per_week' => 5,
            'training_days' => [1, 2, 3, 4, 5],
            'plan_started_on' => now()->startOfWeek()->toDateString(),
        ]);

        foreach ([$user, $admin] as $account) {
            NotificationSetting::firstOrCreate(['user_id' => $account->id]);
        }
    }
}
