<?php

declare(strict_types=1);

use App\Enums\VigMethod;
use App\Modeling\Odds\MarketConsensus;
use App\Modeling\Odds\OddsConverter;

it('removes the vig from each book before combining them', function () {
    // A -110 both ways market implies 104.76 percent, so the fair price for
    // either side is 50 percent and not 52.4.
    $consensus = MarketConsensus::fairProbability(
        ['dk' => ['home' => -110, 'away' => -110]],
        'home',
        ['home', 'away'],
    );

    expect($consensus->fairProbability)->toEqualWithDelta(0.5, 1e-9)
        ->and($consensus->impliedSum)->toEqualWithDelta(1.0476, 0.0005)
        ->and($consensus->bookCount)->toBe(1);
});

it('never produces a market that sums to less than one', function () {
    // The trap this class exists to avoid: averaging raw implied
    // probabilities across books with different holds, then removing vig
    // afterwards, yields a blended market no book offered - and usually one
    // that looks beatable before a model has been consulted.
    $books = [
        'a' => ['home' => -110, 'away' => -110],
        'b' => ['home' => -105, 'away' => -115],
        'c' => ['home' => -120, 'away' => -102],
    ];

    $home = MarketConsensus::fairProbability($books, 'home', ['home', 'away']);
    $away = MarketConsensus::fairProbability($books, 'away', ['home', 'away']);

    expect($home->fairProbability + $away->fairProbability)->toBeGreaterThanOrEqual(0.999)
        ->and($home->impliedSum)->toBeGreaterThan(1.0);
});

it('ignores a book quoting only one side', function () {
    // A one-sided price cannot have its vig removed, so counting it would
    // mean treating a raw price as a fair one.
    $consensus = MarketConsensus::fairProbability(
        [
            'dk' => ['home' => -110, 'away' => -110],
            'mgm' => ['home' => -110],
        ],
        'home',
        ['home', 'away'],
    );

    expect($consensus->bookCount)->toBe(1)
        ->and($consensus->perBook)->toHaveKey('dk')
        ->and($consensus->perBook)->not->toHaveKey('mgm');
});

it('says nothing when no book quotes both sides', function () {
    expect(MarketConsensus::fairProbability(['dk' => ['home' => -110]], 'home', ['home', 'away']))
        ->toBeNull();
});

it('shrugs off one stale book', function () {
    // The reason the combination is a median. Four books agree the home
    // side is around even money; one has not moved and still has it at
    // +300. A mean would invent an edge of several points exactly where a
    // tool like this does its damage.
    $books = [
        'a' => ['home' => -110, 'away' => -110],
        'b' => ['home' => -108, 'away' => -112],
        'c' => ['home' => -112, 'away' => -108],
        'd' => ['home' => -110, 'away' => -110],
        'stale' => ['home' => 300, 'away' => -400],
    ];

    $consensus = MarketConsensus::fairProbability($books, 'home', ['home', 'away']);

    expect($consensus->bookCount)->toBe(5)
        ->and($consensus->fairProbability)->toEqualWithDelta(0.5, 0.01);
});

it('prices a lopsided market the same way a book does', function () {
    // -300 against +240 is a heavy favourite. Removing the hold has to
    // leave it a favourite, and by less than the raw price implied.
    $consensus = MarketConsensus::fairProbability(
        ['dk' => ['home' => -300, 'away' => 240]],
        'home',
        ['home', 'away'],
    );

    expect($consensus->fairProbability)->toBeGreaterThan(0.5)
        ->and($consensus->fairProbability)->toBeLessThan(OddsConverter::americanToProbability(-300));
});

it('can use the power method instead', function () {
    // The two disagree most on longshots, which is the whole reason both
    // exist. They must at least both be probabilities.
    $books = ['dk' => ['home' => -300, 'away' => 240]];

    $multiplicative = MarketConsensus::fairProbability($books, 'away', ['home', 'away']);
    $power = MarketConsensus::fairProbability($books, 'away', ['home', 'away'], VigMethod::Power);

    expect($power->method)->toBe(VigMethod::Power)
        ->and($power->fairProbability)->toBeGreaterThan(0.0)->toBeLessThan(1.0)
        ->and($power->fairProbability)->not->toBe($multiplicative->fairProbability);
});

it('takes the middle of an even number of books', function () {
    expect(MarketConsensus::median([0.4, 0.5, 0.6, 0.7]))->toEqualWithDelta(0.55, 1e-9)
        ->and(MarketConsensus::median([0.5]))->toBe(0.5)
        ->and(MarketConsensus::median([]))->toBe(0.0);
});

it('prices a totals market by its own sides', function () {
    // Under is the shorter price at -115, so it is the side the market
    // thinks more likely, and the pair still has to sum to one.
    $over = MarketConsensus::fairProbability(
        ['dk' => ['over' => -105, 'under' => -115]],
        'over',
        ['over', 'under'],
    );

    $under = MarketConsensus::fairProbability(
        ['dk' => ['over' => -105, 'under' => -115]],
        'under',
        ['over', 'under'],
    );

    expect($over->fairProbability)->toBeLessThan(0.5)
        ->and($under->fairProbability)->toBeGreaterThan(0.5)
        ->and($over->fairProbability + $under->fairProbability)->toEqualWithDelta(1.0, 1e-9)
        ->and($over->bookCount)->toBe(1);
});
