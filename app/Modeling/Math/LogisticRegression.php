<?php

declare(strict_types=1);

namespace App\Modeling\Math;

use InvalidArgumentException;

/**
 * Binary logistic regression trained by gradient descent with L2.
 *
 * Written out rather than pulled in, partly because Laravel Cloud runs PHP
 * only, and partly because a model you can read line by line is a model you
 * can argue with.
 *
 * The regularization is not decoration. With a few thousand games and a
 * dozen correlated features - rest, travel, pace, form - an unregularized
 * fit will happily assign a huge weight to whatever noise separated last
 * season, and the backtest will applaud. L2 keeps weights small unless the
 * data really insists.
 */
final class LogisticRegression
{
    /** @var array<int, float> */
    private array $weights = [];

    private float $bias = 0.0;

    /** @var array<int, float> */
    private array $featureMeans = [];

    /** @var array<int, float> */
    private array $featureStdDevs = [];

    private bool $trained = false;

    public function __construct(
        private readonly float $learningRate = 0.1,
        private readonly int $maxIterations = 2000,
        private readonly float $l2 = 1.0,
        private readonly float $tolerance = 1e-7,
        private readonly bool $standardize = true,
    ) {
        if ($learningRate <= 0.0) {
            throw new InvalidArgumentException('Learning rate must be positive.');
        }

        if ($l2 < 0.0) {
            throw new InvalidArgumentException('L2 strength cannot be negative.');
        }
    }

    /**
     * @param  array<int, array<int, float>>  $samples
     * @param  array<int, int>  $labels  1 or 0
     * @param  array<int, float>|null  $sampleWeights
     * @return array{iterations:int, loss:float, converged:bool}
     */
    public function fit(array $samples, array $labels, ?array $sampleWeights = null): array
    {
        if ($samples === []) {
            throw new InvalidArgumentException('Cannot fit on an empty sample.');
        }

        if (count($samples) !== count($labels)) {
            throw new InvalidArgumentException('Samples and labels must be the same length.');
        }

        $featureCount = count($samples[0]);
        $rowCount = count($samples);
        $sampleWeights ??= array_fill(0, $rowCount, 1.0);
        $weightTotal = array_sum($sampleWeights);

        if ($this->standardize) {
            $this->computeScaling($samples, $featureCount);
        }

        $samples = array_map(fn (array $row) => $this->scale($row), $samples);

        $this->weights = array_fill(0, $featureCount, 0.0);
        $this->bias = 0.0;

        $previousLoss = INF;
        $loss = 0.0;
        $converged = false;
        $iteration = 0;

        for ($iteration = 1; $iteration <= $this->maxIterations; $iteration++) {
            $gradients = array_fill(0, $featureCount, 0.0);
            $biasGradient = 0.0;
            $loss = 0.0;

            foreach ($samples as $i => $row) {
                $prediction = $this->sigmoid($this->rawScore($row));
                $error = $prediction - $labels[$i];
                $rowWeight = $sampleWeights[$i];

                for ($f = 0; $f < $featureCount; $f++) {
                    $gradients[$f] += $rowWeight * $error * $row[$f];
                }

                $biasGradient += $rowWeight * $error;

                $clamped = min(1 - 1e-15, max(1e-15, $prediction));
                $loss += $rowWeight * ($labels[$i] === 1 ? -log($clamped) : -log(1 - $clamped));
            }

            $loss /= $weightTotal;

            for ($f = 0; $f < $featureCount; $f++) {
                $penalty = $this->l2 * $this->weights[$f];
                $this->weights[$f] -= $this->learningRate * (($gradients[$f] + $penalty) / $weightTotal);
                $loss += ($this->l2 * $this->weights[$f] ** 2) / (2 * $weightTotal);
            }

            // L2 never touches the bias: shrinking the intercept would just
            // drag every prediction toward fifty percent.
            $this->bias -= $this->learningRate * ($biasGradient / $weightTotal);

            if (abs($previousLoss - $loss) < $this->tolerance) {
                $converged = true;
                break;
            }

            $previousLoss = $loss;
        }

        $this->trained = true;

        return ['iterations' => $iteration, 'loss' => $loss, 'converged' => $converged];
    }

