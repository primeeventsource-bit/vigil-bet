<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| How far an unproven market may disagree with the book
|--------------------------------------------------------------------------
|
| The calibrated tier let four markets publish, and the first board it
| produced offered Philadelphia at a 27.8% edge: the model had them at
| 62.3% against a no-vig market of 33.6%. Professional edges run one to
| three points. Every market here loses to the closing line, so a model
| claiming the book is twenty-eight points wrong is the model being wrong,
| published as a recommendation.
|
| Calibration is no protection against this. It says the stated
| probabilities are right on average, and the largest disagreements with a
| sharper source are precisely where an average is least comforting.
|
| So a market that has not beaten the close is shrunk toward it. A market
| that has beaten it is not - it earned the right to disagree.
|
*/

use App\Enums\Verdict;
use App\Services\Value\ValueScanner;

function shrink(float $model, ?float $fair, Verdict $verdict): float
{
    $method = new ReflectionMethod(ValueScanner::class, 'shrinkToMarket');

    return $method->invoke(app(ValueScanner::class), $model, $fair, $verdict);
}

it('pulls an unproven market most of the way back to the book', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    // The real numbers off the first calibrated board.
    $published = shrink(0.623, 0.336, Verdict::Calibrated);

    expect(round($published, 3))->toBe(0.408)
        // 7.2 points rather than 28.7, which is a claim the evidence carries.
        ->and(round($published - 0.336, 3))->toBe(0.072);
});

it('leaves a market that beat the closing line alone', function () {
    /*
     * The one tier that earned the right to disagree. Shrinking it would
     * throw away the only thing it has proved.
     */
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    expect(shrink(0.623, 0.336, Verdict::Proven))->toBe(0.623);
});

it('shrinks every tier that does not claim an edge', function (Verdict $verdict) {
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    expect(shrink(0.623, 0.336, $verdict))->toBeLessThan(0.623)
        ->and($verdict->claimsEdge())->toBeFalse();
})->with([
    'calibrated' => Verdict::Calibrated,
    'unproven' => Verdict::Unproven,
    'negative' => Verdict::Negative,
    'insufficient' => Verdict::Insufficient,
]);

it('shrinks a disagreement in either direction', function () {
    /*
     * Symmetry matters: a model under the market is as likely to be wrong
     * as one over it, and shrinking only the overs would bias every board
     * toward unders.
     */
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    $under = shrink(0.200, 0.500, Verdict::Calibrated);

    expect(round($under, 3))->toBe(0.425)
        ->and($under)->toBeGreaterThan(0.200);
});

it('leaves the number alone when there is no market to shrink toward', function () {
    /*
     * No consensus price means no edge is computed anyway, so there is
     * nothing to protect against and nothing to invent.
     */
    expect(shrink(0.623, null, Verdict::Calibrated))->toBe(0.623);
});

it('can be turned off, and says so in configuration rather than in code', function () {
    config()->set('edge.value.unproven_edge_shrink', 1.0);

    expect(shrink(0.623, 0.336, Verdict::Calibrated))->toBe(0.623);
});

it('collapses a market onto the book when set to zero', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.0);

    expect(shrink(0.623, 0.336, Verdict::Calibrated))->toBe(0.336);
});

it('ships with a ceiling that actually binds', function () {
    /*
     * A default of 1.0 would be the bug this exists to prevent, shipped
     * as a setting.
     */
    $configured = (float) config('edge.value.unproven_edge_shrink');

    expect($configured)->toBeGreaterThan(0.0)
        ->and($configured)->toBeLessThan(1.0);
});
