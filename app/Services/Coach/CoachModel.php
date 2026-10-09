<?php

namespace App\Services\Coach;

/** The one thing the coach needs from an AI service: turn a conversation into a reply. */
interface CoachModel
{
    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages  starts with a user message
     *
     * @throws CoachUnavailable when the service cannot answer right now (the message is safe to show)
     */
    public function complete(string $system, array $messages, int $maxTokens): CoachCompletion;
}
