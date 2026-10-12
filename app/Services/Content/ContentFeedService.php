<?php

namespace App\Services\Content;

use App\Http\Resources\V1\AnnouncementResource;
use App\Http\Resources\V1\BadgeResource;
use App\Http\Resources\V1\BannerResource;
use App\Http\Resources\V1\ExerciseResource;
use App\Http\Resources\V1\FoodResource;
use App\Http\Resources\V1\HelpArticleResource;
use App\Http\Resources\V1\MembershipPlanResource;
use App\Http\Resources\V1\ProgramDetailResource;
use App\Models\Announcement;
use App\Models\Badge;
use App\Models\Banner;
use App\Models\ContentChange;
use App\Models\Exercise;
use App\Models\Food;
use App\Models\HelpArticle;
use App\Models\MembershipPlan;
use App\Models\Plan;
use App\Services\Membership\EntitlementService;
use App\Support\Content\ContentCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * What the mobile apps are allowed to see of each content collection, rendered for the requesting member, plus the
 * change feed that lets them stay in sync without re-downloading everything.
 *
 * Sync protocol (see docs/mobile/openapi.yaml):
 *   1. GET /content/manifest             -> cursor C0 (read BEFORE downloading, so nothing is missed)
 *   2. GET /content/{collection}?page=n  -> full snapshot, page by page
 *   3. GET /content/changes?after=C0     -> everything that changed meanwhile; repeat with the returned cursor
 */
class ContentFeedService
{
    public const PAGE_LIMIT = 200;

    public function __construct(private readonly EntitlementService $entitlements) {}

    /** The rows a member may see. Everything else behaves as if it did not exist (and appears as a tombstone). */
    public function query(ContentCollection $collection, Request $request): Builder
    {
        $planSlug = $this->entitlements->forRequest($request)->plan?->slug;

        return match ($collection) {
            // A member keeps the program they follow even after an admin archives it (never a draft).
            ContentCollection::Programs => Plan::query()
                ->where(fn (Builder $q) => $q->where('status', 'published')->when($request->user()?->profile?->plan_id, fn ($w, $id) => $w->orWhere(fn ($x) => $x->where('id', $id)->where('status', 'archived'))))
                ->with(['days.items.exercise', 'phases']),
            ContentCollection::Exercises => Exercise::query()->published()->with(['media', 'postureRules']),
            ContentCollection::Foods => Food::query()->whereNull('user_id')->with('servings'),
            ContentCollection::Badges => Badge::query()->where('is_active', true),
            ContentCollection::MembershipPlans => MembershipPlan::query()->published(),
            ContentCollection::Announcements => Announcement::query()->visibleTo($planSlug),
            ContentCollection::Banners => Banner::query()->visibleTo($planSlug),
            ContentCollection::Help => HelpArticle::query()->published(),
        };
    }

    /** @return class-string<JsonResource> */
    private function resource(ContentCollection $collection): string
    {
        return match ($collection) {
            ContentCollection::Programs => ProgramDetailResource::class,
            ContentCollection::Exercises => ExerciseResource::class,
            ContentCollection::Foods => FoodResource::class,
            ContentCollection::Badges => BadgeResource::class,
            ContentCollection::MembershipPlans => MembershipPlanResource::class,
            ContentCollection::Announcements => AnnouncementResource::class,
            ContentCollection::Banners => BannerResource::class,
            ContentCollection::Help => HelpArticleResource::class,
        };
    }

    /**
     * @param  iterable<Model>  $models
     * @return list<array<string, mixed>>
     */
    public function render(ContentCollection $collection, iterable $models, Request $request): array
    {
        $resource = $this->resource($collection);

        return array_values($resource::collection(collect($models))->resolve($request));
    }

    /** @return array{cursor: int, collections: array<string, array{version: int, count: int}>} */
    public function manifest(Request $request): array
    {
        $versions = ContentChange::query()->selectRaw('collection, MAX(id) as v')->groupBy('collection')->pluck('v', 'collection');

        $collections = [];
        foreach (ContentCollection::cases() as $collection) {
            $collections[$collection->value] = [
                'version' => (int) ($versions[$collection->value] ?? 0),
                'count' => $this->query($collection, $request)->toBase()->count(),
            ];
        }

        return ['cursor' => ContentChange::latestId(), 'collections' => $collections];
    }

    /**
     * Changes after a cursor, rendered for this member.
     *
     * @param  list<ContentCollection>  $collections
     * @return array{items: list<array<string, mixed>>, cursor: int, has_more: bool}|null null = cursor too old / unknown (resync)
     */
    public function changes(Request $request, int $after, array $collections, int $limit = self::PAGE_LIMIT): ?array
    {
        $latest = ContentChange::latestId();
        $oldest = (int) ContentChange::query()->min('id');

        // A cursor from the future (restored database) or from before pruned history cannot be trusted.
        if ($after > $latest || ($after > 0 && $oldest > 0 && $after < $oldest - 1)) {
            return null;
        }

        $rows = ContentChange::query()->where('id', '>', $after)
            ->whereIn('collection', array_map(fn (ContentCollection $c) => $c->value, $collections))
            ->orderBy('id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        // Only the final state of each document matters; keep the position of its last change.
        $last = $rows->groupBy(fn ($r) => $r->collection.':'.$r->entity_id)->map(fn (Collection $g) => $g->last())->sortBy('id')->values();

        $visible = [];
        foreach ($last->groupBy('collection') as $name => $group) {
            $collection = ContentCollection::from($name);
            $ids = $group->where('action', 'upsert')->pluck('entity_id')->all();
            $models = $ids ? $this->query($collection, $request)->whereIn('id', $ids)->get()->keyBy('id') : collect();
            $rendered = collect($this->render($collection, $models->values(), $request))->keyBy('id');
            $visible[$name] = $rendered;
        }

        $items = $last->map(function ($row) use ($visible) {
            $resource = $row->action === 'upsert' ? ($visible[$row->collection][$row->entity_id] ?? null) : null;

            return [
                'change_id' => $row->id,
                'collection' => $row->collection,
                'id' => (int) $row->entity_id,
                // Deleted, unpublished, expired or not meant for this member: all the same to the app.
                'action' => $resource ? 'upsert' : 'delete',
                'resource' => $resource,
            ];
        })->values()->all();

        return [
            'items' => $items,
            'cursor' => $rows->isEmpty() ? $after : (int) $rows->last()->id,
            'has_more' => $hasMore,
        ];
    }
}
