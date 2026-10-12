<?php

namespace App\Http\Resources\V1;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Announcement */
class AnnouncementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'severity' => $this->severity,
            'pinned' => (bool) $this->pinned,
            'cta' => $this->cta_url ? ['label' => $this->cta_label, 'url' => $this->cta_url] : null,
            // Apps hide an announcement outside its window even if their cache still holds it.
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
