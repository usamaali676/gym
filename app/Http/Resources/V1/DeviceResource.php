<?php

namespace App\Http\Resources\V1;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Device */
class DeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currentTokenId = $request->user()?->currentAccessToken()?->getKey();

        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'name' => $this->name,
            'app_version' => $this->app_version,
            'os_version' => $this->os_version,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'signed_in' => $this->personal_access_token_id !== null,
            'is_current' => $currentTokenId !== null && $this->personal_access_token_id === $currentTokenId,
            'push_enabled' => $this->push_token_hash !== null,
        ];
    }
}
