<?php

declare(strict_types=1);

namespace App\Modeling\Math;

use InvalidArgumentException;

/**
 * Poisson counts, for goals.
 *
 * Hockey scoring sits close to Poisson: goals arrive at a roughly constant
 * rate and rarely cluster. That makes a single rate per team enough to price
 * a total, and the difference of two Poissons enough to price a moneyline.
 *
 * Everything is computed in log space and exponentiated at the end, because
 * lambda^k and k! both overflow long before the probability does.
 */
final class Poisson
{
    public static function pmf(int $k, float $lambda): float
    {
        self::assertLambda($lambda);

        if ($k < 0) {
            return 0.0;
        }

        return exp(($k * log($lambda)) - $lambda - self::logFactorial($k));
    }

    /** P(X <= k). */
    public static function cdf(int $k, float $lambda): float
    {
        if ($k < 0) {
            return 0.0;
        }

        $sum = 0.0;

        for ($i = 0; $i <= $k; $i++) {
            $sum += self::pmf($i, $lambda);
        }

        return min(1.0, $sum);
    }

    /** P(X > k), which is what an over bet needs. */
    public static function survival(int $k, float $lambda): float
    {
        return max(0.0, 1.0 - self::cdf($k, $lambda));
    }

    /**
     * Win, draw and loss probabilities for two independent Poisson scorers.
     *
     * @return array{home:float, draw:float, away:float}
     */
    public static function matchOutcome(float $homeLambda, float $awayLambda, int $maxGoals = 15): array
    {
        self::assertLambda($homeLambda);
        self::assertLambda($awayLambda);

        $home = 0.0;
        $draw = 0.0;
        $away = 0.0;

        for ($h = 0; $h <= $maxGoals; $h++) {
            $pHome = self::pmf($h, $homeLambda);

            for ($a = 0; $a <= $maxGoals; $a++) {
                $joint = $pHome * self::pmf($a, $awayLambda);

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

        // The tail beyond maxGoals is tiny but real; normalise so the three
        // numbers are a distribution rather than nearly one.
        return [
            'home' => $home / $total,
            'draw' => $draw / $total,
            'away' => $away / $total,
        ];
    }

    /**
     * P(total goals > line) for a half-point line, and the push probability
     * when the line is a whole number.
     *
     * @return array{over:float, under:float, push:float}
     */
    public static function totalProbabilities(float $homeLambda, float $awayLambda, float $line, int $maxGoals = 30): array
    {
        // The sum of independent Poissons is Poisson with the summed rate.
        $lambda = $homeLambda + $awayLambda;
        self::assertLambda($lambda);

        $push = 0.0;

        if (floor($line) === $line) {
            $push = self::pmf((int) $line, $lambda);
        }

        $under = self::cdf((int) floor($line), $lambda) - $push;
        $over = max(0.0, 1.0 - $under - $push);

        return ['over' => $over, 'under' => $under, 'push' => $push];
    }

    /** log(k!) by summation; exact for the small counts sport produces. */
    public static function logFactorial(int $k): float
    {
        if ($k < 0) {
            throw new InvalidArgumentException("Factorial is undefined for {$k}.");
        }

        $sum = 0.0;

        for ($i = 2; $i <= $k; $i++) {
            $sum += log($i);
        }

        return $sum;
    }

    private static function assertLambda(float $lambda): void
    {
        if ($lambda <= 0.0 || ! is_finite($lambda)) {
            throw new InvalidArgumentException("Poisson rate must be positive and finite, got {$lambda}.");
        }
    }
}
