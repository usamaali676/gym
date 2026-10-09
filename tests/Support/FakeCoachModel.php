<?php

namespace Tests\Support;

use App\Services\Coach\CoachCompletion;
use App\Services\Coach\CoachModel;
use App\Services\Coach\CoachUnavailable;

/** Stands in for the AI service: records what it was asked and answers from a script. */
class FakeCoachModel implements CoachModel
{
    /** @var list<array{system: string, messages: array<int, array<string, string>>, maxTokens: int}> */
    public array $calls = [];

    public ?CoachUnavailable $fail = null;

    public ?\Throwable $crash = null;

    public CoachCompletion $reply;

    public function __construct()
    {
        $this->reply = new CoachCompletion('Add 2.5 kg once you hit the top of the rep range on every set.', 120, 30);
    }

    public function complete(string $system, array $messages, int $maxTokens): CoachCompletion
    {
        $this->calls[] = compact('system', 'messages', 'maxTokens');
        if ($this->crash) {
            throw $this->crash;
        }
        if ($this->fail) {
            throw $this->fail;
        }

        return $this->reply;
    }
}
