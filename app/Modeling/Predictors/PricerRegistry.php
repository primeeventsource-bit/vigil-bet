<?php

declare(strict_types=1);

namespace App\Modeling\Predictors;

use App\Enums\Sport;
use RuntimeException;

/**
 * Picks the pricer that matches how a sport actually scores, from the
 * score_model entry in config/sports.php. Adding a sport never means editing
 * a condition in here.
 */
class PricerRegistry
{
    /** @var array<string, MarketPricer> */
    private array $pricers = [];

    public function __construct(MarketPricer ...$pricers)
    {
        foreach ($pricers as $pricer) {
            $this->pricers[$pricer->key()] = $pricer;
        }
    }

    public function for(Sport $sport): MarketPricer
    {
        $key = (string) $sport->config('score_model');

        return $this->pricers[$key]
            ?? throw new RuntimeException("No pricer registered for score model [{$key}] on {$sport->value}.");
    }

    public function get(string $key): MarketPricer
    {
        return $this->pricers[$key]
            ?? throw new RuntimeException("No pricer registered with key [{$key}].");
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->pricers);
    }
}
