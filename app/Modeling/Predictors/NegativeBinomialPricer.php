<?php

declare(strict_types=1);

namespace App\Modeling\Predictors;

use App\Data\GameProjection;
use App\Data\MarketPrices;
use App\Enums\Market;
use App\Enums\Side;
use App\Modeling\Math\NegativeBinomial;
use App\Modeling\Math\Poisson;

/**
 * Prices baseball off two overdispersed run distributions.
 *
 * Runs cluster: a big inning is a real event, so the variance of runs per
 * game sits well above the mean. Pricing totals with Poisson would treat a
 * twelve run game as nearly impossible and quietly sell overs that should be
 * bought. When no dispersion is supplied this falls back to Poisson rather
 * than inventing one.
 *
 * Baseball has no ties, so the small mass on equal scores is split by
 * relative strength: extra innings, where the better team is a small
 * favourite but not much more.
 */
class NegativeBinomialPricer implements MarketPricer
{
    private const EXTRA_INNINGS_EDGE = 0.10;

    public function key(): string
    {
        return 'neg_binomial';
    }

    /** @return array<int, Market> */
    public function supports(): array
    {
        return [Market::Moneyline, Market::Total, Market::Spread];
    }

    public function price(GameProjection $projection, Market $market, ?float $line = null): MarketPrices
    {
        return match ($market) {
            Market::Moneyline => $this->moneyline($projection),
            Market::Total => $this->total($projection, $line),
            Market::Spread => $this->runLine($projection, $line ?? -1.5),
        };
    }

    private function moneyline(GameProjection $projection): MarketPrices
    {
        $outcome = $projection->dispersion !== null
            ? NegativeBinomial::matchOutcome($projection->homeScore, $projection->awayScore, $projection->dispersion)
            : Poisson::matchOutcome($projection->homeScore, $projection->awayScore);

        $edge = $projection->homeScore - $projection->awayScore;
        $homeShare = min(0.65, max(0.35, 0.5 + ($edge * self::EXTRA_INNINGS_EDGE)));

        return new MarketPrices(
            market: Market::Moneyline,
            probabilities: [
                Side::Home->value => $outcome['home'] + ($outcome['draw'] * $homeShare),
                Side::Away->value => $outcome['away'] + ($outcome['draw'] * (1 - $homeShare)),
            ],
        );
    }

    private function total(GameProjection $projection, ?float $line): MarketPrices
    {
        $line ??= round($projection->total() * 2) / 2;

        $total = $projection->dispersion !== null
            ? NegativeBinomial::totalProbabilities(
                $projection->homeScore,
                $projection->awayScore,
                $projection->dispersion,
                $line,
            )
            : Poisson::totalProbabilities($projection->homeScore, $projection->awayScore, $line);

        return new MarketPrices(
            market: Market::Total,
            probabilities: [
                Side::Over->value => $total['over'],
                Side::Under->value => $total['under'],
            ],
            push: $total['push'],
            line: $line,
        );
    }

    private function runLine(GameProjection $projection, float $line): MarketPrices
    {
        $homeCovers = 0.0;
        $awayCovers = 0.0;
        $push = 0.0;
        $mass = 0.0;

        for ($h = 0; $h <= 20; $h++) {
            $pHome = $this->runProbability($h, $projection->homeScore, $projection->dispersion);

            if ($pHome < 1e-12) {
                continue;
            }

            for ($a = 0; $a <= 20; $a++) {
                $joint = $pHome * $this->runProbability($a, $projection->awayScore, $projection->dispersion);
                $mass += $joint;
                $adjusted = ($h - $a) + $line;

                if (abs($adjusted) < 1e-9) {
                    $push += $joint;
                } elseif ($adjusted > 0) {
                    $homeCovers += $joint;
                } else {
                    $awayCovers += $joint;
                }
            }
        }

        return new MarketPrices(
            market: Market::Spread,
            probabilities: [
                Side::Home->value => $homeCovers / $mass,
                Side::Away->value => $awayCovers / $mass,
            ],
            push: $push / $mass,
            line: $line,
        );
    }

    private function runProbability(int $runs, float $mean, ?float $dispersion): float
    {
        return $dispersion !== null
            ? NegativeBinomial::pmf($runs, $mean, $dispersion)
            : Poisson::pmf($runs, $mean);
    }
}
