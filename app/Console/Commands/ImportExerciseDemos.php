<?php

namespace App\Console\Commands;

use App\Models\Exercise;
use App\Models\ExerciseMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Registers the clips rendered by scripts/render-exercise-demos.mjs as exercise media, so the apps can play them.
 * Safe to run again: a clip whose file is unchanged is left alone (no new download for any phone), a changed one
 * replaces the old file and row. Nothing is trusted from the index: size and SHA-256 are re-checked against the file.
 */
class ImportExerciseDemos extends Command
{
    protected $signature = 'exercises:import-demos {dir : The folder written by render-exercise-demos.mjs (has index.json)} {--dry-run : Check and report, change nothing}';

    protected $description = 'Register rendered demo clips (MP4 + poster) as exercise media';

    public function handle(): int
    {
        $dir = rtrim((string) $this->argument('dir'), '/\\');
        $indexFile = $dir.'/index.json';
        if (! is_file($indexFile)) {
            $this->error("No index.json in {$dir}. Run scripts/render-exercise-demos.mjs first.");

            return self::FAILURE;
        }

        $index = json_decode(File::get($indexFile), true);
        if (! is_array($index) || ! is_array($index['clips'] ?? null)) {
            $this->error('index.json is not a list of clips.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $added = $unchanged = $problems = 0;

        foreach ($index['clips'] as $clip) {
            $slug = (string) ($clip['slug'] ?? '');
            $view = (string) ($clip['view'] ?? '');
            $exercise = Exercise::query()->where('slug', $slug)->first();
            if (! $exercise || ! in_array($view, ExerciseMedia::VIEWS, true)) {
                $this->warn("skip {$slug}/{$view}: ".($exercise ? 'unknown view' : 'no such exercise'));
                $problems++;

                continue;
            }

            foreach ([['demo_video', 'video', 'sha256', 'bytes', 'video/mp4', 'mp4'], ['thumbnail', 'poster', 'poster_sha256', 'poster_bytes', 'image/jpeg', 'jpg']] as [$kind, $field, $shaField, $bytesField, $mime, $ext]) {
                $name = basename((string) ($clip[$field] ?? ''));
                $file = $dir.'/'.$name;
                if ($name === '' || ! is_file($file)) {
                    $this->warn("skip {$slug}/{$view} {$kind}: file missing");
                    $problems++;

                    continue;
                }
                $sha = hash_file('sha256', $file);
                if ($sha !== ($clip[$shaField] ?? null) || filesize($file) !== (int) ($clip[$bytesField] ?? -1)) {
                    $this->error("skip {$slug}/{$view} {$kind}: the file does not match the index (corrupt or edited)");
                    $problems++;

                    continue;
                }

                $current = ExerciseMedia::query()->where('exercise_id', $exercise->id)->where('kind', $kind)->where('view', $view)->get();
                if ($current->count() === 1 && $current->first()->checksum === $sha) {
                    $unchanged++;

                    continue;
                }
                if ($dry) {
                    $this->line("would add {$slug} {$kind} ({$view}), ".number_format(filesize($file) / 1024, 0).' KB');
                    $added++;

                    continue;
                }

                $path = sprintf('exercises/%s/%s-%s-%s.%s', $exercise->slug, $kind, $view, Str::lower(Str::random(12)), $ext);
                Storage::disk('media')->put($path, File::get($file));
                $current->each->delete(); // one clip per kind and view; the old file goes with its row
                ExerciseMedia::create([
                    'exercise_id' => $exercise->id, 'kind' => $kind, 'view' => $view,
                    'disk' => 'media', 'path' => $path, 'mime' => $mime, 'bytes' => filesize($file), 'checksum' => $sha,
                    'width' => $clip['width'] ?? null, 'height' => $clip['height'] ?? null,
                    'duration_ms' => $kind === 'demo_video' ? ($clip['duration_ms'] ?? null) : null,
                    'status' => 'published',
                ]);
                $added++;
            }
        }

        $this->info(($dry ? 'Would add' : 'Added').": {$added}, unchanged: {$unchanged}, problems: {$problems}");

        return $problems > 0 ? self::FAILURE : self::SUCCESS;
    }
}
