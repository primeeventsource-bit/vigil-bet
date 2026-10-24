<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Chart arithmetic, kept out of the templates.
 *
 * Geometry that lives in a Blade file cannot be tested, and a chart that
 * plots the right numbers in the wrong place looks exactly like a working
 * one. Everything here is a pure function over numbers so a test can check
 * it without rendering anything.
 */
final class Plot
{
    /**
     * The value range to draw, padded out to a minimum span.
     *
     * An axis fitted exactly to the data turns a one-point drift into a
     * dramatic climb. A fixed full-scale axis does the opposite and
     * flattens a real move into a straight line. So the data range is
     * padded a tenth and then widened symmetrically until it covers at
     * least minSpan, and hitting the end of the scale shifts the window
     * rather than shrinking it, so the span survives the edge.
     *
     * @return array{float, float}
     */
    public static function niceDomain(
        float $low,
        float $high,
        float $minSpan,
        ?float $clampLow = null,
        ?float $clampHigh = null,
    ): array {
        $pad = ($high - $low) * 0.1;
        $low -= $pad;
        $high += $pad;

        if (($high - $low) < $minSpan) {
            $middle = ($low + $high) / 2;
            $low = $middle - ($minSpan / 2);
            $high = $middle + ($minSpan / 2);
        }

        if ($clampHigh !== null && $high > $clampHigh) {
            $low -= $high - $clampHigh;
            $high = $clampHigh;
        }

        if ($clampLow !== null && $low < $clampLow) {
            $high = min($clampHigh ?? INF, $high + ($clampLow - $low));
            $low = $clampLow;
        }

        return [$low, $high];
    }
}
