<?php

declare(strict_types=1);

namespace App\Modeling\Math;

use InvalidArgumentException;

/**
 * Negative binomial counts, for runs.
 *
 * Baseball scoring is overdispersed: the variance of runs per game is well
 * above the mean, because innings are not independent trials and a big
 * inning is a real thing. Poisson assumes variance equals mean and therefore
 * prices a 12-run game as nearly impossible, which is how a totals model
 * quietly bleeds money on overs.
 *
 * Parameterised by mean and a dispersion r, where variance = mean + mean^2/r.
 * Large r approaches Poisson.
 */
final class NegativeBinomial
{
    public static function pmf(int $k, float $mean, float $dispersion): float
    {
        self::assertParams($mean, $dispersion);

        if ($k < 0) {
            return 0.0;
        }

        $r = $dispersion;
        $p = $r / ($r + $mean);

        $logPmf = self::logGamma($k + $r)
            - self::logGamma($r)
            - Poisson::logFactorial($k)
            + $r * log($p)
            + $k * log(1.0 - $p);

        return exp($logPmf);
    }

    public static function cdf(int $k, float $mean, float $dispersion): float
    {
        if ($k < 0) {
            return 0.0;
        }

        $sum = 0.0;

        for ($i = 0; $i <= $k; $i++) {
            $sum += self::pmf($i, $mean, $dispersion);
        }

        return min(1.0, $sum);
    }

    public static function survival(int $k, float $mean, float $dispersion): float
    {
        return max(0.0, 1.0 - self::cdf($k, $mean, $dispersion));
    }

    /**
     * Dispersion implied by an observed mean and variance. Returns null when
     * the sample is not overdispersed, which is the caller's signal to use
     * Poisson instead of forcing a parameter that does not exist.
     */
    public static function dispersionFrom(float $mean, float $variance): ?float
    {
        if ($mean <= 0.0 || $variance <= $mean) {
            return null;
        }

        return ($mean * $mean) / ($variance - $mean);
    }

    /**
     * @return array{home:float, draw:float, away:float}
     */
    public static function matchOutcome(
        float $homeMean,
        float $awayMean,
        float $dispersion,
        int $maxRuns = 25,
    ): array {
        $home = 0.0;
        $draw = 0.0;
        $away = 0.0;

        for ($h = 0; $h <= $maxRuns; $h++) {
            $pHome = self::pmf($h, $homeMean, $dispersion);

            for ($a = 0; $a <= $maxRuns; $a++) {
                $joint = $pHome * self::pmf($a, $awayMean, $dispersion);

                if ($h > $a) {
                    $home += $joint;
                } elseif ($h === $a) {
                    $draw += $joint;
                } else {
                    $away += $joint;
                }
            }
        }

        $total = $home + $draw + $away;

        return ['home' => $home / $total, 'draw' => $draw / $total, 'away' => $away / $total];
    }

    /**
     * Totals, summed by convolution rather than by adding the parameters:
     * two negative binomials do not sum to one unless they share p.
     *
     * @return array{over:float, under:float, push:float}
     */
    public static function totalProbabilities(
        float $homeMean,
        float $awayMean,
        float $dispersion,
        float $line,
        int $maxRuns = 30,
    ): array {
        $totalPmf = array_fill(0, $maxRuns * 2 + 1, 0.0);

        for ($h = 0; $h <= $maxRuns; $h++) {
            $pHome = self::pmf($h, $homeMean, $dispersion);

            if ($pHome < 1e-12) {
                continue;
            }

            for ($a = 0; $a <= $maxRuns; $a++) {
                $totalPmf[$h + $a] += $pHome * self::pmf($a, $awayMean, $dispersion);
            }
        }

        $mass = array_sum($totalPmf);
        $push = 0.0;
        $under = 0.0;

        foreach ($totalPmf as $runs => $probability) {
            $probability /= $mass;

            if ((float) $runs < $line) {
                $under += $probability;
            } elseif ((float) $runs === $line) {
                $push += $probability;
            }
        }

        return ['over' => max(0.0, 1.0 - $under - $push), 'under' => $under, 'push' => $push];
    }

    /** Lanczos approximation; accurate to about 1e-13 for the range used here. */
    public static function logGamma(float $x): float
    {
        if ($x <= 0.0) {
            throw new InvalidArgumentException("logGamma requires a positive argument, got {$x}.");
        }

        $g = [
            676.5203681218851, -1259.1392167224028, 771.32342877765313,
            -176.61502916214059, 12.507343278686905, -0.13857109526572012,
            9.9843695780195716e-6, 1.5056327351493116e-7,
        ];

        if ($x < 0.5) {
            return log(M_PI / sin(M_PI * $x)) - self::logGamma(1.0 - $x);
        }

        $x -= 1.0;
        $a = 0.99999999999980993;
        $t = $x + 7.5;

        foreach ($g as $i => $coefficient) {
            $a += $coefficient / ($x + $i + 1);
        }

        return 0.5 * log(2 * M_PI) + ($x + 0.5) * log($t) - $t + log($a);
    }

    private static function assertParams(float $mean, float $dispersion): void
    {
        if ($mean <= 0.0 || $dispersion <= 0.0) {
            throw new InvalidArgumentException("Mean and dispersion must be positive, got {$mean} and {$dispersion}.");
        }
    }
}
