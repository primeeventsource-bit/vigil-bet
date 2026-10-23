<?php

declare(strict_types=1);

namespace App\Services\EdgeLab;

use Illuminate\Support\Facades\Cache;

/**
 * Stops Vigil from making one Edge Lab outage into two.
 *
 * Edge Lab rate limits per minute. A sync job that keeps retrying a dead
 * or angry upstream burns that allowance on failures, so that the moment
 * the upstream comes back the client is already throttled and still cannot
 * reach it.
 *
 * Three states, kept in the cache rather than in a column, because this is
 * knowledge about right now and not history worth keeping:
 *
 *   closed    normal. Failures are counted.
 *   open      too many consecutive failures. Calls fail fast without
 *             leaving the building, until the cooldown expires.
 *   half-open the cooldown has passed. Exactly one call is let through to
 *             find out whether the upstream is back; it closes the breaker
 *             on success and re-opens it on failure.
 *
 * A single success resets the count. The threshold is consecutive
 * failures, not failures in a window: an upstream that answers one call in
 * three is unwell but reachable, and sync jobs that retry are the right
 * response to that, not a breaker.
 */
class CircuitBreaker
{
    private const FAILURES_KEY = 'edgelab:breaker:failures';

    private const OPENED_AT_KEY = 'edgelab:breaker:opened_at';

    /*
     * Settings are read when they are used rather than captured in the
     * constructor. This is a singleton: one built at boot would hold
     * whatever the configuration said then, and a change to it - in a
     * test, or by an operator raising the threshold during an incident -
     * would silently have no effect on the object actually deciding.
     */
    private function threshold(): int
    {
        return max(1, (int) config('edgelab.breaker.failure_threshold'));
    }

    private function cooldownSeconds(): int
    {
        return max(1, (int) config('edgelab.breaker.cooldown_seconds'));
    }

    /** Whether a call may be attempted at all. */
    public function allowsRequest(): bool
    {
        return $this->state() !== 'open';
    }

    public function state(): string
    {
        $openedAt = Cache::get(self::OPENED_AT_KEY);

        if ($openedAt === null) {
            return 'closed';
        }

        return (now()->timestamp - (int) $openedAt) >= $this->cooldownSeconds() ? 'half-open' : 'open';
    }

    public function recordSuccess(): void
    {
        Cache::forget(self::FAILURES_KEY);
        Cache::forget(self::OPENED_AT_KEY);
    }

    public function recordFailure(): void
    {
        $failures = (int) Cache::get(self::FAILURES_KEY, 0) + 1;

        // Held a good deal longer than the cooldown, so a slow trickle of
        // failures still adds up to an open breaker rather than expiring
        // between them and never reaching the threshold.
        Cache::put(self::FAILURES_KEY, $failures, now()->addSeconds($this->cooldownSeconds() * 10));

        if ($failures >= $this->threshold()) {
            Cache::put(self::OPENED_AT_KEY, now()->timestamp, now()->addSeconds($this->cooldownSeconds() * 10));
        }
    }

    public function consecutiveFailures(): int
    {
        return (int) Cache::get(self::FAILURES_KEY, 0);
    }

    /** Seconds until the breaker will let a call through, or null when it already does. */
    public function retryInSeconds(): ?int
    {
        $openedAt = Cache::get(self::OPENED_AT_KEY);

        if ($openedAt === null) {
            return null;
        }

        return max(0, $this->cooldownSeconds() - (now()->timestamp - (int) $openedAt)) ?: null;
    }

    /** For tests and for an operator who has fixed the upstream and wants to retry now. */
    public function reset(): void
    {
        $this->recordSuccess();
    }
}
