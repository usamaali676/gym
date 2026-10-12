<?php

namespace App\Services\Coach;

final class CoachCompletion
{
    public function __construct(
        public readonly string $text,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly bool $truncated = false,
        public readonly bool $declined = false,
    ) {}
}
