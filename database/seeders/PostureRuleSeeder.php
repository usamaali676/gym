<?php

namespace Database\Seeders;

use App\Models\Exercise;
use App\Models\PostureRule;
use App\Services\PostureRuleValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads one posture rule per exercise from database/seeders/data/posture-rules/*.json.
 * Every config is validated first: a broken rule stops the seed instead of reaching the browser.
 * Re-running is safe; rules are keyed by (exercise, version) and a rule an admin has edited
 * (updated_by is set) is left alone.
 */
class PostureRuleSeeder extends Seeder
{
    public function run(PostureRuleValidator $validator): void
    {
        $files = glob(database_path('seeders/data/posture-rules/*.json')) ?: [];
        if ($files === []) {
            throw new RuntimeException('No posture rule files found in database/seeders/data/posture-rules.');
        }

        $exercises = Exercise::query()->pluck('id', 'slug');

        DB::transaction(function () use ($files, $validator, $exercises) {
            foreach ($files as $file) {
                $name = basename($file);
                $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

                $exerciseId = $exercises[$data['exercise'] ?? ''] ?? null;
                if (! $exerciseId) {
                    throw new RuntimeException("{$name}: unknown exercise \"".($data['exercise'] ?? '').'". Seed the plan and exercise library first.');
                }

                $errors = $validator->validate($data['config'] ?? null);
                if ($errors !== []) {
                    throw new RuntimeException("{$name}: ".implode('; ', $errors));
                }

                $version = (int) ($data['version'] ?? 1);
                $existing = PostureRule::where(['exercise_id' => $exerciseId, 'version' => $version])->first();
                if ($existing?->updated_by !== null) {
                    continue; // edited in the admin panel: keep their version
                }

                PostureRule::updateOrCreate(
                    ['exercise_id' => $exerciseId, 'version' => $version],
                    [
                        'tier' => $data['tier'],
                        'camera_view' => $data['camera_view'],
                        'config' => $data['config'],
                        'guidance' => $data['guidance'] ?? null,
                        'is_active' => true,
                    ],
                );
            }
        });
    }
}
