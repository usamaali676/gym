<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\MembershipPlan;
use App\Support\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Messages shown in the apps' inbox / banner area. Draft until published; optional schedule and plan audience. */
class AnnouncementController extends Controller
{
    public const CTA_URL = 'regex:#^(https://|ironpulse://)\S+$#i';

    public function index(): View
    {
        return view('admin.content.announcements.index', ['items' => Announcement::query()->orderByDesc('pinned')->latest('id')->paginate(30)]);
    }

    public function create(): View
    {
        return view('admin.content.announcements.form', ['item' => new Announcement(['severity' => 'info', 'status' => 'draft']), 'plans' => $this->plans()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $item = Announcement::create($this->data($request));
        AdminAudit::record('announcement.create', ['id' => $item->id]);

        return redirect()->route('admin.announcements.edit', $item)->with('status', 'Announcement saved.');
    }

    public function edit(Announcement $announcement): View
    {
        return view('admin.content.announcements.form', ['item' => $announcement, 'plans' => $this->plans()]);
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($this->data($request));
        AdminAudit::record('announcement.update', ['id' => $announcement->id]);

        return redirect()->route('admin.announcements.edit', $announcement)->with('status', 'Announcement saved. Apps pick it up on their next refresh.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        AdminAudit::record('announcement.delete', ['id' => $announcement->id]);
        $announcement->delete();

        return redirect()->route('admin.announcements.index')->with('status', 'Announcement deleted.');
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'severity' => ['required', Rule::in(Announcement::SEVERITIES)],
            'cta_label' => ['nullable', 'string', 'max:40', 'required_with:cta_url'],
            'cta_url' => ['nullable', 'string', 'max:255', self::CTA_URL],
            'audience' => ['nullable', 'array'],
            'audience.*' => ['string', Rule::exists('membership_plans', 'slug')],
            'pinned' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
        ]);

        $d['audience'] = ! empty($d['audience']) ? array_values($d['audience']) : null;
        $d['pinned'] = $request->boolean('pinned');

        return $d;
    }

    private function plans()
    {
        return MembershipPlan::query()->orderBy('sort_order')->get(['slug', 'name']);
    }
}
