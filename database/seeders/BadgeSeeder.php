<?php

namespace Database\Seeders;

use App\Models\Badge;
use App\Services\BadgeMetrics;
use Illuminate\Database\Seeder;
use RuntimeException;

/** Seeds the badge catalogue (database/seeders/data/badges.json). Updates by slug, so editing the file and re-seeding is safe. */
class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode((string) file_get_contents(database_path('seeders/data/badges.json')), true, 512, JSON_THROW_ON_ERROR);
        $known = BadgeMetrics::names();

        foreach ($data['badges'] as $row) {
            if (! in_array($row['criteria']['metric'] ?? null, $known, true)) {
                throw new RuntimeException("Badge {$row['slug']} uses an unknown metric \"".($row['criteria']['metric'] ?? '').'".');
            }
            Badge::updateOrCreate(['slug' => $row['slug']], $row + ['is_active' => true]);
        }
    }
}
