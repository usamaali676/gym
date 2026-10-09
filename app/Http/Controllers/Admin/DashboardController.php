<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\Food;
use App\Models\PostureRule;
use App\Models\PostureSession;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $weekAgo = now()->subDays(7);

        return view('admin.index', [
            'stats' => [
                'users' => User::where('is_guest', false)->count(),
                'guests' => User::where('is_guest', true)->count(),
                'active' => User::where('last_seen_at', '>=', $weekAgo)->count(),
                'admins' => User::where('role', 'admin')->count(),
                'workouts' => WorkoutSession::where('status', 'completed')->where('ended_at', '>=', $weekAgo)->count(),
                'posture' => PostureSession::where('started_at', '>=', $weekAgo)->count(),
                'exercises' => Exercise::count(),
                'foods' => Food::whereNull('user_id')->count(),
                'custom_foods' => Food::whereNotNull('user_id')->count(),
                'rules' => PostureRule::where('is_active', true)->count(),
            ],
            'health' => [
                'coach' => config('ironpulse.coach.enabled') && config('ironpulse.coach.api_key') && config('ironpulse.coach.model'),
                'coach_enabled' => (bool) config('ironpulse.coach.enabled'),
                'mail' => config('mail.default'),
                'mediapipe' => is_file(config('ironpulse.posture.assets_dir').'/models/pose_landmarker_lite.task') && is_file(config('ironpulse.posture.assets_dir').'/wasm/vision_wasm_internal.wasm'),
                'guest_mode' => (bool) config('ironpulse.guest_mode'),
                'queue' => config('queue.default'),
                'app_env' => app()->environment(),
                'debug' => (bool) config('app.debug'),
            ],
        ]);
    }
}