    /** @param  array<int, float>  $features */
    public function predict(array $features): float
    {
        if (! $this->trained) {
            throw new InvalidArgumentException('Model has not been fitted.');
        }

        return $this->sigmoid($this->rawScore($this->scale($features)));
    }

    public function sigmoid(float $z): float
    {
        // Split by sign so exp() cannot overflow on a large magnitude.
        if ($z >= 0) {
            return 1.0 / (1.0 + exp(-$z));
        }

        $e = exp($z);

        return $e / (1.0 + $e);
    }

    /**
     * Per-feature contribution to one prediction, in log odds. This is what
     * the game detail screen shows as the reasons behind a number, and it is
     * the reason the model stays linear: a number you cannot explain is a
     * number you cannot sanity check.
     *
     * @param  array<int, float>  $features
     * @return array<int, float>
     */
    public function contributions(array $features): array
    {
        $scaled = $this->scale($features);
        $contributions = [];

        foreach ($this->weights as $index => $weight) {
            $contributions[$index] = $weight * ($scaled[$index] ?? 0.0);
        }

        return $contributions;
    }

    /** @return array<int, float> */
    public function weights(): array
    {
        return $this->weights;
    }

    public function bias(): float
    {
        return $this->bias;
    }

    /** @return array<string, mixed> */
    public function params(): array
    {
        return [
            'weights' => $this->weights,
            'bias' => $this->bias,
            'means' => $this->featureMeans,
            'std_devs' => $this->featureStdDevs,
            'l2' => $this->l2,
            'standardize' => $this->standardize,
        ];
    }

    /** @param  array<string, mixed>  $params */
    public static function fromParams(array $params): self
    {
        $model = new self(
            l2: (float) ($params['l2'] ?? 1.0),
            standardize: (bool) ($params['standardize'] ?? true),
        );

        $model->weights = array_map('floatval', (array) ($params['weights'] ?? []));
        $model->bias = (float) ($params['bias'] ?? 0.0);
        $model->featureMeans = array_map('floatval', (array) ($params['means'] ?? []));
        $model->featureStdDevs = array_map('floatval', (array) ($params['std_devs'] ?? []));
        $model->trained = true;

        return $model;
    }

    /** @param  array<int, float>  $row */
    private function rawScore(array $row): float
    {
        $score = $this->bias;

        foreach ($this->weights as $index => $weight) {
            $score += $weight * ($row[$index] ?? 0.0);
        }

        return $score;
    }

    /**
     * Standardising matters here. Rest days run 0 to 14 and travel distance
     * runs 0 to 3000; without scaling the larger column dominates the
     * gradient and the smaller one never gets learned at all.
     *
     * @param  array<int, array<int, float>>  $samples
     */
    private function computeScaling(array $samples, int $featureCount): void
    {
        $rowCount = count($samples);
        $this->featureMeans = array_fill(0, $featureCount, 0.0);
        $this->featureStdDevs = array_fill(0, $featureCount, 1.0);

        for ($f = 0; $f < $featureCount; $f++) {
            $sum = 0.0;

            foreach ($samples as $row) {
                $sum += $row[$f];
            }

            $mean = $sum / $rowCount;
            $variance = 0.0;

            foreach ($samples as $row) {
                $variance += ($row[$f] - $mean) ** 2;
            }

            $sd = sqrt($variance / $rowCount);

            $this->featureMeans[$f] = $mean;
            // A constant column has no spread. Leave the divisor at 1 so
            // scaling becomes a no-op instead of a division by zero.
            $this->featureStdDevs[$f] = $sd > 1e-12 ? $sd : 1.0;
        }
    }

    /**
     * @param  array<int, float>  $row
     * @return array<int, float>
     */
    private function scale(array $row): array
    {
        if (! $this->standardize || $this->featureMeans === []) {
            return $row;
        }

        $scaled = [];

        foreach ($row as $index => $value) {
            $scaled[$index] = ($value - ($this->featureMeans[$index] ?? 0.0))
                / ($this->featureStdDevs[$index] ?? 1.0);
        }

        return $scaled;
    }
}
