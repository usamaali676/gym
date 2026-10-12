<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppRelease;
use App\Models\AppVersionPolicy;
use App\Services\App\AppVersionService;
use App\Support\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Version policy and maintenance windows per platform, plus the release-notes history.
 * Publishing an app to the stores happens in App Store Connect / Play Console; this only tells installed apps what to do.
 */
class AppReleaseController extends Controller
{
    public function index(): View
    {
        return view('admin.releases.index', [
            'policies' => AppVersionPolicy::query()->orderBy('platform')->get(),
            'releases' => AppRelease::query()->latest('id')->limit(30)->get(),
        ]);
    }

    public function updatePolicy(Request $request, string $platform): RedirectResponse
    {
        abort_unless(in_array($platform, AppVersionPolicy::PLATFORMS, true), 404);
        $policy = AppVersionPolicy::forPlatform($platform) ?? abort(404);

        $version = ['nullable', 'string', 'max:20', fn ($a, $v, $fail) => $v === null || AppVersionService::normalise($v) !== null || $fail('Use a version like 1.4.0.')];
        $d = $request->validate([
            'min_supported' => ['required', 'string', 'max:20', $version[3]],
            'recommended' => $version,
            'latest' => $version,
            'store_url' => ['nullable', 'string', 'max:255', 'regex:#^https://\S+$#i'],
            'release_notes' => ['nullable', 'string', 'max:2000'],
            'maintenance_enabled' => ['sometimes', 'boolean'],
            'maintenance_message' => ['nullable', 'string', 'max:500'],
            'maintenance_ends_at' => ['nullable', 'date'],
        ]);

        $min = AppVersionService::normalise($d['min_supported']);
        foreach (['recommended', 'latest'] as $field) {
            if (! empty($d[$field]) && version_compare(AppVersionService::normalise($d[$field]), $min, '<')) {
                return back()->withInput()->withErrors([$field => 'This cannot be lower than the minimum supported version.']);
            }
        }

        $policy->fill($d);
        $policy->maintenance_enabled = $request->boolean('maintenance_enabled');
        $policy->updated_by = $request->user()->id;
        $policy->save();
        AdminAudit::record('app_policy.update', ['platform' => $platform, 'min' => $d['min_supported'], 'maintenance' => $policy->maintenance_enabled]);

        return back()->with('status', ucfirst($platform).' policy saved. Apps check it on launch and when they resume.');
    }

    public function storeRelease(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'platform' => ['required', Rule::in([...AppVersionPolicy::PLATFORMS, 'all'])],
            'version' => ['required', 'string', 'max:20', fn ($a, $v, $fail) => AppVersionService::normalise($v) !== null || $fail('Use a version like 1.4.0.')],
            'build_number' => ['nullable', 'string', 'max:20'],
            'notes' => ['required', 'string', 'max:2000'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $release = AppRelease::create($d + ['published_at' => $request->boolean('is_published') ? now() : null]);
        $release->forceFill(['is_published' => $request->boolean('is_published')])->save();
        AdminAudit::record('app_release.create', ['id' => $release->id]);

        return back()->with('status', 'Release notes saved.');
    }

    public function toggleRelease(AppRelease $release): RedirectResponse
    {
        $release->forceFill(['is_published' => ! $release->is_published, 'published_at' => $release->is_published ? null : now()])->save();

        return back()->with('status', $release->is_published ? 'Release notes published.' : 'Release notes hidden.');
    }

    public function destroyRelease(AppRelease $release): RedirectResponse
    {
        $release->delete();

        return back()->with('status', 'Release notes deleted.');
    }
}
