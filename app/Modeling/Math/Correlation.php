<?php

declare(strict_types=1);

namespace App\Modeling\Math;

use InvalidArgumentException;

/**
 * Pearson correlation and the slope of a straight line through two series,
 * with the standard error that says whether either is worth reading.
 *
 * A correlation on its own invites the wrong conclusion twice over. On a
 * small sample it is large by accident, and on a large one it is
 * significant while being far too small to matter. So nothing here returns
 * a coefficient alone: `summarise()` carries the sample size, the slope in
 * the units of the thing being measured, and a t statistic, because the
 * question is never "is there a relationship" but "is it big enough to bet
 * on".
 */
final class Correlation
{
    /**
     * @param  array<int, float>  $x
     * @param  array<int, float>  $y
     */
    public static function pearson(array $x, array $y): float
    {
        self::assertPaired($x, $y);

        $n = count($x);
        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $covariance = 0.0;
        $varianceX = 0.0;
        $varianceY = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;

            $covariance += $dx * $dy;
            $varianceX += $dx * $dx;
            $varianceY += $dy * $dy;
        }

        if ($varianceX <= 0.0 || $varianceY <= 0.0) {
            // One series never moves, so there is nothing to correlate.
            return 0.0;
        }

        return $covariance / sqrt($varianceX * $varianceY);
    }

    /**
     * Least-squares slope of y on x, in y-units per x-unit.
     *
     * This is the number worth quoting. "Wind correlates 0.11 with the
     * total" means nothing anyone can act on; "every 10 mph of wind takes
     * 1.4 points off the total" is a statement you can check against a
     * price.
     *
     * @param  array<int, float>  $x
     * @param  array<int, float>  $y
     */
    public static function slope(array $x, array $y): float
    {
        self::assertPaired($x, $y);

        $n = count($x);
        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $covariance = 0.0;
        $varianceX = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;

            $covariance += $dx * ($y[$i] - $meanY);
            $varianceX += $dx * $dx;
        }

        return $varianceX <= 0.0 ? 0.0 : $covariance / $varianceX;
    }

    /**
     * Correlation, slope, sample size and a t statistic together.
     *
     * The t statistic is the one for a correlation coefficient,
     * r * sqrt((n - 2) / (1 - r^2)), on n - 2 degrees of freedom. Roughly:
     * above about 2 is worth a second look, below it is noise. It is
     * reported rather than compared against a threshold here, because what
     * counts as convincing depends on how many of these questions were
     * asked, and that is the caller's business to know.
     *
     * @param  array<int, float>  $x
     * @param  array<int, float>  $y
     * @return array{n: int, r: float, slope: float, t: float}
     */
    public static function summarise(array $x, array $y): array
    {
        self::assertPaired($x, $y);

        $n = count($x);
        $r = self::pearson($x, $y);

        $t = 0.0;

        if ($n > 2 && abs($r) < 1.0) {
            $t = $r * sqrt(($n - 2) / (1.0 - ($r * $r)));
        }

        return [
            'n' => $n,
            'r' => $r,
            'slope' => self::slope($x, $y),
            't' => $t,
        ];
    }

    /**
     * @param  array<int, float>  $x
     * @param  array<int, float>  $y
     */
    private static function assertPaired(array &$x, array &$y): void
    {
        // Re-indexed here rather than trusted from the caller. These series
        // nearly always arrive from a filter, which leaves gaps in the
        // keys, and a positional loop over a gapped array reads past the
        // end. Taken by reference so one place fixes it for every method.
        $x = array_values($x);
        $y = array_values($y);

        if (count($x) !== count($y)) {
            throw new InvalidArgumentException(
                'Correlation needs paired series; got '.count($x).' and '.count($y).'.'
            );
        }

        if (count($x) < 3) {
            throw new InvalidArgumentException('Correlation needs at least three pairs.');
        }
    }
}
