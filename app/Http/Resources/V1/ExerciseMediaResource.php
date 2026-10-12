<?php

namespace App\Http\Resources\V1;

use App\Models\ExerciseMedia;
use App\Support\MediaUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExerciseMedia */
class ExerciseMediaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'view' => $this->view,
            'url' => MediaUrls::exercise($this->resource),
            // Cache the file under id + checksum, not under the (expiring) URL.
            'checksum' => $this->checksum,
            'mime' => $this->mime,
            'bytes' => $this->bytes,
            'width' => $this->width,
            'height' => $this->height,
            'duration_ms' => $this->duration_ms,
        ];
    }
}
