<?php

declare(strict_types=1);

namespace App\Modeling\Predictors;

use App\Data\GameProjection;
use App\Data\MarketPrices;
use App\Enums\Market;

/**
 * Turns a projection into probabilities for one market.
 *
 * Each sport gets the pricer that matches how it actually scores, chosen by
 * the score_model entry in config/sports.php rather than by a condition
 * buried in a service.
 */
interface MarketPricer
{
    public function key(): string;

    /** @return array<int, Market> */
    public function supports(): array;

    public function price(GameProjection $projection, Market $market, ?float $line = null): MarketPrices;
}
