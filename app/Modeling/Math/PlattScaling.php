<?php

declare(strict_types=1);

namespace App\Modeling\Math;

use InvalidArgumentException;

/**
 * Platt scaling: a one-feature logistic regression fitted on the log odds of
 * the raw prediction.
 *
 * Two parameters instead of a step function, which is the point. On a few
 * hundred games isotonic will fit the noise and look perfect in sample;
 * Platt can only stretch or shift the S-curve, so it cannot invent detail
 * the data does not contain.
 *
 * a scales confidence: below 1 pulls predictions toward the middle, which is
 * the usual correction for an overconfident model. b shifts the whole curve,
 * correcting a model that leans one way.
 */
final class PlattScaling
{
    private float $a = 1.0;

    private float $b = 0.0;

    private bool $fitted = false;

    public function __construct(
        private readonly float $learningRate = 0.1,
        private readonly int $maxIterations = 2000,
        private readonly float $tolerance = 1e-9,
    ) {}

    /**
     * @param  array<int, float>  $predictions
     * @param  array<int, int>  $outcomes
     */
    public function fit(array $predictions, array $outcomes): self
    {
        if (count($predictions) !== count($outcomes)) {
            throw new InvalidArgumentException('Predictions and outcomes must be the same length.');
        }

        if ($predictions === []) {
            throw new InvalidArgumentException('Cannot calibrate on an empty sample.');
        }

        $logits = array_map([$this, 'logit'], $predictions);
        $n = count($logits);

        $this->a = 1.0;
        $this->b = 0.0;
        $previousLoss = INF;

        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            $gradientA = 0.0;
            $gradientB = 0.0;
            $loss = 0.0;

            foreach ($logits as $i => $logit) {
                $p = $this->sigmoid($this->a * $logit + $this->b);
                $error = $p - $outcomes[$i];

                $gradientA += $error * $logit;
                $gradientB += $error;

                $clamped = min(1 - 1e-15, max(1e-15, $p));
                $loss += $outcomes[$i] === 1 ? -log($clamped) : -log(1 - $clamped);
            }

            $this->a -= $this->learningRate * ($gradientA / $n);
            $this->b -= $this->learningRate * ($gradientB / $n);

            $loss /= $n;

            if (abs($previousLoss - $loss) < $this->tolerance) {
                break;
            }

            $previousLoss = $loss;
        }

        $this->fitted = true;

        return $this;
    }

    public function calibrate(float $probability): float
    {
        if (! $this->fitted) {
            throw new InvalidArgumentException('Calibration has not been fitted.');
        }

        return min(0.999, max(0.001, $this->sigmoid($this->a * $this->logit($probability) + $this->b)));
    }

    /** @return array{a:float, b:float} */
    public function params(): array
    {
        return ['a' => $this->a, 'b' => $this->b];
    }

    /** @param  array<string, mixed>  $params */
    public static function fromParams(array $params): self
    {
        $model = new self;
        $model->a = (float) ($params['a'] ?? 1.0);
        $model->b = (float) ($params['b'] ?? 0.0);
        $model->fitted = true;

        return $model;
    }

    private function logit(float $p): float
    {
        $p = min(1 - 1e-12, max(1e-12, $p));

        return log($p / (1 - $p));
    }

    private function sigmoid(float $z): float
    {
        if ($z >= 0) {
            return 1.0 / (1.0 + exp(-$z));
        }

        $e = exp($z);

        return $e / (1.0 + $e);
    }
}
