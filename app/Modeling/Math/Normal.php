<?php

declare(strict_types=1);

namespace App\Modeling\Math;

use InvalidArgumentException;

/**
 * The normal distribution, written out rather than pulled in.
 *
 * Margins in football and basketball are close enough to normal that a mean
 * and a standard deviation turn a projected margin into a win probability
 * and a spread cover probability. The accuracy of the erf approximation
 * below is the floor on how accurate every one of those numbers can be, so
 * it is the high-precision form, not the quick one.
 */
final class Normal
{
    /**
     * Abramowitz and Stegun 7.1.26 gives about 1e-7 absolute error, which is
     * far finer than any sports model deserves but costs nothing.
     */
    public static function erf(float $x): float
    {
        $sign = $x < 0 ? -1.0 : 1.0;
        $x = abs($x);

        $a1 = 0.254829592;
        $a2 = -0.284496736;
        $a3 = 1.421413741;
        $a4 = -1.453152027;
        $a5 = 1.061405429;
        $p = 0.3275911;

        $t = 1.0 / (1.0 + $p * $x);
        $y = 1.0 - ((((($a5 * $t + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);

        return $sign * $y;
    }

    /** P(X <= x) for X ~ Normal(mean, sd). */
    public static function cdf(float $x, float $mean = 0.0, float $sd = 1.0): float
    {
        self::assertSd($sd);

        return 0.5 * (1.0 + self::erf(($x - $mean) / ($sd * M_SQRT2)));
    }

    public static function pdf(float $x, float $mean = 0.0, float $sd = 1.0): float
    {
        self::assertSd($sd);

        $z = ($x - $mean) / $sd;

        return exp(-0.5 * $z * $z) / ($sd * sqrt(2 * M_PI));
    }

    /**
     * Inverse CDF via Acklam's rational approximation, refined once with a
     * Halley step. Used to turn a probability back into a margin, which is
     * how a model probability becomes a projected spread.
     */
    public static function inverseCdf(float $p, float $mean = 0.0, float $sd = 1.0): float
    {
        if ($p <= 0.0 || $p >= 1.0) {
            throw new InvalidArgumentException("Probability must be strictly between 0 and 1, got {$p}.");
        }

        self::assertSd($sd);

        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02,
            1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02,
            6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00,
            -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00,
            3.754408661907416e+00];

        $pLow = 0.02425;
        $pHigh = 1 - $pLow;

        if ($p < $pLow) {
            $q = sqrt(-2 * log($p));
            $z = ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) /
                (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        } elseif ($p <= $pHigh) {
            $q = $p - 0.5;
            $r = $q * $q;
            $z = ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q /
                ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
        } else {
            $q = sqrt(-2 * log(1 - $p));
            $z = -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) /
                (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }

        // One Halley refinement: cheap, and takes the error to machine noise.
        $e = self::cdf($z) - $p;
        $u = $e * sqrt(2 * M_PI) * exp($z * $z / 2);
        $z -= $u / (1 + $z * $u / 2);

        return $mean + $sd * $z;
    }

    /** P(X > x), the form a win probability usually wants. */
    public static function survival(float $x, float $mean = 0.0, float $sd = 1.0): float
    {
        return 1.0 - self::cdf($x, $mean, $sd);
    }

    private static function assertSd(float $sd): void
    {
        if ($sd <= 0.0) {
            throw new InvalidArgumentException("Standard deviation must be positive, got {$sd}.");
        }
    }
}
