<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

trait ParsesDates
{
    /** A Y-m-d query/body value read in the member's own timezone; falls back to their "today". */
    protected function dateParam(Request $request, User $user, string $key = 'date'): CarbonImmutable
    {
        $value = $request->input($key);

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $value, $user->timezone());
            if ($date !== false && $date->format('Y-m-d') === $value) {
                return $date->startOfDay();
            }
        }

        return $user->localToday();
    }
}
