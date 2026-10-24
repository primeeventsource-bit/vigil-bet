<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\VigMethod;
use App\Modeling\Odds\Middle;
use App\Modeling\Odds\OddsConverter;
use App\Modeling\Odds\VigRemover;

it('finds the gap between two totals', function () {
    // Over 44 one book, Under 47.5 another: 45, 46 and 47 take both.
    $window = Middle::window(Market::Total, 44.0, 47.5);

    expect($window)->toBe(['low' => 44.0, 'high' => 47.5])
        ->and(Middle::width(Market::Total, 44.0, 47.5))->toBe(3.5)
        ->and(Middle::outs(Market::Total, 44.0, 47.5))->toBe(3);
});

it('finds the gap between two spreads', function () {
    // Home -2.5 wins above a margin of 2.5; away +3.5 wins below 3.5.
    // A three-point home win takes both.
    $window = Middle::window(Market::Spread, -2.5, 3.5);

    expect($window)->toBe(['low' => 2.5, 'high' => 3.5])
        ->and(Middle::width(Market::Spread, -2.5, 3.5))->toBe(1.0)
        ->and(Middle::outs(Market::Spread, -2.5, 3.5))->toBe(1);
});

it('says there is no middle when the market agrees with itself', function () {
    // The ordinary case: the same number at both books.
    expect(Middle::window(Market::Total, 45.0, 45.0))->toBeNull()
        ->and(Middle::window(Market::Spread, -3.0, 3.0))->toBeNull()
        ->and(Middle::outs(Market::Total, 45.0, 45.0))->toBe(0);
});

it('says there is no middle when the lines are crossed the wrong way', function () {
    // Over 47.5 and Under 44 is the inverse: a gap where both legs LOSE.
    expect(Middle::window(Market::Total, 47.5, 44.0))->toBeNull()
        ->and(Middle::width(Market::Total, 47.5, 44.0))->toBe(0.0);
});

it('counts only results strictly inside, because a line is a push', function () {
    // Over 44 / Under 46: 45 wins both. 44 and 46 each push a leg.
    expect(Middle::outs(Market::Total, 44.0, 46.0))->toBe(1);
});

it('prices the break-even hit rate as the overround itself', function () {
    // Two legs at -110: the middle must land 4.76% of the time to break
    // even, which is exactly the hold on the two prices taken.
    expect(Middle::breakEvenHitProbability(-110, -110))->toEqualWithDelta(0.047619, 0.000001)
        ->and(Middle::impliedSum(-110, -110) - 1.0)
        ->toEqualWithDelta(Middle::breakEvenHitProbability(-110, -110), 1e-12);
});

it('costs the overround on a miss and pays about ninety percent on a hit', function () {
    expect(Middle::missPerUnit(-110, -110))->toEqualWithDelta(-0.045455, 0.000001)
        ->and(Middle::hitPerUnit(-110, -110))->toEqualWithDelta(0.909091, 0.000001);
});

it('is worth nothing at exactly the break-even rate', function () {
    $breakEven = Middle::breakEvenHitProbability(-110, -110);

    expect(Middle::expectedValuePerUnit(-110, -110, $breakEven))->toEqualWithDelta(0.0, 1e-12);
});

it('turns positive only once the gap beats the overround', function () {
    // A three-point middle in the NFL lands far more than 4.76% of the time.
    expect(Middle::expectedValuePerUnit(-110, -110, 0.09))->toBeGreaterThan(0.0)
        ->and(Middle::expectedValuePerUnit(-110, -110, 0.03))->toBeLessThan(0.0);
});

it('reads the chance of the gap off the board rather than a model', function () {
    // The two legs cover every result and overlap on the middle, so what
    // the two fair probabilities carry above one IS the gap.
    expect(Middle::marketHitProbability(0.55, 0.52))->toEqualWithDelta(0.07, 1e-12)
        ->and(Middle::marketHitProbability(0.50, 0.50))->toEqualWithDelta(0.0, 1e-12);
});

it('never reports a negative chance when the board disagrees with itself', function () {
    expect(Middle::marketHitProbability(0.40, 0.45))->toBe(0.0);
});

it('splits the stake so a miss costs the same either way', function () {
    $split = Middle::stakeSplitCents(-110, -110, 100000);

    expect($split['low'] + $split['high'])->toBe(100000)
        ->and($split['low'])->toBe(50000);
});

it('refuses to middle a moneyline', function () {
    Middle::window(Market::Moneyline, 0.0, 0.0);
})->throws(InvalidArgumentException::class);

it('refuses an impossible hit rate', function () {
    Middle::expectedValuePerUnit(-110, -110, 1.4);
})->throws(InvalidArgumentException::class);

/*
 * The finding that decides what a middle finder is for.
 *
 * To middle a market you have to buy the low leg at the low book and the
 * high leg at the high book, and both of those are the favoured side of
 * their own market - that is exactly what makes the gap a gap. So the
 * chance of the gap landing is already inside both prices, and you pay
 * each book's margin on top of it. The board can therefore never say a
 * middle is worth taking: the only thing that can is a model that
 * disagrees with the board about the shape of the distribution.
 */
it('can never be worth taking on the board alone, whatever the prices', function () {
    mt_srand(20261021);

    $tested = 0;
    $worstExcess = -1.0;

    foreach (range(1, 4000) as $ignored) {
        $lowBook = [mt_rand(-400, 400), mt_rand(-400, 400)];
        $highBook = [mt_rand(-400, 400), mt_rand(-400, 400)];

        foreach ([$lowBook, $highBook] as $pair) {
            foreach ($pair as $price) {
                if (! OddsConverter::isValidAmerican($price)) {
                    continue 3;
                }
            }
        }

        $lowSum = OddsConverter::americanToProbability($lowBook[0])
            + OddsConverter::americanToProbability($lowBook[1]);
        $highSum = OddsConverter::americanToProbability($highBook[0])
            + OddsConverter::americanToProbability($highBook[1]);

        // A book that holds no margin is not a book; a two-sided market
        // summing under 100% is an arbitrage inside one book, and belongs
        // to the other finder entirely.
        if ($lowSum <= 1.0 || $highSum <= 1.0) {
            continue;
        }

        // And the two legs actually taken must not themselves pay either
        // way. When they do, the break-even hit rate goes negative and the
        // bet is an arbitrage that happens to have a gap on top of it -
        // which the arbitrage finder owns, and which needs no middle to be
        // worth taking.
        if (Middle::impliedSum($lowBook[0], $highBook[1]) <= 1.0) {
            continue;
        }

        $tested++;

        foreach ([VigMethod::Multiplicative, VigMethod::Power] as $method) {
            $lowFair = VigRemover::fairProbabilities(['low' => $lowBook[0], 'high' => $lowBook[1]], $method)['low'];
            $highFair = VigRemover::fairProbabilities(['low' => $highBook[0], 'high' => $highBook[1]], $method)['high'];

            $hit = Middle::marketHitProbability($lowFair, $highFair);
            $breakEven = Middle::breakEvenHitProbability($lowBook[0], $highBook[1]);

            $worstExcess = max($worstExcess, $hit - $breakEven);
        }
    }

    expect($tested)->toBeGreaterThan(200)
        ->and($worstExcess)->toBeLessThan(0.0);
});
