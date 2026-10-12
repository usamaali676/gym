<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AnnouncementResource;
use App\Http\Resources\V1\BannerResource;
use App\Http\Resources\V1\HelpArticleResource;
use App\Models\Announcement;
use App\Models\Banner;
use App\Models\HelpArticle;
use App\Services\Membership\EntitlementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Announcements, banners and help articles an admin has published for this member. */
class PublicContentController extends Controller
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function announcements(Request $request): AnonymousResourceCollection
    {
        $slug = $this->entitlements->forRequest($request)->plan?->slug;

        return AnnouncementResource::collection(
            Announcement::query()->visibleTo($slug)->orderByDesc('pinned')->orderByDesc('published_at')->limit(50)->get()
        );
    }

    public function banners(Request $request): AnonymousResourceCollection
    {
        $request->validate(['placement' => ['nullable', 'in:'.implode(',', Banner::PLACEMENTS)]]);
        $slug = $this->entitlements->forRequest($request)->plan?->slug;

        return BannerResource::collection(
            Banner::query()->visibleTo($slug)->when($request->query('placement'), fn ($q, $p) => $q->where('placement', $p))
                ->orderBy('sort_order')->orderByDesc('published_at')->limit(20)->get()
        );
    }

    public function help(): AnonymousResourceCollection
    {
        return HelpArticleResource::collection(HelpArticle::query()->published()->orderBy('category')->orderBy('sort_order')->orderBy('title')->get());
    }

    public function helpArticle(string $slug): HelpArticleResource
    {
        return new HelpArticleResource(HelpArticle::query()->published()->where('slug', $slug)->first() ?? throw ApiException::notFound('Article not found.'));
    }
}
