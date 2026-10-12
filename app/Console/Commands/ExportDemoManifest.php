<?php

namespace App\Console\Commands;

use App\Models\Exercise;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportDemoManifest extends Command
{
    protected $signature = 'exercises:demo-manifest {--out=storage/app/demo-manifest.json : Where to write the list}';

    protected $description = 'List the published exercises (slug, demo key, muscle regions) for scripts/render-exercise-demos.mjs';

    public function handle(): int
    {
        $items = Exercise::query()->published()->orderBy('id')->get()->map(fn (Exercise $e) => [
            'slug' => $e->slug,
            'demo_key' => $e->demo_key ?: $e->slug,
            'regions' => $e->target_regions ?? ['primary' => [], 'secondary' => []],
        ])->values();

        $out = base_path((string) $this->option('out'));
        File::ensureDirectoryExists(dirname($out));
        File::put($out, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info("{$items->count()} exercises written to {$this->option('out')}");

        return self::SUCCESS;
    }
}
