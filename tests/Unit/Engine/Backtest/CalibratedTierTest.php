<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The calibrated tier
|--------------------------------------------------------------------------
|
| `Proven` means the model beats the no-vig closing line. Measured across
| the eleven live markets on 25 October 2026, every single one loses to
| the close - nba h2h, nfl h2h, nhl h2h, mlb h2h and the rest, all
| `negative`. A gate only that could open meant VIGIL published nothing
| and would have gone on publishing nothing however long it ran.
|
| So there is a second tier, and it asserts something weaker that is
| actually true: over a real out-of-sample run, when this model says 60%
| the thing happens about 60% of the time. Four of the eleven markets
| clear it on today's numbers and seven do not, which is what makes it a
| standard rather than a rubber stamp.
|
| These tests fix the shape of that: what it lets through, what it still
| refuses, and that it never claims an edge it has not got.
|
*/

use App\Enums\Verdict;
use App\Modeling\Backtest\Verdicter;

beforeEach(function () {
    $this->verdicter = new Verdicter;
});

/** The live numbers, so these are not invented thresholds. */
dataset('live markets', [
    'nba h2h' => [2713, 0.0334, true],
    'nfl h2h' => [630, 0.0376, true],
    'nhl h2h' => [1722, 0.0391, true],
    'mlb h2h' => [3235, 0.0508, true],
    'ncaaf h2h' => [9929, 0.0847, false],
    'nba spreads' => [1056, 0.1038, false],
    'nhl spreads' => [326, 0.1039, false],
    'ncaab h2h' => [1216, 0.1289, false],
    'ncaab spreads' => [536, 0.2328, false],
]);

it('publishes exactly the markets whose own numbers hold up', function (int $predictions, float $calibration, bool $expected) {
    /*
     * Every one of these loses to the closing line, so the proven tier
     * is unavailable to all of them. What separates them is whether
     * their stated probabilities have held.
     */
    $result = $this->verdicter->decide(
        predictions: $predictions,
        modelLogLoss: 0.7000,
        marketLogLoss: 0.6800,
        modelCalibrationError: $calibration,
        marketCalibrationError: 0.0200,
        improvementTStat: -3.1,
    );

    expect($result->verdict->allowsRecommendation())->toBe($expected);
})->with('live markets');

it('never lets the weaker tier claim an edge', function () {
    $result = $this->verdicter->decide(
        predictions: 2713,
        modelLogLoss: 0.7000,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.0334,
        marketCalibrationError: 0.0200,
        improvementTStat: -3.1,
    );

    expect($result->verdict)->toBe(Verdict::Calibrated)
        ->and($result->verdict->allowsRecommendation())->toBeTrue()
        // The distinction the whole tier rests on.
        ->and($result->verdict->claimsEdge())->toBeFalse()
        ->and(Verdict::Proven->claimsEdge())->toBeTrue();
});

it('says in the reason that it still loses to the market', function () {
    /*
     * A customer reading why this market is published should not have to
     * infer the part that is against it.
     */
    $result = $this->verdicter->decide(
        predictions: 2713,
        modelLogLoss: 0.7000,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.0334,
        marketCalibrationError: 0.0200,
        improvementTStat: -3.1,
    );

    expect($result->reason)->toContain('loses to the closing line')
        ->and($result->reason)->toContain('not an edge');
});

it('still needs a real sample before any tier opens', function () {
    /*
     * Calibration over forty games is a number about forty games. The
     * sample floor is untouched by the new tier.
     */
    $result = $this->verdicter->decide(
        predictions: 40,
        modelLogLoss: 0.7000,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.0100,
        marketCalibrationError: 0.0200,
        improvementTStat: -3.1,
    );

    expect($result->verdict)->toBe(Verdict::Insufficient)
        ->and($result->verdict->allowsRecommendation())->toBeFalse();
});

it('will not publish a market whose calibration was never measured', function () {
    $result = $this->verdicter->decide(
        predictions: 2713,
        modelLogLoss: 0.7000,
        marketLogLoss: 0.6800,
        modelCalibrationError: null,
        marketCalibrationError: 0.0200,
        improvementTStat: -3.1,
    );

    expect($result->verdict->allowsRecommendation())->toBeFalse();
});

it('keeps the proven tier available for anything that ever earns it', function () {
    /*
     * Not deleted, just no longer the only door. If a market ever does
     * beat the close, that is a stronger claim and it should be said.
     */
    $result = $this->verdicter->decide(
        predictions: 2713,
        modelLogLoss: 0.6650,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.0180,
        marketCalibrationError: 0.0200,
        improvementTStat: 2.4,
    );

    expect($result->verdict)->toBe(Verdict::Proven)
        ->and($result->verdict->claimsEdge())->toBeTrue();
});
