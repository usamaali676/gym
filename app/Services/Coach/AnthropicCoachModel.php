<?php

namespace App\Services\Coach;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Talks to the Anthropic API from the server with the official SDK. The key never reaches the browser, and what
 * goes wrong is logged here without the conversation, then reported to the user in plain words.
 */
class AnthropicCoachModel implements CoachModel
{
    public function __construct(private readonly LoggerInterface $log, private readonly ?ClientInterface $transport = null) {}

    public function complete(string $system, array $messages, int $maxTokens): CoachCompletion
    {
        $model = (string) config('ironpulse.coach.model');
        $key = (string) config('ironpulse.coach.api_key');
        if ($model === '' || $key === '') {
            throw new CoachUnavailable('The coach is not set up on this server.');
        }

        $client = new Client(
            apiKey: $key,
            requestOptions: array_filter(['timeout' => 60.0, 'maxRetries' => 1, 'transporter' => $this->transport]),
        );

        try {
            $message = $client->messages->create(
                model: $model,
                maxTokens: $maxTokens,
                system: $system,
                messages: $messages,
                // Optional: only sent when the operator sets COACH_EFFORT (not every model accepts it).
                outputConfig: config('ironpulse.coach.effort') ? ['effort' => config('ironpulse.coach.effort')] : null,
            );
        } catch (RateLimitException $e) {
            $this->log->warning('coach.rate_limited', ['status' => $e->status]);
            throw new CoachUnavailable('The coach is busy right now. Try again in a minute.', 503);
        } catch (AuthenticationException $e) {
            $this->log->error('coach.auth_failed', ['status' => $e->status]);
            throw new CoachUnavailable('The coach is not set up correctly on this server. Please tell the person who runs it.', 503);
        } catch (APIStatusException $e) {
            $this->log->error('coach.api_error', ['status' => $e->status, 'type' => $e->type?->value]);
            throw new CoachUnavailable('The coach could not answer that. Please try again.', 502);
        } catch (APIConnectionException $e) {
            $this->log->error('coach.connection_failed', ['error' => class_basename($e)]);
            throw new CoachUnavailable('The coach could not be reached. Check your connection and try again.', 502);
        }

        if ($message->stopReason === 'refusal') {
            return new CoachCompletion(
                "I can't help with that one. If it is about training, nutrition or recovery, try asking in a different way.",
                $message->usage->inputTokens, $message->usage->outputTokens, declined: true,
            );
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $text = trim($text);
        if ($text === '') {
            throw new CoachUnavailable('The coach did not have an answer. Please try rephrasing.', 502);
        }

        return new CoachCompletion($text, $message->usage->inputTokens, $message->usage->outputTokens, truncated: $message->stopReason === 'max_tokens');
    }
}
