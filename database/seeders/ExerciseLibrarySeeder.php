<?php

namespace Database\Seeders;

use App\Models\Exercise;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Adds form content to the 31 plan exercises and seeds the extra library
 * (swap alternatives, warm-up helpers, mobility moves). Run after PlanSeeder.
 */
class ExerciseLibrarySeeder extends Seeder
{
    public function run(): void
    {
        $plan = $this->json('plan.json');
        $content = $this->json('exercise-content.json')['plan'];
        $library = $this->json('exercise-library.json')['exercises'];
        $rest = $plan['rest_defaults'];

        DB::transaction(function () use ($content, $library, $rest) {
            foreach ($content as $slug => $c) {
                Exercise::where('slug', $slug)->update($this->contentColumns($c));
            }

            foreach ($library as $e) {
                $isMobility = $e['kind'] === 'mobility';
                $defaults = $isMobility
                    ? ['min_seconds' => 0, 'max_seconds' => 0, 'default_seconds' => 0]
                    : $rest[$e['mechanic']];

                Exercise::updateOrCreate(['slug' => $e['slug']], array_merge([
                    'name' => $e['name'],
                    'kind' => $e['kind'],
                    'muscle_group' => $e['muscle_group'],
                    'mechanic' => $e['mechanic'],
                    'equipment' => $e['equipment'],
                    'difficulty' => $e['difficulty'],
                    'is_timed' => $e['is_timed'] ?? false,
                    'mobility_type' => $e['mobility_type'] ?? null,
                    'default_hold_seconds' => $e['hold_seconds'] ?? null,
                    'per_side' => $e['per_side'] ?? false,
                    'rest_min_seconds' => $defaults['min_seconds'],
                    'rest_max_seconds' => $defaults['max_seconds'],
                    'default_rest_seconds' => $defaults['default_seconds'],
                    'demo_key' => $e['slug'],
                    'is_active' => true,
                ], $this->contentColumns($e)));
            }
        });
    }

    /** @return array<string, mixed> */
    private function contentColumns(array $c): array
    {
        return [
            'description' => $c['description'],
            'form_cues' => $c['cues'],
            'common_mistakes' => $c['mistakes'],
            'breathing' => $c['breathing'],
            'target_regions' => $c['regions'],
        ];
    }

    private function json(string $file): array
    {
        return json_decode(file_get_contents(database_path('seeders/data/'.$file)), true, 512, JSON_THROW_ON_ERROR);
    }
}
