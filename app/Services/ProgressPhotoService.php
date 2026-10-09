<?php

namespace App\Services;

use App\Models\ProgressPhoto;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Progress photos live on the private disk and are only ever served to their owner through the app.
 * Every upload is decoded and re-encoded as a JPEG, which strips EXIF (GPS and camera data), neutralises
 * anything unusual hiding in the file, and keeps storage small.
 */
class ProgressPhotoService
{
    public const DISK = 'local';

    public const MAX_PER_USER = 120;

    private const MAX_EDGE = 1600;

    public function store(User $user, UploadedFile $file, string $pose, CarbonInterface $takenOn, ?string $notes): ProgressPhoto
    {
        if (ProgressPhoto::where('user_id', $user->id)->count() >= self::MAX_PER_USER) {
            throw ValidationException::withMessages(['photo' => 'You have reached the limit of '.self::MAX_PER_USER.' photos. Delete some to add more.']);
        }

        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $image) {
            throw ValidationException::withMessages(['photo' => 'That file could not be read as an image.']);
        }

        $image = $this->orient($image, $file->getRealPath());
        [$w, $h] = [imagesx($image), imagesy($image)];
        $scale = min(1, self::MAX_EDGE / max($w, $h));
        if ($scale < 1) {
            $resized = imagescale($image, (int) round($w * $scale), (int) round($h * $scale));
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        imagejpeg($image, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = "progress/{$user->id}/".Str::uuid().'.jpg';
        Storage::disk(self::DISK)->put($path, $bytes);

        return ProgressPhoto::create([
            'user_id' => $user->id,
            'taken_on' => $takenOn->toDateString(),
            'pose' => $pose,
            'path' => $path,
            'mime' => 'image/jpeg',
            'size_bytes' => strlen($bytes),
            'notes' => $notes,
        ]);
    }

    public function delete(ProgressPhoto $photo): void
    {
        Storage::disk(self::DISK)->delete($photo->path);
        $photo->delete();
    }

    /** Honour the EXIF orientation before the metadata is thrown away, so portrait phone photos are not stored sideways. */
    private function orient(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $rotation = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($rotation === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $rotation, 0);
        imagedestroy($image);

        return $rotated ?: $image;
    }
}
