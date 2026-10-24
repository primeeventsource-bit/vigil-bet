<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Market;
use App\Enums\Side;
use InvalidArgumentException;

/**
 * Model probabilities for both sides of one market, with the push explicitly
 * accounted for rather than quietly folded into one side.
 *
 * A spread of exactly 3 in the NFL pushes often enough that ignoring it
 * overstates both sides by a couple of points, which is larger than most of
 * the edges this app will ever find.
 */
final readonly class MarketPrices
{
    /** @param  array<string, float>  $probabilities  keyed by Side value */
    public function __construct(
        public Market $market,
        public array $probabilities,
        public float $push = 0.0,
        public ?float $line = null,
    ) {}

    public function for(Side $side): float
    {
        return $this->probabilities[$side->value]
            ?? throw new InvalidArgumentException("No probability for side {$side->value} in {$this->market->value}.");
    }

    /** Probabilities plus push must account for all outcomes. */
    public function sumsToOne(float $tolerance = 1e-6): bool
    {
        return abs(array_sum($this->probabilities) + $this->push - 1.0) <= $tolerance;
    }

    /**
     * Probability conditional on not pushing, which is what a price has to
     * be compared against: a push returns the stake rather than losing it.
     */
    public function excludingPush(Side $side): float
    {
        $live = 1.0 - $this->push;

        return $live <= 0.0 ? 0.0 : $this->for($side) / $live;
    }
}
