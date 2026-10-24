<?php

declare(strict_types=1);

use App\Modeling\Math\LogisticRegression;
use App\Modeling\Math\Metrics;

it('computes a numerically safe sigmoid', function () {
    $model = new LogisticRegression;

    expect($model->sigmoid(0.0))->toEqualWithDelta(0.5, 1e-12)
        ->and($model->sigmoid(1000.0))->toEqualWithDelta(1.0, 1e-12)
        // The naive form overflows here; this must not.
        ->and($model->sigmoid(-1000.0))->toEqualWithDelta(0.0, 1e-12)
        ->and(is_finite($model->sigmoid(-1000.0)))->toBeTrue();
});

it('learns a clearly separable boundary', function () {
    $samples = [];
    $labels = [];

    for ($i = 0; $i < 200; $i++) {
        $x = $i / 100 - 1.0;
        $samples[] = [$x];
        $labels[] = $x > 0 ? 1 : 0;
    }

    $model = new LogisticRegression(learningRate: 0.5, maxIterations: 3000, l2: 0.01);
    $model->fit($samples, $labels);

    expect($model->predict([1.0]))->toBeGreaterThan(0.8)
        ->and($model->predict([-1.0]))->toBeLessThan(0.2);
});

it('recovers a known relationship', function () {
    // Generated from a logistic model with a positive weight, so the fitted
    // weight must come out positive too.
    mt_srand(42);
    $samples = [];
    $labels = [];

    for ($i = 0; $i < 400; $i++) {
        $x = (mt_rand(0, 1000) / 100) - 5.0;
        $p = 1 / (1 + exp(-(0.8 * $x)));
        $samples[] = [$x];
        $labels[] = (mt_rand(0, 1000) / 1000) < $p ? 1 : 0;
    }

    $model = new LogisticRegression(learningRate: 0.5, maxIterations: 4000, l2: 0.01);
    $model->fit($samples, $labels);

    expect($model->weights()[0])->toBeGreaterThan(0.0)
        ->and($model->predict([4.0]))->toBeGreaterThan($model->predict([-4.0]));
});

it('reports convergence and a falling loss', function () {
    $samples = [[0.0], [1.0], [2.0], [3.0]];
    $labels = [0, 0, 1, 1];

    $result = (new LogisticRegression(learningRate: 0.3, maxIterations: 5000))->fit($samples, $labels);

    expect($result['converged'])->toBeTrue()
        ->and($result['loss'])->toBeLessThan(log(2))
        ->and($result['iterations'])->toBeLessThan(5000);
});

it('shrinks weights as L2 rises', function () {
    $samples = [];
    $labels = [];

    for ($i = 0; $i < 100; $i++) {
        $x = $i / 50 - 1.0;
        $samples[] = [$x];
        $labels[] = $x > 0 ? 1 : 0;
    }

    $light = new LogisticRegression(learningRate: 0.5, maxIterations: 2000, l2: 0.001);
    $heavy = new LogisticRegression(learningRate: 0.5, maxIterations: 2000, l2: 100.0);

    $light->fit($samples, $labels);
    $heavy->fit($samples, $labels);

    expect(abs($heavy->weights()[0]))->toBeLessThan(abs($light->weights()[0]));
});

it('standardises so a large column cannot drown a small one', function () {
    // Rest days 0 to 14 against travel miles 0 to 3000. The signal is in
    // rest; without scaling the travel column dominates the gradient.
    $samples = [];
    $labels = [];

    for ($i = 0; $i < 200; $i++) {
        $rest = $i % 15;
        $travel = ($i * 37) % 3000;
        $samples[] = [(float) $rest, (float) $travel];
        $labels[] = $rest > 7 ? 1 : 0;
    }

    $model = new LogisticRegression(learningRate: 0.3, maxIterations: 3000, l2: 0.01);
    $model->fit($samples, $labels);

    // The rest weight must carry more of the decision than travel.
    expect(abs($model->weights()[0]))->toBeGreaterThan(abs($model->weights()[1]));
});

it('explains a prediction as per feature contributions', function () {
    $samples = [[0.0, 1.0], [1.0, 1.0], [2.0, 0.0], [3.0, 0.0]];
    $labels = [0, 0, 1, 1];

    $model = new LogisticRegression(learningRate: 0.3, maxIterations: 2000, l2: 0.01);
    $model->fit($samples, $labels);

    $contributions = $model->contributions([3.0, 0.0]);

    expect($contributions)->toHaveCount(2)
        ->and(array_sum($contributions) + $model->bias())
        ->toEqualWithDelta(log($model->predict([3.0, 0.0]) / (1 - $model->predict([3.0, 0.0]))), 1e-9);
});

it('survives a constant column without dividing by zero', function () {
    $samples = [[1.0, 5.0], [2.0, 5.0], [3.0, 5.0], [4.0, 5.0]];
    $labels = [0, 0, 1, 1];

    $model = new LogisticRegression(learningRate: 0.3, maxIterations: 1000);
    $result = $model->fit($samples, $labels);

    expect(is_finite($result['loss']))->toBeTrue()
        ->and(is_finite($model->predict([2.5, 5.0])))->toBeTrue();
});

it('round trips through stored parameters', function () {
    $samples = [[0.0], [1.0], [2.0], [3.0]];
    $labels = [0, 0, 1, 1];

    $model = new LogisticRegression(learningRate: 0.3, maxIterations: 2000);
    $model->fit($samples, $labels);

    $rebuilt = LogisticRegression::fromParams($model->params());

    // A stored model must predict identically; every prediction row points
    // at the exact version that produced it.
    foreach ([[0.5], [1.5], [2.5]] as $row) {
        expect($rebuilt->predict($row))->toEqualWithDelta($model->predict($row), 1e-12);
    }
});

it('beats always guessing the base rate', function () {
    $samples = [];
    $labels = [];

    for ($i = 0; $i < 300; $i++) {
        $x = $i / 150 - 1.0;
        $samples[] = [$x];
        $labels[] = $x > 0.1 ? 1 : 0;
    }

    $model = new LogisticRegression(learningRate: 0.5, maxIterations: 3000, l2: 0.01);
    $model->fit($samples, $labels);

    $predictions = array_map(fn (array $row) => $model->predict($row), $samples);
    $baseRate = array_sum($labels) / count($labels);
    $baseline = array_fill(0, count($labels), $baseRate);

    expect(Metrics::logLoss($predictions, $labels))->toBeLessThan(Metrics::logLoss($baseline, $labels));
});

it('refuses to predict before it has been fitted', function () {
    expect(fn () => (new LogisticRegression)->predict([1.0]))->toThrow(InvalidArgumentException::class);
});

it('rejects mismatched samples and labels', function () {
    expect(fn () => (new LogisticRegression)->fit([[1.0]], [1, 0]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new LogisticRegression)->fit([], []))->toThrow(InvalidArgumentException::class);
});
