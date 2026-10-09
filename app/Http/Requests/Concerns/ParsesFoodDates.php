<?php

namespace App\Http\Requests\Concerns;

use Carbon\CarbonImmutable;

trait ParsesFoodDates
{
    /** A Y-m-d request value as a start-of-day date in the user's time zone. */
    public function dateInput(string $key): ?CarbonImmutable
    {
        $value = $this->input($key);
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value, $this->user()->timezone())->startOfDay();
    }

    /** Dates you can log food on: up to a year back and up to tomorrow (time zones). */
    protected function loggableDateRules(): array
    {
        $tz = $this->user()?->timezone() ?? 'UTC';
        $today = CarbonImmutable::now($tz)->startOfDay();

        return ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today->subDays(365)->toDateString(), 'before_or_equal:'.$today->addDay()->toDateString()];
    }
}
