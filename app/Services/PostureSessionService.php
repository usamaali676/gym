<?php

namespace App\Services;

use App\Models\PostureRule;
use App\Models\PostureSession;
use App\Models\User;
use App\Models\XpEvent;
use Illuminate\Support\Facades\DB;

/**
 * Stores the summary of a Posture Lab set and pays the (capped) XP for it.
 * Only numbers are stored: no video, no images, no landmarks.
 */
class PostureSessionService
{
    public function __construct(private readonly XpService $xp) {}

    /**
     * Idempotent on `client_uuid`: a retried request returns the stored session and pays nothing twice.
     *
     * @param  array<string, mixed>  $data  validated payload
     * @return array{session: PostureSession, created: bool, xp: array{session:int, reps:int, total:int}}
     */
    public function record(User $user, array $data): array
    {
        $existing = PostureSession::where('client_uuid', $data['client_uuid'])->first();
        if ($existing) {
            abort_unless($existing->user_id === $user->id, 409, 'This session id is already in use.');

            return ['session' => $existing, 'created' => false, 'xp' => ['session' => 0, 'reps' => 0, 'total' => 0]];
        }

        return DB::transaction(function () use ($user, $data) {
            $rule = isset($data['posture_rule_id']) ? PostureRule::find($data['posture_rule_id']) : null;

            $session = PostureSession::create([
                'user_id' => $user->id,
                'exercise_id' => $data['exercise_id'],
                'posture_rule_id' => $rule?->id,
                'workout_session_id' => $data['workout_session_id'] ?? null,
                'client_uuid' => $data['client_uuid'],
                'rule_version' => $data['rule_version'],
                'camera_view' => $data['camera_view'],
                'started_at' => $data['started_at'],
                'duration_seconds' => $data['duration_seconds'],
                'total_reps' => $data['total_reps'],
                'good_reps' => $data['good_reps'],
                'bad_reps' => $data['bad_reps'],
                'hold_seconds' => $data['hold_seconds'] ?? null,
                'form_score_avg' => $data['form_score_avg'],
                'form_score_min' => $data['form_score_min'],
                'error_counts' => $data['error_counts'] ?? [],
                'rep_log' => $data['rep_log'] ?? [],
                'avg_fps' => $data['avg_fps'] ?? null,
                'model_variant' => $data['model_variant'] ?? 'lite',
            ]);

            return ['session' => $session, 'created' => true, 'xp' => $this->award($user, $session)];
        });
    }

    /** @return array{session:int, reps:int, total:int} */
    private function award(User $user, PostureSession $session): array
    {
        $worthIt = $session->good_reps >= config('ironpulse.xp.posture_session_min_good_reps')
            || ($session->hold_seconds ?? 0) >= config('ironpulse.xp.posture_session_min_hold_seconds');

        $sessionXp = 0;
        $repXp = 0;

        if ($worthIt) {
            $event = $this->xp->award($user, 'posture_session', config('ironpulse.xp.posture_session'), 'Posture Lab set', 'posture_session', $session->id);
            $sessionXp = $event?->amount ?? 0;

            $perRep = (int) config('ironpulse.xp.posture_good_rep');
            $wanted = min($session->good_reps, (int) config('ironpulse.xp.posture_good_rep_session_cap')) * $perRep;
            $today = XpEvent::query()
                ->where('user_id', $user->id)
                ->where('type', 'posture_good_rep')
                ->where('created_at', '>=', now()->startOfDay())
                ->sum('amount');
            $room = max(0, (int) config('ironpulse.xp.posture_good_rep_daily_cap') - (int) $today);
            $amount = min($wanted, $room);

            if ($amount > 0) {
                $repXp = $this->xp->award($user, 'posture_good_rep', $amount, "{$session->good_reps} clean reps in the Posture Lab", 'posture_session', $session->id)?->amount ?? 0;
            }
        }

        return ['session' => $sessionXp, 'reps' => $repXp, 'total' => $sessionXp + $repXp];
    }
}
