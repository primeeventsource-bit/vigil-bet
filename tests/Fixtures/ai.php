<?php

declare(strict_types=1);

use App\Models\VlBoardEntry;
use App\Services\Ai\Claude;
use App\Services\Ai\ClaudeReply;
use App\Services\EdgeLab\Mirror;

/**
 * A mirrored board entry, written through the Mirror the way a sync
 * would write it.
 *
 * Shared between the Read tests rather than defined in one of them,
 * because a helper that only exists when its own file is loaded makes a
 * single-file test run fail for a reason that has nothing to do with the
 * code under test.
 */
function entryFor(array $overrides = []): VlBoardEntry
{
    $row = boardRow($overrides['id'] ?? 1, $overrides);

    app(Mirror::class)->write(VlBoardEntry::class, $row, [
        'edge_lab_id' => (int) $row['id'],
        'edge_lab_event_id' => $overrides['edge_lab_event_id'] ?? ($row['event_id'] ?? null),
        'sport' => $row['sport'],
        'market' => $row['market'],
        'side' => $overrides['side'] ?? ($row['side'] ?? null),
        'selection' => $row['selection'],
        'model_prob' => $row['model_prob'],
        'fair_prob' => $row['fair_prob'],
        'edge' => $row['edge'],
        'edge_at_price' => $row['edge_at_price'],
        'best_book' => $overrides['best_book'] ?? ($row['best_book'] ?? null),
        'best_price_american' => $row['best_price_american'],
        'best_book_title' => $row['best_book_title'],
        'market_verdict' => $row['market_verdict'],
        'recommendation' => $row['recommendation'],
        'pass_reason' => $row['pass_reason'],
        'reason' => $row['reason'],
        'observed_at' => now(),
    ]);

    return VlBoardEntry::query()->latest('id')->firstOrFail();
}

/**
 * Stands in for the model at the seam.
 *
 * Faked here rather than over HTTP because the SDK speaks PSR-18 through
 * discovery and never touches Laravel's client - a test that faked the
 * network would pass while the real call went out.
 *
 * @param  array<int, string>|string  $replies  one answer, or one per attempt
 */
function claudeReturns(array|string $replies, int $inputTokens = 900, int $outputTokens = 110): object
{
    $fake = new class(is_array($replies) ? $replies : [$replies], $inputTokens, $outputTokens) implements Claude
    {
        public int $calls = 0;

        public ?string $error = null;

        public function lastError(): ?string
        {
            return $this->error;
        }

        /** @var array<int, array{model:string, system:string, user:string}> */
        public array $seen = [];

        /** @param  array<int, string>  $replies */
        public function __construct(private array $replies, private int $in, private int $out) {}

        public function ask(string $system, string $user, string $model, int $maxTokens, string $effort): ?ClaudeReply
        {
            $this->seen[] = compact('model', 'system', 'user');
            $body = $this->replies[$this->calls] ?? end($this->replies);
            $this->calls++;

            return new ClaudeReply($body, $this->in, $this->out, 0, 12);
        }
    };

    app()->instance(Claude::class, $fake);

    return $fake;
}

/** A Read long enough to pass the 60-120 word check. */
function paddedRead(string $opening): string
{
    return $opening.' '.str_repeat('The engine measured this against the closing line and recorded what it found. ', 8);
}
