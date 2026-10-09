<?php

namespace App\Services;

use App\Models\User;
use App\Models\XpEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class XpService
{
    public function levelFor(int $xp): int
    {
        return (int) floor(sqrt(max(0, $xp) / config('ironpulse.xp.level_base'))) + 1;
    }

    /** Total XP needed to reach a level. */
    public function xpForLevel(int $level): int
    {
        return (int) ((max(1, $level) - 1) ** 2 * config('ironpulse.xp.level_base'));
    }

    /** @return array{level:int,into:int,needed:int,pct:float,next_at:int} */
    public function progress(int $xp): array
    {
        $level = $this->levelFor($xp);
        $floor = $this->xpForLevel($level);
        $ceil = $this->xpForLevel($level + 1);
        $needed = max(1, $ceil - $floor);

        return [
            'level' => $level,
            'into' => $xp - $floor,
            'needed' => $needed,
            'pct' => round(min(1, ($xp - $floor) / $needed), 4),
            'next_at' => $ceil,
        ];
    }

    /**
     * Award XP once per (type, source). Returns null if this source was already awarded.
     */
    public function award(User $user, string $type, int $amount, string $description, ?string $sourceType = null, ?int $sourceId = null): ?XpEvent
    {
        if ($amount === 0) {
            return null;
        }

        try {
            return DB::transaction(function () use ($user, $type, $amount, $description, $sourceType, $sourceId) {
                if ($sourceType !== null && $sourceId !== null && XpEvent::query()
                    ->where(['user_id' => $user->id, 'type' => $type, 'source_type' => $sourceType, 'source_id' => $sourceId])
                    ->exists()) {
                    return null;
                }

                $event = XpEvent::create([
                    'user_id' => $user->id,
                    'type' => $type,
                    'amount' => $amount,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'description' => $description,
                    'created_at' => now(),
                ]);

                $user->refresh();
                $total = max(0, $user->xp_total + $amount);
                $user->forceFill(['xp_total' => $total, 'level' => $this->levelFor($total)])->save();

                return $event;
            });
        } catch (UniqueConstraintViolationException) {
            return null; // lost a race with an identical award: treat as already awarded
        }
    }
}
