<?php

declare(strict_types=1);

namespace App\Modeling\Math;

use InvalidArgumentException;

/**
 * How a set of probabilities is scored.
 *
 * Accuracy is the wrong measure for betting. A model that says 95% on every
 * home favourite and is right 80% of the time looks accurate and loses money.
 * Log loss and Brier score punish confident wrongness, which is the failure
 * that actually costs you, and calibration says whether a stated 60% really
 * happens 60 percent of the time.
 */
final class Metrics
{
    /** Clamped before any log, so one certain miss cannot return infinity. */
    private const EPSILON = 1e-15;

    /**
     * Mean negative log likelihood. Lower is better. This is the headline
     * number: the market log loss is the bar a model has to clear.
     *
     * @param  array<int, float>  $probabilities
     * @param  array<int, int>  $outcomes  1 for the event happening, 0 for not
     */
    public static function logLoss(array $probabilities, array $outcomes): float
    {
        self::assertAligned($probabilities, $outcomes);

        $sum = 0.0;

        foreach ($probabilities as $i => $p) {
            $p = self::clamp($p);
            $sum += $outcomes[$i] === 1 ? -log($p) : -log(1.0 - $p);
        }

        return $sum / count($probabilities);
    }

    /**
     * Mean squared error of the probability. Lower is better; 0.25 is the
     * score of always saying fifty percent.
     *
     * @param  array<int, float>  $probabilities
     * @param  array<int, int>  $outcomes
     */
    public static function brier(array $probabilities, array $outcomes): float
    {
        self::assertAligned($probabilities, $outcomes);

        $sum = 0.0;

        foreach ($probabilities as $i => $p) {
            $sum += ($p - $outcomes[$i]) ** 2;
        }

        return $sum / count($probabilities);
    }

    /**
     * Brier skill against a baseline, usually the market. Positive beats the
     * baseline, zero ties it, negative loses to it.
     *
     * @param  array<int, float>  $probabilities
     * @param  array<int, float>  $baseline
     * @param  array<int, int>  $outcomes
     */
    public static function brierSkillScore(array $probabilities, array $baseline, array $outcomes): float
    {
        $model = self::brier($probabilities, $outcomes);
        $reference = self::brier($baseline, $outcomes);

        if ($reference <= 0.0) {
            return 0.0;
        }

        return 1.0 - ($model / $reference);
    }

    /**
     * Observed rate against predicted rate, in buckets. This is what a
     * calibration plot draws, and what exposes a model that is directionally
     * right and systematically overconfident.
     *
     * @param  array<int, float>  $probabilities
     * @param  array<int, int>  $outcomes
     * @return array<int, array{bin:int, low:float, high:float, n:int, mean_predicted:float, observed_rate:float}>
     */
    public static function calibrationCurve(array $probabilities, array $outcomes, int $bins = 10): array
    {
        self::assertAligned($probabilities, $outcomes);

        if ($bins < 2) {
            throw new InvalidArgumentException('A calibration curve needs at least two bins.');
        }

        $buckets = array_fill(0, $bins, ['sum' => 0.0, 'hits' => 0, 'n' => 0]);

        foreach ($probabilities as $i => $p) {
            $index = min($bins - 1, (int) floor($p * $bins));
            $buckets[$index]['sum'] += $p;
            $buckets[$index]['hits'] += $outcomes[$i];
            $buckets[$index]['n']++;
        }

        $curve = [];

        foreach ($buckets as $index => $bucket) {
            if ($bucket['n'] === 0) {
                continue;
            }

            $curve[] = [
                'bin' => $index,
                'low' => $index / $bins,
                'high' => ($index + 1) / $bins,
                'n' => $bucket['n'],
                'mean_predicted' => $bucket['sum'] / $bucket['n'],
                'observed_rate' => $bucket['hits'] / $bucket['n'],
            ];
        }

        return $curve;
    }

    /**
     * Expected calibration error: the average gap between what was said and
     * what happened, weighted by how often each was said.
     *
     * @param  array<int, float>  $probabilities
     * @param  array<int, int>  $outcomes
     */
    public static function expectedCalibrationError(array $probabilities, array $outcomes, int $bins = 10): float
    {
        $curve = self::calibrationCurve($probabilities, $outcomes, $bins);
        $total = count($probabilities);
        $error = 0.0;

        foreach ($curve as $bucket) {
            $error += ($bucket['n'] / $total) * abs($bucket['mean_predicted'] - $bucket['observed_rate']);
        }

        return $error;
    }

    /**
     * Area under the ROC curve, from average ranks so ties are handled.
     *
     * Measures ordering only. A model can have excellent AUC and be badly
     * calibrated, which is why this is never the deciding number.
     *
     * @param  array<int, float>  $probabilities
     * @param  array<int, int>  $outcomes
     */
    public static function auc(array $probabilities, array $outcomes): float
    {
        self::assertAligned($probabilities, $outcomes);

        $positives = array_sum($outcomes);
        $negatives = count($outcomes) - $positives;

        if ($positives === 0 || $negatives === 0) {
            return 0.5;
        }

        $ranks = self::averageRanks($probabilities);
        $positiveRankSum = 0.0;

        foreach ($outcomes as $i => $outcome) {
            if ($outcome === 1) {
                $positiveRankSum += $ranks[$i];
            }
        }

        return ($positiveRankSum - ($positives * ($positives + 1) / 2)) / ($positives * $negatives);
    }

    /**
     * Flat stake return per unit staked.
     *
     * @param  array<int, int>  $outcomes
     * @param  array<int, float>  $decimalOdds
     */
    public static function flatRoi(array $outcomes, array $decimalOdds): float
    {
        if (count($outcomes) !== count($decimalOdds)) {
            throw new InvalidArgumentException('Outcomes and odds must be the same length.');
        }

        if ($outcomes === []) {
            return 0.0;
        }

        $profit = 0.0;

        foreach ($outcomes as $i => $outcome) {
            $profit += $outcome === 1 ? $decimalOdds[$i] - 1.0 : -1.0;
        }

        return $profit / count($outcomes);
    }

    private static function clamp(float $p): float
    {
        return min(1.0 - self::EPSILON, max(self::EPSILON, $p));
    }

    /**
     * @param  array<int, float>  $values
     * @return array<int, float>
     */
    private static function averageRanks(array $values): array
    {
        $indexed = [];

        foreach ($values as $i => $value) {
            $indexed[] = ['i' => $i, 'v' => $value];
        }

        usort($indexed, static fn (array $a, array $b) => $a['v'] <=> $b['v']);

        $ranks = [];
        $count = count($indexed);
        $position = 0;

        while ($position < $count) {
            $tieEnd = $position;

            while ($tieEnd + 1 < $count && $indexed[$tieEnd + 1]['v'] === $indexed[$position]['v']) {
                $tieEnd++;
            }

            $averageRank = (($position + 1) + ($tieEnd + 1)) / 2;

            for ($i = $position; $i <= $tieEnd; $i++) {
                $ranks[$indexed[$i]['i']] = $averageRank;
            }

            $position = $tieEnd + 1;
        }

        ksort($ranks);

        return $ranks;
    }

    /**
     * @param  array<int, float>  $probabilities
     * @param  array<int, int>  $outcomes
     */
    private static function assertAligned(array $probabilities, array $outcomes): void
    {
        if (count($probabilities) !== count($outcomes)) {
            throw new InvalidArgumentException('Probabilities and outcomes must be the same length.');
        }

        if ($probabilities === []) {
            throw new InvalidArgumentException('Cannot score an empty set of predictions.');
        }
    }
}
