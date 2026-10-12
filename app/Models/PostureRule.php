<?php

namespace App\Models;

use App\Enums\CameraView;
use App\Enums\PostureTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostureRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tier' => PostureTier::class,
            'camera_view' => CameraView::class,
            'config' => 'array',
            'guidance' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
