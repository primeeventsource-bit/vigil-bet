<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\VigMethod;

/**
 * The market's fair probability for one side, with the bookmaker margin
 * taken out, agreed across however many books were quoting both sides.
 */
final readonly class ConsensusPrice
{
    /**
     * @param  array<string, float>  $perBook  fair probability by book key
     */
    public function __construct(
        public float $fairProbability,
        public int $bookCount,
        /** Median overround across the books used, as a sum of implied probabilities. */
        public float $impliedSum,
        public VigMethod $method,
        public array $perBook = [],
    ) {}
}
