<?php

namespace App\Http\Resources\V1;

use App\Models\Banner;
use App\Support\MediaUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Banner */
class BannerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'placement' => $this->placement,
            'image' => $this->image_path ? ['url' => MediaUrls::banner($this->resource), 'checksum' => $this->image_checksum, 'mime' => $this->image_mime] : null,
            'cta' => $this->cta_url ? ['label' => $this->cta_label, 'url' => $this->cta_url] : null,
            'sort_order' => $this->sort_order,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
