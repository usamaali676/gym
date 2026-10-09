<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/** Web app manifest and the offline fallback page used by the service worker. */
class PwaController extends Controller
{
    public function manifest(): JsonResponse
    {
        $icon = fn (string $file, string $size, string $purpose = 'any') => [
            'src' => asset("icons/{$file}"), 'sizes' => $size, 'type' => 'image/png', 'purpose' => $purpose,
        ];
        $shortcut = fn (string $name, string $route) => [
            'name' => $name, 'url' => route($route), 'icons' => [['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png']],
        ];

        return response()->json([
            'id' => url('/'),
            'name' => config('app.name'),
            'short_name' => config('app.name'),
            'description' => 'Plan-based gym tracker: game-mode workouts, posture lab, diet and progress.',
            'start_url' => route('dashboard'),
            'scope' => url('/').'/',
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => '#07090A',
            'theme_color' => '#07090A',
            'categories' => ['health', 'fitness', 'sports'],
            'icons' => [
                $icon('icon-192.png', '192x192'),
                $icon('icon-512.png', '512x512'),
                $icon('icon-maskable-512.png', '512x512', 'maskable'),
                ['src' => asset('icons/icon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ],
            'shortcuts' => [
                $shortcut("Today's workout", 'workout.start'),
                $shortcut('Posture Lab', 'posture.index'),
                $shortcut('Food log', 'food.index'),
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function offline(): View
    {
        return view('offline');
    }
}
