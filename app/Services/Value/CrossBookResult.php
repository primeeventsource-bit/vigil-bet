<?php

declare(strict_types=1);

namespace App\Services\Value;

use Illuminate\Support\Carbon;

/**
 * What one cross-book sweep found. Counts rather than rows, because the
 * rows are already written and the caller wants to know whether anything
 * was there.
 */
final readonly class CrossBookResult
{
    public function __construct(
        public int $arbitrages,
        public int $middles,
        public Carbon $scannedAt,
    ) {}

    public function found(): int
    {
        return $this->arbitrages + $this->middles;
    }
}
