<?php

namespace Database\Factories;

use App\Models\PostureSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PostureSession> */
class PostureSessionFactory extends Factory
{
    protected $model = PostureSession::class;

    public function definition(): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'rule_version' => 1,
            'camera_view' => 'side',
            'started_at' => now()->subHour(),
            'duration_seconds' => 40,
            'total_reps' => 8,
            'good_reps' => 7,
            'bad_reps' => 1,
            'hold_seconds' => null,
            'form_score_avg' => 86,
            'form_score_min' => 70,
            'error_counts' => [],
            'rep_log' => [],
            'avg_fps' => 28.5,
            'model_variant' => 'lite',
        ];
    }
}
