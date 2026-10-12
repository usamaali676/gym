<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\ExerciseMedia;
use App\Support\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Demonstration videos and images for an exercise, delivered to the apps from the private media disk.
 * Uploads are validated by their real content type, renamed, and never executed or served from the web root.
 */
class ExerciseMediaController extends Controller
{
    private const EXTENSIONS = [
        'video/mp4' => 'mp4', 'video/webm' => 'webm',
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    ];

    public function store(Request $request, Exercise $exercise): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(ExerciseMedia::KINDS)],
            'view' => ['nullable', Rule::in(ExerciseMedia::VIEWS)],
            'file' => ['required', 'file', 'mimetypes:'.implode(',', array_keys(self::EXTENSIONS)), 'max:61440'], // 60 MB
        ]);

        $file = $request->file('file');
        $mime = $file->getMimeType();
        $isVideo = str_starts_with($mime, 'video/');

        if (in_array($data['kind'], ['demo_video', 'instruction_video'], true) !== $isVideo) {
            return back()->withErrors(['file' => $isVideo ? 'A video can only be used as a demo or instruction video.' : 'This type needs an image (JPEG, PNG or WebP).']);
        }

        $view = $data['view'] ?? null;
        $path = sprintf('exercises/%s/%s-%s.%s', $exercise->slug, $data['kind'].($view ? '-'.$view : ''), Str::lower(Str::random(12)), self::EXTENSIONS[$mime]);
        Storage::disk('media')->put($path, $file->get());
        [$width, $height] = $isVideo ? [null, null] : (@getimagesize($file->getRealPath()) ?: [null, null]);

        // One clip per kind and camera view: uploading again replaces it (the old file is deleted with its row).
        ExerciseMedia::query()->where('exercise_id', $exercise->id)->where('kind', $data['kind'])->where('view', $view)->get()->each->delete();

        $media = ExerciseMedia::create([
            'exercise_id' => $exercise->id, 'kind' => $data['kind'], 'view' => $view,
            'disk' => 'media', 'path' => $path, 'mime' => $mime, 'bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()), 'width' => $width, 'height' => $height,
            'status' => 'published',
        ]);
        AdminAudit::record('exercise.media.add', ['exercise_id' => $exercise->id, 'media_id' => $media->id, 'kind' => $media->kind]);

        return back()->with('status', 'Media uploaded. Apps download it the next time they sync.');
    }

    public function update(Request $request, ExerciseMedia $media): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['draft', 'published'])]]);
        $media->update($data);
        AdminAudit::record('exercise.media.update', ['media_id' => $media->id, 'status' => $data['status']]);

        return back()->with('status', $data['status'] === 'published' ? 'Media published.' : 'Media hidden from the apps.');
    }

    public function destroy(ExerciseMedia $media): RedirectResponse
    {
        AdminAudit::record('exercise.media.remove', ['media_id' => $media->id, 'exercise_id' => $media->exercise_id]);
        $media->delete();

        return back()->with('status', 'Media removed.');
    }
}
