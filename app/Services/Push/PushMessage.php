<?php

namespace App\Services\Push;

/** What to tell a member. `title` / `body` are shown on a lock screen only if the member allowed details there. */
final class PushMessage
{
    /**
     * @param  string  $category  workout_reminders | membership | achievements | announcements | nutrition
     * @param  array<string, scalar>  $data  routing info for the app (never personal data)
     */
    public function __construct(
        public readonly string $category,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {}
}
