<?php

declare(strict_types=1);

namespace App\Modeling\Math;

use InvalidArgumentException;

/**
 * Isotonic calibration by pool adjacent violators.
 *
 * A model can rank games correctly and still state the wrong numbers: say 70
 * percent on a group that wins 62 percent of the time and every stake built
 * on it is too large. Calibration fixes the numbers without touching the
 * ordering.
 *
 * Isotonic assumes only that higher predictions should mean higher observed
 * rates, which is weaker than the S-shape Platt assumes, so it corrects
 * stranger distortions. The cost is that it needs more data and will happily
 * memorise noise on a small sample, which is why the trainer falls back to
 * Platt below a threshold.
 */
final class IsotonicRegression
{
    /** @var array<int, array{x:float, y:float}> */
    private array $steps = [];

    private bool $fitted = false;

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

        $points = [];

        foreach ($predictions as $i => $prediction) {
            $points[] = ['x' => $prediction, 'y' => (float) $outcomes[$i], 'w' => 1.0];
        }

        usort($points, static fn (array $a, array $b) => $a['x'] <=> $b['x']);

        // Pool adjacent violators: walk forward, and whenever a block has a
        // lower mean than the block before it, merge the two. Repeating this
        // leaves a non-decreasing step function, which is the closest
        // monotone fit to what actually happened.
        $blocks = [];

        foreach ($points as $point) {
            $blocks[] = ['sum' => $point['y'] * $point['w'], 'weight' => $point['w'], 'x' => $point['x']];

            while (count($blocks) > 1) {
                $last = count($blocks) - 1;
                $previousMean = $blocks[$last - 1]['sum'] / $blocks[$last - 1]['weight'];
                $currentMean = $blocks[$last]['sum'] / $blocks[$last]['weight'];

                if ($previousMean <= $currentMean) {
                    break;
                }

                $blocks[$last - 1]['sum'] += $blocks[$last]['sum'];
                $blocks[$last - 1]['weight'] += $blocks[$last]['weight'];
                $blocks[$last - 1]['x'] = $blocks[$last]['x'];
                array_pop($blocks);
            }
        }

        $this->steps = array_map(
            static fn (array $block) => ['x' => $block['x'], 'y' => $block['sum'] / $block['weight']],
            $blocks,
        );

        $this->fitted = true;

        return $this;
    }

    /**
     * Calibrated probability, interpolated between steps.
     *
     * Results are nudged inside (0, 1): a calibration map built from a finite
     * sample will contain blocks that went 0 for 8 or 8 for 8, and taking
     * those literally would claim certainty the data cannot support, as well
     * as producing an infinite log loss the moment one of them is wrong.
     */
    public function calibrate(float $probability): float
    {
        if (! $this->fitted) {
            throw new InvalidArgumentException('Calibration map has not been fitted.');
        }

        $count = count($this->steps);

        if ($count === 1) {
            return $this->bound($this->steps[0]['y']);
        }

        if ($probability <= $this->steps[0]['x']) {
            return $this->bound($this->steps[0]['y']);
        }

        if ($probability >= $this->steps[$count - 1]['x']) {
            return $this->bound($this->steps[$count - 1]['y']);
        }

        for ($i = 0; $i < $count - 1; $i++) {
            $low = $this->steps[$i];
            $high = $this->steps[$i + 1];

            if ($probability >= $low['x'] && $probability <= $high['x']) {
                $span = $high['x'] - $low['x'];

                if ($span <= 1e-12) {
                    return $this->bound($high['y']);
                }

                $ratio = ($probability - $low['x']) / $span;

                return $this->bound($low['y'] + $ratio * ($high['y'] - $low['y']));
            }
        }

        return $this->bound($this->steps[$count - 1]['y']);
    }

    /** @return array<int, array{x:float, y:float}> */
    public function map(): array
    {
        return $this->steps;
    }

    /** @param  array<int, array{x:float|string, y:float|string}>  $steps */
    public static function fromMap(array $steps): self
    {
        $model = new self;
        $model->steps = array_map(
            static fn (array $step) => ['x' => (float) $step['x'], 'y' => (float) $step['y']],
            $steps,
        );
        $model->fitted = $model->steps !== [];

        return $model;
    }

    private function bound(float $value): float
    {
        return min(0.999, max(0.001, $value));
    }
}
