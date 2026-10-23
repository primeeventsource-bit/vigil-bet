<?php

declare(strict_types=1);

namespace App\Data\EdgeLab;

/**
 * One page of a keyset-paginated list, and the cursor to ask for the next.
 *
 * `nextSince` is null when there is nothing further. Edge Lab answers with
 * null once you have caught up, and older builds answered with the cursor
 * you sent; both mean the same thing, so `hasMore()` treats a cursor that
 * has not moved as caught up rather than looping on it forever.
 */
final readonly class Page
{
    /** @param  array<int, array<string, mixed>>  $rows */
    public function __construct(
        public array $rows,
        public ?int $nextSince,
        public ?int $requestedSince = null,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public static function fromResponse(array $payload, ?int $requestedSince = null): self
    {
        $meta = (array) ($payload['meta'] ?? []);
        $next = $meta['next_since'] ?? null;

        return new self(
            rows: array_values((array) ($payload['data'] ?? [])),
            nextSince: $next === null ? null : (int) $next,
            requestedSince: $requestedSince ?? (isset($meta['since']) && $meta['since'] !== null ? (int) $meta['since'] : null),
        );
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * Whether asking again would be worth it.
     *
     * An empty page always means caught up, whatever cursor came back, and
     * a cursor that did not move means the same. Either check alone has
     * been enough to produce an infinite sync loop in somebody's codebase.
     */
    public function hasMore(): bool
    {
        if ($this->isEmpty() || $this->nextSince === null) {
            return false;
        }

        return $this->requestedSince === null || $this->nextSince > $this->requestedSince;
    }
}
