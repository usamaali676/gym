<?php

namespace Database\Seeders;

use App\Models\Exercise;
use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\PlanDayExercise;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the workout plan from database/seeders/data/plan.json (the PDF is the source of truth).
 * Idempotent: re-running updates rows in place and never duplicates them.
 */
class PlanSeeder extends Seeder
{
    public const PLAN_SLUG = 'five-day-push-pull-legs';

    public function run(): void
    {
        $data = json_decode(file_get_contents(database_path('seeders/data/plan.json')), true, 512, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($data) {
            $plan = Plan::updateOrCreate(['slug' => self::PLAN_SLUG], [
                'name' => '5-Day Push / Pull / Legs',
                'source' => $data['meta']['source'],
                'version' => $data['meta']['version'],
                'is_default' => true,
                'rules' => $data['rules'],
            ]);

            Plan::where('id', '!=', $plan->id)->update(['is_default' => false]);

            $days = [];
            foreach ($data['plan_days'] as $row) {
                $days[$row['day_of_week']] = PlanDay::updateOrCreate(
                    ['plan_id' => $plan->id, 'day_of_week' => $row['day_of_week']],
                    [
                        'slug' => $row['slug'],
                        'name' => $row['name'],
                        'session_type' => $row['session_type'],
                        'label' => $row['label'],
                        'focus' => $row['focus'],
                        'is_rest' => $row['is_rest'],
                        'recovery_note' => $row['is_rest'] ? $data['rules']['sleep']['note'] : null,
                    ],
                );
            }

            $exercises = [];
            foreach ($data['exercises'] as $row) {
                $rest = $data['rest_defaults'][$row['mechanic']];
                $exercises[$row['slug']] = Exercise::updateOrCreate(['slug' => $row['slug']], [
                    'name' => $row['name'],
                    'kind' => 'plan',
                    'muscle_group' => $row['muscle_group'],
                    'mechanic' => $row['mechanic'],
                    'equipment' => $row['equipment'],
                    'difficulty' => $row['difficulty'],
                    'rest_min_seconds' => $rest['min_seconds'],
                    'rest_max_seconds' => $rest['max_seconds'],
                    'default_rest_seconds' => $rest['default_seconds'],
                    'demo_key' => $row['slug'],
                    'is_active' => true,
                ]);
            }

            foreach ($data['plan_day_exercises'] as $row) {
                PlanDayExercise::updateOrCreate(
                    ['plan_day_id' => $days[$row['day']]->id, 'exercise_id' => $exercises[$row['exercise']]->id],
                    [
                        'position' => $row['position'],
                        'sets' => $row['sets'],
                        'reps_min' => $row['reps_min'],
                        'reps_max' => $row['reps_max'],
                        'focus' => $row['focus'],
                        'label_override' => $row['label'] ?? null,
                    ],
                );
            }
        });
    }
}
