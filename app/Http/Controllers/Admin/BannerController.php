<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\MembershipPlan;
use App\Support\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BannerController extends Controller
{
    public function index(): View
    {
        return view('admin.content.banners.index', ['items' => Banner::query()->orderBy('placement')->orderBy('sort_order')->latest('id')->paginate(30)]);
    }

    public function create(): View
    {
        return view('admin.content.banners.form', ['item' => new Banner(['placement' => 'home', 'status' => 'draft']), 'plans' => $this->plans()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $item = new Banner;
        $this->save($item, $request);
        AdminAudit::record('banner.create', ['id' => $item->id]);

        return redirect()->route('admin.banners.edit', $item)->with('status', 'Banner saved.');
    }

    public function edit(Banner $banner): View
    {
        return view('admin.content.banners.form', ['item' => $banner, 'plans' => $this->plans()]);
    }

    public function update(Request $request, Banner $banner): RedirectResponse
    {
        $this->save($banner, $request);
        AdminAudit::record('banner.update', ['id' => $banner->id]);

        return redirect()->route('admin.banners.edit', $banner)->with('status', 'Banner saved. Apps pick it up on their next refresh.');
    }

    public function destroy(Banner $banner): RedirectResponse
    {
        AdminAudit::record('banner.delete', ['id' => $banner->id]);
        $banner->delete();

        return redirect()->route('admin.banners.index')->with('status', 'Banner deleted.');
    }

    private function save(Banner $banner, Request $request): void
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'subtitle' => ['nullable', 'string', 'max:160'],
            'placement' => ['required', Rule::in(Banner::PLACEMENTS)],
            'cta_label' => ['nullable', 'string', 'max:40', 'required_with:cta_url'],
            'cta_url' => ['nullable', 'string', 'max:255', AnnouncementController::CTA_URL],
            'audience' => ['nullable', 'array'],
            'audience.*' => ['string', Rule::exists('membership_plans', 'slug')],
            'sort_order' => ['nullable', 'integer', 'between:0,999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'image' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:3072'],
            'remove_image' => ['sometimes', 'boolean'],
        ]);

        $d['audience'] = ! empty($d['audience']) ? array_values($d['audience']) : null;
        $d['sort_order'] = $d['sort_order'] ?? 0;
        unset($d['image'], $d['remove_image']);

        $banner->fill($d);

        if ($request->boolean('remove_image') && $banner->image_path) {
            Storage::disk('media')->delete($banner->image_path);
            $banner->forceFill(['image_path' => null, 'image_mime' => null, 'image_checksum' => null]);
        }

        if ($file = $request->file('image')) {
            $old = $banner->image_path;
            $this->attachImage($banner, $file);
            if ($old) {
                Storage::disk('media')->delete($old);
            }
        }

        $banner->save();
    }

    /** Stored under a random name with an extension derived from the verified type, never from the upload's name. */
    private function attachImage(Banner $banner, UploadedFile $file): void
    {
        $mime = $file->getMimeType();
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? abort(422, 'Unsupported image type.');
        $path = 'banners/'.Str::random(40).'.'.$ext;

        Storage::disk('media')->put($path, $file->get());
        $banner->forceFill(['image_path' => $path, 'image_mime' => $mime, 'image_checksum' => hash_file('sha256', $file->getRealPath())]);
    }

    private function plans()
    {
        return MembershipPlan::query()->orderBy('sort_order')->get(['slug', 'name']);
    }
}
