<?php

namespace App\Services\Coach;

use App\Models\PersonalRecord;
use App\Models\PostureSession;
use App\Models\User;
use App\Services\NutritionTotalsService;
use App\Services\PlanService;
use App\Services\StreakService;

/**
 * The only facts about a user that ever leave the server for the coach. It is a short plain-text summary of
 * training and nutrition setup. It never includes a name, email, address, timezone, photos, food logs,
 * measurements or anything the user typed elsewhere in the app.
 */
class CoachContextService
{
    public function __construct(
        private readonly PlanService $plans,
        private readonly StreakService $streaks,
        private readonly NutritionTotalsService $nutrition,
    ) {}

    public function summary(User $user): string
    {
        $user->loadMissing('profile.plan');
        $p = $user->profile;
        $lines = [];

        if ($p) {
            $lines[] = 'Goal: '.($p->goal?->label() ?? 'not set').'. Fitness level: '.($p->fitness_level?->label() ?? 'not set').'.';
            $lines[] = trim('Age '.($p->age ?? '?').', '.($p->sex && $p->sex->value !== 'unspecified' ? $p->sex->value.', ' : '').'height '.($p->height_cm ? round($p->height_cm).' cm' : '?').', weight '.($p->weight_kg ? round($p->weight_kg, 1).' kg' : '?').'.');
            $lines[] = 'Trains '.count($p->trainingDays()).' days a week. Diet: '.($p->diet_preference?->label() ?? 'not set').'.';
            if ($p->equipment) {
                $lines[] = 'Equipment: '.implode(', ', $p->equipment).'.';
            }
        }

        $plan = $this->plans->planFor($user);
        if ($plan) {
            $lines[] = "Follows the plan \"{$plan->name}\".";
            $today = $this->plans->dayFor($user, $user->localToday());
            if ($today) {
                $lines[] = 'Today is '.($today->is_rest ? 'a rest day.' : "a {$today->title()} day: ".$this->plans->effectiveItems($user, $today)->map(fn ($v) => $v->name())->implode(', ').'.');
            }
            $week = $this->plans->weekProgress($user);
            $lines[] = "This week: {$week['done']} of {$week['target']} sessions done. Workout streak: {$this->streaks->current($user)}.";
        }

        $records = PersonalRecord::query()->where('user_id', $user->id)->whereIn('type', ['est_1rm', 'max_weight'])->with('exercise')->orderByDesc('achieved_at')->limit(4)->get();
        if ($records->isNotEmpty()) {
            $lines[] = 'Recent records: '.$records->map(fn ($r) => ($r->exercise?->name ?? 'Exercise').' '.rtrim(rtrim(number_format((float) $r->value, 1), '0'), '.').' kg ('.($r->type === 'est_1rm' ? 'est. 1RM' : 'heaviest').')')->implode('; ').'.';
        }

        $diet = $this->nutrition->activePlan($user);
        if ($diet) {
            $lines[] = "Daily targets: {$diet->calories} kcal, {$diet->protein_g} g protein, {$diet->carbs_g} g carbs, {$diet->fat_g} g fat.";
        }

        $posture = PostureSession::query()->where('user_id', $user->id)->where('started_at', '>=', now()->subDays(30))->with('exercise')->get();
        if ($posture->isNotEmpty()) {
            $lines[] = 'Posture Lab, last 30 days: '.$posture->count().' sets, average form score '.(int) round($posture->avg('form_score_avg')).'/100.';
        }

        $rules = $plan?->rules;
        if (is_array($rules)) {
            if (isset($rules['progressive_overload']['note'])) {
                $lines[] = 'Plan rule, progression: '.$rules['progressive_overload']['note'];
            }
            if (isset($rules['creatine'])) {
                $lines[] = "Plan rule, creatine: {$rules['creatine']['grams']} g {$rules['creatine']['frequency']}. {$rules['creatine']['note']}";
            }
            if (isset($rules['sleep'])) {
                $lines[] = "Plan rule, sleep: {$rules['sleep']['hours_min']}-{$rules['sleep']['hours_max']} hours.";
            }
        }

        return implode("\n", $lines);
    }
}
