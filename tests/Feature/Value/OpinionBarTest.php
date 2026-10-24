<?php

declare(strict_types=1);

use App\Models\ModelVersion;
use App\Services\Value\OpinionBar;

/*
|--------------------------------------------------------------------------
| The bar for publishing an opinion
|--------------------------------------------------------------------------
|
| Not the verdict gate. The gate asks whether a model beats the closing
| line, which is the question for selling a pick, and it is circular for
| publishing one: no model beats it, and the evidence that would settle
| it only accumulates once something is published.
|
| This bar asks what the product's own claim depends on. An opinion is
| shown with a confidence figure, so the thing that must be true is that
| the figure is roughly true - calibration, measured against outcomes
| rather than against a price.
|
| The numbers in these tests are the production models as they actually
| are, so a change that would start publishing a market fails here first.
|
*/

function modelWith(array $metrics, string $sport = 'nhl', string $market = 'h2h'): ModelVersion
{
    // Unique on (sport, market, model_type, version), and these tests
    // build several models for one market on purpose.
    static $version = 0;
    $version++;

    return ModelVersion::create([
        'sport' => $sport,
        'market' => $market,
        'model_type' => 'elo',
        'version' => $version,
        'status' => 'active',
        'training_rows' => 1000,
        // Required, and irrelevant here: the bar reads holdout metrics only.
        'params' => ['model' => 'elo', 'k_factor' => 20],
        'holdout_metrics' => $metrics,
        'trained_at' => now(),
        'trained_through_at' => now(),
        'activated_at' => now(),
    ]);
}

it('publishes the markets whose confidence figures are close to true', function () {
    /*
     * The four moneyline models as measured today. NHL is the
     * interesting one: it loses to the closing line on log loss and is
     * better calibrated than the closing line, which is exactly the
     * property a confidence figure needs and not the property the gate
     * tests for.
     */
    $bar = app(OpinionBar::class);

    foreach ([
        'nhl h2h' => ['predictions' => 956, 'calibration_error' => 0.0335],
        'nba h2h' => ['predictions' => 1453, 'calibration_error' => 0.0349],
        'ncaaf h2h' => ['predictions' => 4928, 'calibration_error' => 0.0505],
        'nfl h2h' => ['predictions' => 415, 'calibration_error' => 0.0530],
    ] as $label => $metrics) {
        expect($bar->allows(modelWith($metrics)))->toBeTrue("{$label} should publish");
    }
});

it('refuses every spreads model, whose numbers are wrong by more than it admits', function () {
    /*
     * Not an accident of the threshold. These models state a probability
     * that is off by nine to twenty-three points, so publishing one
     * would be publishing a number known to be false.
     */
    $bar = app(OpinionBar::class);

    foreach ([
        'nfl spreads' => ['predictions' => 399, 'calibration_error' => 0.0883],
        'nba spreads' => ['predictions' => 1056, 'calibration_error' => 0.1038],
        'nhl spreads' => ['predictions' => 326, 'calibration_error' => 0.1039],
        'ncaab spreads' => ['predictions' => 536, 'calibration_error' => 0.2328],
        'ncaab h2h' => ['predictions' => 708, 'calibration_error' => 0.1054],
    ] as $label => $metrics) {
        expect($bar->allows(modelWith($metrics, 'ncaab', 'spreads')))->toBeFalse("{$label} must not publish");
    }
});

it('refuses a model with too small a sample to have been measured', function () {
    // NCAAF spreads: 61 predictions. A calibration error on that many
    // games is mostly an artefact of which games they were.
    $bar = app(OpinionBar::class);

    $model = modelWith(['predictions' => 61, 'calibration_error' => 0.01], 'ncaaf', 'spreads');

    expect($bar->allows($model))->toBeFalse()
        ->and($bar->refusal($model))->toContain('300');
});

it('refuses a model whose calibration was never measured', function () {
    // Unmeasured is not the same as good, and treating it as good is
    // how an unproven number reaches a customer.
    $model = modelWith(['predictions' => 2000]);

    expect(app(OpinionBar::class)->allows($model))->toBeFalse()
        ->and(app(OpinionBar::class)->refusal($model))->toContain('never had its calibration measured');
});

it('refuses a market with no active model at all', function () {
    expect(app(OpinionBar::class)->allows(null))->toBeFalse()
        ->and(app(OpinionBar::class)->refusal(null))->toContain('no active model');
});

it('gives a reason an operator can read without opening the class', function () {
    $model = modelWith(['predictions' => 1056, 'calibration_error' => 0.1038]);

    // The refusal is written into the row and shown, so it has to say
    // what is wrong and by how much.
    expect(app(OpinionBar::class)->refusal($model))
        ->toContain('10.4')
        ->toContain('6.0');
});

it('does not care whether the model beats the market', function () {
    /*
     * The whole point of this bar existing beside the gate. A model can
     * be well calibrated and still lose to the closing line - every one
     * of ours does - and that is publishable as an opinion while not
     * being sellable as a pick.
     */
    $model = modelWith([
        'predictions' => 956,
        'calibration_error' => 0.0335,
        'verdict' => 'negative',
        'improvement_t_stat' => -1.08,
        'log_loss_improvement' => -0.0076,
    ]);

    expect(app(OpinionBar::class)->allows($model))->toBeTrue();
});

it('reports calibration as a percentage for showing beside an opinion', function () {
    expect(app(OpinionBar::class)->calibrationPercent(modelWith(['predictions' => 956, 'calibration_error' => 0.0335])))
        ->toBe(3.4)
        ->and(app(OpinionBar::class)->calibrationPercent(null))->toBeNull();
});
