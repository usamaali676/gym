<?php

namespace App\Support;

use App\Models\Banner;
use App\Models\ExerciseMedia;
use Illuminate\Support\Facades\URL;

/**
 * Media lives on a private disk. The API hands out signed, expiring URLs, so video players and image caches need
 * no auth header and a leaked link stops working. Access rules (premium content) are applied when the URL is issued.
 */
final class MediaUrls
{
    public const TTL_HOURS = 6;

    public static function exercise(ExerciseMedia $media): string
    {
        return URL::temporarySignedRoute('v1.media.exercise', now()->addHours(self::TTL_HOURS), ['media' => $media->id]);
    }

    public static function banner(Banner $banner): ?string
    {
        return $banner->image_path
            ? URL::temporarySignedRoute('v1.media.banner', now()->addHours(self::TTL_HOURS), ['banner' => $banner->id])
            : null;
    }
}
