<?php

declare(strict_types=1);

use App\Modeling\Odds\Likelihood;

it('states a win chance as bets out of ten', function () {
    expect(Likelihood::winsOutOfTen(0.704))->toBe(7)
        ->and(Likelihood::winsOutOfTen(0.55))->toBe(6)
        ->and(Likelihood::winsOutOfTen(0.50))->toBe(5);
});

it('says out loud how often it still loses', function () {
    expect(Likelihood::sentence(0.704))->toBe('Wins about 7 out of 10 bets like this')
        ->and(Likelihood::losesSentence(0.704))->toBe('Still loses about 3 times in 10')
        ->and(Likelihood::losesSentence(0.92))->toBe('Still loses about 1 time in 10');
});

it('draws ten dots with the losing ones visible', function () {
    $dots = Likelihood::dots(0.704);

    expect($dots)->toHaveCount(10)
        ->and(count(array_filter($dots)))->toBe(7)
        ->and(count(array_filter($dots, fn ($d) => $d === false)))->toBe(3);
});

it('knows the win rate a price needs to break even', function () {
    expect(Likelihood::breakEvenWinRate(-110))->toEqualWithDelta(0.5238, 0.0001)
        ->and(Likelihood::breakEvenWinRate(-200))->toEqualWithDelta(0.6667, 0.0001)
        ->and(Likelihood::breakEvenWinRate(100))->toBe(0.5);
});

it('measures edge against the price actually on offer', function () {
    // The same bet, at two prices. Likely to win is not the same as worth
    // betting, which is the whole reason a favourite can still be a pass.
    expect(Likelihood::edgeAtPrice(0.704, -200))->toEqualWithDelta(0.0373, 0.0001)
        ->and(Likelihood::edgeAtPrice(0.704, -300))->toEqualWithDelta(-0.0460, 0.0001);
});

it('calls a heavy favourite at a short price a losing bet', function () {
    // 70% is a strong favourite and -300 still loses money.
    expect(Likelihood::edgeAtPrice(0.70, -300))->toBeLessThan(0.0);
});

it('calls a near coin flip at a long price a winning bet', function () {
    // 52% at +110 is barely favoured and makes money.
    expect(Likelihood::edgeAtPrice(0.52, 110))->toBeGreaterThan(0.0);
});

it('writes a reason line naming the book and the break even rate', function () {
    $reason = Likelihood::reason(0.704, -200, 'Hard Rock Bet');

    expect($reason)->toContain('70.4%')
        ->and($reason)->toContain('Hard Rock Bet')
        ->and($reason)->toContain('66.7%')
        ->and($reason)->toContain('-200');
});

it('refuses an impossible probability', function () {
    expect(fn () => Likelihood::winsOutOfTen(1.4))->toThrow(InvalidArgumentException::class);
});
