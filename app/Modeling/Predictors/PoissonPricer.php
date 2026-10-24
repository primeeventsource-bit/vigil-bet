<?php

declare(strict_types=1);

namespace App\Modeling\Predictors;

use App\Data\GameProjection;
use App\Data\MarketPrices;
use App\Enums\Market;
use App\Enums\Side;
use App\Modeling\Math\Poisson;

/**
 * Prices hockey off two Poisson scoring rates.
 *
 * Goals arrive at a roughly constant rate and rarely cluster, so a rate per
 * team is enough. One wrinkle matters: regulation ties are not a push on an
 * NHL moneyline, because the game is decided in overtime or a shootout. The
 * tie mass is therefore split rather than returned as a push, and slightly
 * toward the better team, since the stronger side wins more coin flips than
 * it loses.
 */
class PoissonPricer implements MarketPricer
{
    /** How much of the overtime split the better team takes, per goal of edge. */
    private const OVERTIME_EDGE = 0.08;

    public function key(): string
    {
        return 'poisson';
    }

    /** @return array<int, Market> */
    public function supports(): array
    {
        return [Market::Moneyline, Market::Total];
    }

    public function price(GameProjection $projection, Market $market, ?float $line = null): MarketPrices
    {
        return match ($market) {
            Market::Moneyline => $this->moneyline($projection),
            Market::Total => $this->total($projection, $line),
            Market::Spread => $this->puckLine($projection, $line ?? -1.5),
        };
    }

    private function moneyline(GameProjection $projection): MarketPrices
    {
        $outcome = Poisson::matchOutcome($projection->homeScore, $projection->awayScore);

        // Regulation ties go to overtime, so the draw mass is distributed,
        // not refunded. Tilt it toward the stronger team, capped so a large
        // edge cannot claim the whole tie.
        $edge = $projection->homeScore - $projection->awayScore;
        $homeShare = min(0.65, max(0.35, 0.5 + ($edge * self::OVERTIME_EDGE)));

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
        $line ??= round(($projection->total()) * 2) / 2;

        $total = Poisson::totalProbabilities($projection->homeScore, $projection->awayScore, $line);

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

    /**
     * The puck line is almost always -1.5, so it is priced by enumerating
     * the goal difference rather than by a normal approximation that would
     * be poor at these small counts.
     */
    private function puckLine(GameProjection $projection, float $line): MarketPrices
    {
        $homeCovers = 0.0;
        $awayCovers = 0.0;
        $push = 0.0;
        $mass = 0.0;

        for ($h = 0; $h <= 15; $h++) {
            $pHome = Poisson::pmf($h, $projection->homeScore);

            for ($a = 0; $a <= 15; $a++) {
                $joint = $pHome * Poisson::pmf($a, $projection->awayScore);
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
}
