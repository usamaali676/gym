<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\ExerciseMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams media behind signed, expiring URLs (the `signed` middleware has already checked the signature).
 * Local files go out as BinaryFileResponse, which honours HTTP Range requests: iOS AVPlayer and Android
 * ExoPlayer need them to seek in a video.
 */
class MediaController extends Controller
{
    public function exercise(int $media): BinaryFileResponse|RedirectResponse
    {
        $row = ExerciseMedia::query()->with('exercise:id,status')->find($media);

        if (! $row || $row->status !== 'published' || $row->exercise?->status !== 'published') {
            throw ApiException::notFound('Media not found.');
        }

        return $this->send($row->disk, $row->path, $row->mime, $row->checksum);
    }

    public function banner(int $banner): BinaryFileResponse|RedirectResponse
    {
        $row = Banner::query()->published()->find($banner);

        if (! $row || ! $row->image_path) {
            throw ApiException::notFound('Image not found.');
        }

        return $this->send('media', $row->image_path, $row->image_mime ?? 'image/jpeg', $row->image_checksum);
    }

    private function send(string $diskName, string $path, string $mime, ?string $checksum): BinaryFileResponse|RedirectResponse
    {
        $disk = Storage::disk($diskName);
        if (! $disk->exists($path)) {
            throw ApiException::notFound('File not found.');
        }

        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=86400, immutable',
            'X-Content-Type-Options' => 'nosniff',
            ...($checksum ? ['ETag' => '"'.$checksum.'"'] : []),
        ];

        // Object storage: hand off to a short-lived provider URL instead of proxying bytes through PHP.
        if (config("filesystems.disks.{$diskName}.driver") === 's3') {
            return redirect()->away($disk->temporaryUrl($path, now()->addMinutes(10)));
        }

        return response()->file($disk->path($path), $headers);
    }
}
