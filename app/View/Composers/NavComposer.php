<?php

namespace App\View\Composers;

use App\Services\StreakService;
use App\Services\XpService;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/** Builds the sidebar / tab-bar items, showing only entries whose route exists. */
class NavComposer
{
    public function __construct(private readonly XpService $xp, private readonly StreakService $streaks) {}

    public function compose(View $view): void
    {
        $user = auth()->user();

        $items = collect(config('ironpulse.nav'))
            ->filter(fn (array $item) => Route::has($item['route']))
            ->filter(fn (array $item) => ($item['feature'] ?? null) !== 'coach' || config('ironpulse.coach.enabled'))
            ->filter(fn (array $item) => empty($item['admin']) || $user?->isAdmin())
            ->map(function (array $item) {
                $item['url'] = route($item['route']);
                $item['active'] = Request::routeIs($item['match'] ?? $item['route']);

                return $item;
            })
            ->values();

        $view->with([
            'navItems' => $items,
            'navXp' => $this->xp->progress($user->xp_total),
            'navStreak' => $this->streaks->current($user),
        ]);
    }
}
