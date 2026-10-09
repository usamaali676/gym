<?php

namespace App\Services\Coach;

use App\Models\CoachMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

class CoachService
{
    public const MAX_INPUT = 1000;

    public function __construct(
        private readonly CoachModel $model,
        private readonly CoachContextService $context,
    ) {}

    /** The coach is only offered when it is switched on and has both a key and a model name. */
    public function enabled(): bool
    {
        return (bool) config('ironpulse.coach.enabled');
    }

    public function configured(): bool
    {
        return $this->enabled() && filled(config('ironpulse.coach.api_key')) && filled(config('ironpulse.coach.model'));
    }

    public function optedIn(User $user): bool
    {
        $user->loadMissing('profile');

        return (bool) $user->profile?->ai_coach_opt_in;
    }

    public function limit(): int
    {
        return max(1, (int) config('ironpulse.coach.daily_message_limit'));
    }

    public function used(User $user): int
    {
        $start = CarbonImmutable::now($user->timezone())->startOfDay()->setTimezone(config('app.timezone'));

        return CoachMessage::where('user_id', $user->id)->where('role', 'user')->where('created_at', '>=', $start)->count();
    }

    public function remaining(User $user): int
    {
        return max(0, $this->limit() - $this->used($user));
    }

    /** @return Collection<int, CoachMessage> oldest first */
    public function history(User $user, int $limit = 60): Collection
    {
        return CoachMessage::where('user_id', $user->id)->orderByDesc('id')->limit($limit)->get()->reverse()->values();
    }

    /**
     * @return array{user: CoachMessage, reply: CoachMessage, remaining: int, truncated: bool}
     *
     * @throws CoachUnavailable
     */
    public function ask(User $user, string $text): array
    {
        if (! $this->configured()) {
            throw new CoachUnavailable('The coach is not available on this server.', 503);
        }
        if (! $this->optedIn($user)) {
            throw new CoachUnavailable('Switch the coach on first. It is optional and off until you choose it.', 403);
        }
        if ($this->remaining($user) <= 0) {
            throw new CoachUnavailable("You have used today's {$this->limit()} coach messages. They reset at midnight.", 429);
        }

        // Context: the last few turns, always starting with something the user said.
        $recent = $this->history($user, (int) config('ironpulse.coach.history_messages'))
            ->map(fn (CoachMessage $m) => ['role' => $m->role, 'content' => $m->content])->values()->all();
        while ($recent && $recent[0]['role'] !== 'user') {
            array_shift($recent);
        }

        // The question is stored first so a flood of parallel requests cannot slip past the daily limit.
        $question = CoachMessage::create(['user_id' => $user->id, 'role' => 'user', 'content' => $text]);

        try {
            $completion = $this->model->complete(
                CoachPrompt::system($this->context->summary($user)),
                [...$recent, ['role' => 'user', 'content' => $text]],
                (int) config('ironpulse.coach.max_output_tokens'),
            );
        } catch (CoachUnavailable $e) {
            $question->delete();   // a failed attempt does not use up a message

            throw $e;
        } catch (Throwable $e) {
            $question->delete();
            report($e);

            throw new CoachUnavailable('The coach could not answer that. Please try again.', 502);
        }

        $reply = CoachMessage::create([
            'user_id' => $user->id, 'role' => 'assistant', 'content' => $completion->text,
            'input_tokens' => $completion->inputTokens, 'output_tokens' => $completion->outputTokens,
        ]);

        return ['user' => $question, 'reply' => $reply, 'remaining' => $this->remaining($user), 'truncated' => $completion->truncated];
    }

    public function clear(User $user): void
    {
        CoachMessage::where('user_id', $user->id)->delete();
    }
}
