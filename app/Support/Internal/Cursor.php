<?php

declare(strict_types=1);

namespace App\Support\Internal;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Keyset pagination on a monotonic id, and the envelope that goes with it.
 *
 * A `since` cursor rather than a page number, because the caller is a
 * projection job reading an append-only table: page 3 of a list that grew between
 * requests is not the same page 3, and the rows that fell between them are
 * gone for good. An id strictly greater than the last one seen cannot skip
 * a row however much was written in between.
 *
 * `next_since` is the last id on this page, and null when the page is the
 * end of the table. A client that gets null has caught up; a client that
 * gets an id has more to ask for, and asking again with it is safe whether
 * or not anything new has landed.
 */
final class Cursor
{
    public function __construct(
        public readonly ?int $since,
        public readonly int $limit,
    ) {}

    /** Apply the cursor to a query ordered by the given key. */
    public function apply(Builder $query, string $key = 'id'): Builder
    {
        return $query
            ->when($this->since !== null, fn (Builder $q) => $q->where($key, '>', $this->since))
            ->orderBy($key)
            ->limit($this->limit);
    }

    /**
     * The response envelope. `has_more` is honest about what it knows: a
     * full page might be the last one, and the only way to find out is to
     * ask again, which is exactly what a client does with next_since.
     *
     * @param  Collection<int, mixed>|array<int, mixed>  $rows
     * @return array<string, mixed>
     */
    public function meta(Collection|array $rows, ?int $lastId): array
    {
        $count = $rows instanceof Collection ? $rows->count() : count($rows);

        return [
            'count' => $count,
            'limit' => $this->limit,
            'since' => $this->since,
            'next_since' => $count > 0 ? $lastId : $this->since,
            'has_more' => $count === $this->limit,
            'as_of' => now()->toIso8601String(),
        ];
    }
}
