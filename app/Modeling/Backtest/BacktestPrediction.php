<?php

declare(strict_types=1);

namespace App\Modeling\Backtest;

use App\Enums\VigMethod;
use App\Modeling\Odds\OddsConverter;
use App\Modeling\Odds\VigRemover;
use Carbon\CarbonImmutable;

/**
 * One scored prediction from a walk-forward run: what the model said, what
 * happened, and what the market closed at.
 */
final readonly class BacktestPrediction
{
    /** @param  array<string, int>  $closingMarket */
    public function __construct(
        public int $eventId,
        public CarbonImmutable $startsAt,
        public float $probability,
        public int $outcome,
        public bool $wasTie = false,
        public array $closingMarket = [],
    ) {}

    public function hasMarket(): bool
    {
        return count($this->closingMarket) >= 2 && isset($this->closingMarket['home']);
    }

    /** The no-vig closing probability for the home side. */
    public function marketProbability(VigMethod $method = VigMethod::Multiplicative): ?float
    {
        if (! $this->hasMarket()) {
            return null;
        }

        return VigRemover::fairProbabilities($this->closingMarket, $method)['home'] ?? null;
    }

    /**
     * How far the model's home probability sits from the market's fair one.
     *
     * This used to be called closing line value, and it is not. CLV needs
     * two prices: the one a bet was struck at and the one the market closed
     * at. Here there is only the close, and the "bet" is struck at the
     * model's own fair price, so the subtraction collapses into the gap
     * between two opinions about the same game.
     *
     * Averaged over a season that gap is a bias measurement, not a skill
     * measurement, and it is trivially gameable: shading every home
     * probability down three points moves the average by three points while
     * leaving the model's ranking ability identical. It is worth reporting -
     * a model that systematically over-rates home teams has something wrong
     * with it - but it must never be a gate, and it must not be called CLV.
     *
     * Real closing line value is measured on bets that were actually placed,
     * at the price actually taken, against the close that followed.
     */
    public function marketGap(VigMethod $method = VigMethod::Multiplicative): ?float
    {
        $market = $this->marketProbability($method);

        return $market === null ? null : $market - $this->probability;
    }

    /** Decimal payout if a bet had been struck at the closing price. */
    public function closingDecimal(): ?float
    {
        if (! isset($this->closingMarket['home'])) {
            return null;
        }

        return OddsConverter::americanToDecimal($this->closingMarket['home']);
    }
}
