<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Content\ContentFeedService;
use App\Support\Content\ContentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Cache-and-sync endpoints: a manifest, full snapshots and an incremental change feed. */
class ContentController extends Controller
{
    public function __construct(private readonly ContentFeedService $feed) {}

    public function manifest(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->feed->manifest($request) + ['server_time' => now()->toIso8601String()]]);
    }

    /** Full, paginated list of one collection as this member may see it. */
    public function snapshot(Request $request, string $collection): JsonResponse
    {
        $collection = ContentCollection::tryFrom($collection) ?? throw ApiException::notFound('Unknown content collection.');
        $params = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('ironpulse.mobile.page_size.max')],
        ]);

        $page = $this->feed->query($collection, $request)->orderBy('id')
            ->paginate($params['per_page'] ?? config('ironpulse.mobile.page_size.max'), page: $params['page'] ?? 1);

        return response()->json([
            'data' => $this->feed->render($collection, $page->items(), $request),
            'meta' => [
                'collection' => $collection->value,
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** Documents that changed after `after` (a cursor from the manifest or a previous call). */
    public function changes(Request $request): JsonResponse
    {
        $params = $request->validate([
            'after' => ['required', 'integer', 'min:0'],
            'collections' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.ContentFeedService::PAGE_LIMIT],
        ]);

        $collections = collect(explode(',', (string) ($params['collections'] ?? '')))->filter()
            ->map(fn (string $c) => ContentCollection::tryFrom(trim($c)) ?? throw new ApiException('validation_failed', "Unknown collection '{$c}'.", 422))
            ->all() ?: ContentCollection::cases();

        $result = $this->feed->changes($request, (int) $params['after'], $collections, (int) ($params['limit'] ?? ContentFeedService::PAGE_LIMIT));

        if ($result === null) {
            throw new ApiException('cursor_expired', 'This sync cursor is too old. Download the collections again.', 410);
        }

        return response()->json([
            'data' => $result['items'],
            'meta' => ['cursor' => $result['cursor'], 'has_more' => $result['has_more'], 'server_time' => now()->toIso8601String()],
        ]);
    }
}
