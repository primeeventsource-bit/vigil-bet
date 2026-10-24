<?php

declare(strict_types=1);

namespace App\Modeling\Predictors;

use App\Data\GameProjection;
use App\Data\MarketPrices;
use App\Enums\Market;
use App\Enums\Side;
use App\Modeling\Math\Normal;
use InvalidArgumentException;

/**
 * Prices football and basketball off a normal distribution over the margin.
 *
 * Margins in these sports are close enough to normal that a projected margin
 * and a standard deviation price all three markets consistently. The sigma
 * is the important parameter and it is per sport: roughly 13.5 points in the
 * NFL, under 12 in the NBA, and wider in college where mismatches are larger.
 *
 * Half-point lines cannot push. Whole-number lines can, and in the NFL the
 * mass sitting exactly on 3 and 7 is far too large to ignore - which is why
 * the push is computed rather than assumed away.
 */
class NormalMarginPricer implements MarketPricer
{
    public function key(): string
    {
        return 'margin_normal';
    }

    /** @return array<int, Market> */
    public function supports(): array
    {
        return [Market::Moneyline, Market::Spread, Market::Total];
    }

    public function price(GameProjection $projection, Market $market, ?float $line = null): MarketPrices
    {
        $sigma = $projection->marginSigma
            ?? throw new InvalidArgumentException('A normal margin projection needs a margin sigma.');

        if ($sigma <= 0.0) {
            throw new InvalidArgumentException("Margin sigma must be positive, got {$sigma}.");
        }

        return match ($market) {
            Market::Moneyline => $this->moneyline($projection, $sigma),
            Market::Spread => $this->spread($projection, $sigma, $line),
            Market::Total => $this->total($projection, $sigma, $line),
        };
    }

    private function moneyline(GameProjection $projection, float $sigma): MarketPrices
    {
        // A tie needs the margin to land exactly on zero. In basketball that
        // cannot happen; in the NFL it is rare but real, so the mass on zero
        // is treated as a push rather than handed to the favourite.
        $push = $this->massAt(0.0, $projection->margin(), $sigma);
        $home = Normal::survival(0.5, $projection->margin(), $sigma);
        $away = Normal::cdf(-0.5, $projection->margin(), $sigma);

        return new MarketPrices(
            market: Market::Moneyline,
            probabilities: [Side::Home->value => $home, Side::Away->value => $away],
            push: $push,
        );
    }

    /**
     * The home line is printed the way a book prints it: -3.5 means the home
     * team must win by four or more.
     */
    private function spread(GameProjection $projection, float $sigma, ?float $line): MarketPrices
    {
        $line ??= -round($projection->margin() * 2) / 2;

        // Home covers when margin + line > 0, so the threshold is -line.
        $threshold = -$line;
        $push = $this->massAt($threshold, $projection->margin(), $sigma);

        $home = Normal::survival($threshold + 0.5, $projection->margin(), $sigma);
        $away = Normal::cdf($threshold - 0.5, $projection->margin(), $sigma);

        if ($push <= 0.0) {
            // Half-point line: no push, so split the whole distribution.
            $home = Normal::survival($threshold, $projection->margin(), $sigma);
            $away = 1.0 - $home;
        }

        return new MarketPrices(
            market: Market::Spread,
            probabilities: [Side::Home->value => $home, Side::Away->value => $away],
            push: $push,
            line: $line,
        );
    }

    /**
     * Totals use a wider spread than margins: both teams scoring well and
     * both scoring badly are correlated in a way that margin cancels out.
     */
    private function total(GameProjection $projection, float $marginSigma, ?float $line): MarketPrices
    {
        $line ??= round($projection->total() * 2) / 2;
        $totalSigma = $marginSigma * 1.35;

        $push = $this->massAt($line, $projection->total(), $totalSigma);

        if ($push > 0.0) {
            $over = Normal::survival($line + 0.5, $projection->total(), $totalSigma);
            $under = Normal::cdf($line - 0.5, $projection->total(), $totalSigma);
        } else {
            $over = Normal::survival($line, $projection->total(), $totalSigma);
            $under = 1.0 - $over;
        }

        return new MarketPrices(
            market: Market::Total,
            probabilities: [Side::Over->value => $over, Side::Under->value => $under],
            push: $push,
            line: $line,
        );
    }

    /**
     * Probability the result lands exactly on a whole number, approximated
     * as the normal mass across that one point. Zero for half-point lines,
     * which cannot push.
     */
    private function massAt(float $value, float $mean, float $sigma): float
    {
        if (floor($value) !== $value) {
            return 0.0;
        }

        return Normal::cdf($value + 0.5, $mean, $sigma) - Normal::cdf($value - 0.5, $mean, $sigma);
    }
}
