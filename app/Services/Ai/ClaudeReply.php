<?php

declare(strict_types=1);

namespace App\Services\Ai;

/** One answer from the model, reduced to what Vigil actually uses. */
final readonly class ClaudeReply
{
    public function __construct(
        public string $body,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $durationMs = 0,
        /** A safety decline. Not an explanation, and never stored as one. */
        public bool $refused = false,
    ) {}
}
