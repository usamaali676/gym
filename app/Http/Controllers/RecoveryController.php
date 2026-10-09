<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\HabitService;
use App\Services\PlanService;
use App\Services\RoutineService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RecoveryController extends Controller
{
    public function __construct(
        private readonly HabitService $habits,
        private readonly RoutineService $routines,
        private readonly PlanService $plans,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user()->load('profile.plan');
        $today = $user->localToday();
        $plan = $this->plans->planFor($user);
        $days = $plan ? $this->plans->days($plan) : collect();

        $dayNo = (int) $request->query('day', $today->dayOfWeekIso);
        $dayNo = $days->has($dayNo) ? $dayNo : $today->dayOfWeekIso;
        $planDay = $days->get($dayNo);

        $type = $request->query('type');
        $type = in_array($type, ['warmup', 'cooldown', 'recovery'], true) ? $type : ($planDay?->is_rest ? 'recovery' : 'warmup');
        $routine = $planDay ? $this->routines->forDay($planDay, $type) : null;

        return view('recovery.index', [
            'habitConfig' => [
                'state' => $this->habits->state($user, $today),
                'urls' => [
                    'water' => route('api.water.add'),
                    'undoWater' => route('api.water.undo'),
                    'creatine' => route('api.creatine.set'),
                    'sleep' => route('api.sleep.set'),
                    'mobility' => route('api.mobility.set'),
                ],
            ],
            'days' => $days,
            'dayNo' => $dayNo,
            'planDay' => $planDay,
            'type' => $type,
            'routine' => $routine,
            'rules' => $plan?->rules ?? Plan::default()?->rules ?? [],
            'user' => $user,
        ]);
    }
}
