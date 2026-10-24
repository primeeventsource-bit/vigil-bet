<?php

declare(strict_types=1);

use App\Data\BacktestGame;
use App\Data\PreGameContext;
use App\Enums\Market;
use App\Enums\Sport;
use App\Modeling\Backtest\WalkForwardRunner;
use Carbon\CarbonImmutable;

function game(int $id, string $date, int $home, int $away, array $closing = []): BacktestGame
{
    return new BacktestGame(
        eventId: $id,
        sport: Sport::Nfl,
        homeTeamId: 1 + ($id % 4),
        awayTeamId: 5 + ($id % 4),
        startsAt: CarbonImmutable::parse($date),
        homeScore: $home,
        awayScore: $away,
        closingMarket: $closing,
    );
}

/** @return array<int, BacktestGame> */
function season(int $count, string $start = '2024-09-01'): array
{
    $games = [];
    $day = CarbonImmutable::parse($start);

    for ($i = 1; $i <= $count; $i++) {
        $games[] = game($i, $day->addDays($i)->toDateTimeString(), 24, 17, ['home' => -140, 'away' => 120]);
    }

    return $games;
}

it('never hands the model anything with a score on it', function () {
    // The guarantee is structural: the predictor receives a PreGameContext,
    // and that class has no score property to read.
    $runner = new WalkForwardRunner(burnIn: 0);
    $seen = [];

    $runner->run(
        season(5),
        function (PreGameContext $context) use (&$seen) {
            $seen[] = $context;

            return 0.55;
        },
        fn () => null,
    );

    expect($seen)->toHaveCount(5);

    foreach ($seen as $context) {
        expect($context)->toBeInstanceOf(PreGameContext::class)
            ->and(property_exists($context, 'homeScore'))->toBeFalse()
            ->and(property_exists($context, 'awayScore'))->toBeFalse();
    }
});

it('predicts before it learns, for every single game', function () {
    // If learning ever ran first, the model would know the result of the
    // game it is about to be scored on.
    $order = [];
    $runner = new WalkForwardRunner(burnIn: 0);

    $runner->run(
        season(3),
        function (PreGameContext $c) use (&$order) {
            $order[] = "predict:{$c->eventId}";

            return 0.5;
        },
        function (BacktestGame $g) use (&$order) {
            $order[] = "learn:{$g->eventId}";
        },
    );

    expect($order)->toBe([
        'predict:1', 'learn:1',
        'predict:2', 'learn:2',
        'predict:3', 'learn:3',
    ]);
});

it('rejects games that arrive out of order', function () {
    // A badly sorted query is the most likely way leakage gets in, so it
    // fails loudly rather than scoring a shuffled season.
    $runner = new WalkForwardRunner(burnIn: 0);

    $outOfOrder = [
        game(1, '2024-10-05 13:00', 24, 17),
        game(2, '2024-09-28 13:00', 20, 14),
    ];

    expect(fn () => $runner->run($outOfOrder, fn () => 0.5, fn () => null))
        ->toThrow(InvalidArgumentException::class, 'chronological order');
});

it('scores nothing during burn in but still learns from it', function () {
    $runner = new WalkForwardRunner(burnIn: 3);
    $learned = 0;

    $predictions = $runner->run(
        season(10),
        fn () => 0.6,
        function () use (&$learned) {
            $learned++;
        },
    );

    // Ten games seen, three spent learning what a team is, seven scored.
    expect($predictions)->toHaveCount(7)
        ->and($learned)->toBe(10);
});

it('skips a game the model declines to price', function () {
    $runner = new WalkForwardRunner(burnIn: 0);

    $predictions = $runner->run(
        season(6),
        fn (PreGameContext $c) => $c->eventId % 2 === 0 ? 0.55 : null,
        fn () => null,
    );

    expect($predictions)->toHaveCount(3);
});

it('records what happened alongside what was predicted', function () {
    $runner = new WalkForwardRunner(burnIn: 0);

    $predictions = $runner->run(
        [game(1, '2024-09-08 13:00', 31, 17, ['home' => -200, 'away' => 170])],
        fn () => 0.62,
        fn () => null,
    );

    expect($predictions[0]->outcome)->toBe(1)
        ->and($predictions[0]->probability)->toBe(0.62)
        ->and($predictions[0]->hasMarket())->toBeTrue();
});

it('does not count a tie as a home win', function () {
    $runner = new WalkForwardRunner(burnIn: 0);

    $predictions = $runner->run(
        [game(1, '2024-09-08 13:00', 20, 20)],
        fn () => 0.5,
        fn () => null,
    );

    expect($predictions[0]->outcome)->toBe(0)
        ->and($predictions[0]->wasTie)->toBeTrue();
});

/** A game carrying the line the home side had to cover. */
function spreadGame(int $id, string $date, int $home, int $away, ?float $line): BacktestGame
{
    return new BacktestGame(
        eventId: $id,
        sport: Sport::Nfl,
        homeTeamId: 1,
        awayTeamId: 2,
        startsAt: CarbonImmutable::parse($date),
        homeScore: $home,
        awayScore: $away,
        closingMarket: ['home' => -110, 'away' => -110],
        closingLine: $line,
    );
}

it('scores a spread on whether the line was covered, not on who won', function () {
    // The bug this replaced: a spread run scored who won outright, against
    // a market closing near even money, and reported a large false edge.
    // Home wins by 3 having been asked to give 7, so it won and did not
    // cover - the two answers disagree, which is the whole point.
    $runner = new WalkForwardRunner(burnIn: 0, market: Market::Spread, sigma: 13.5);

    $predictions = $runner->run(
        [spreadGame(1, '2024-09-01', 24, 21, -7.0)],
        fn (PreGameContext $c) => 0.75,
        fn (BacktestGame $g) => null,
    );

    expect($predictions)->toHaveCount(1)
        ->and($predictions[0]->outcome)->toBe(0);
});

it('counts a covered line as a win for the model', function () {
    $runner = new WalkForwardRunner(burnIn: 0, market: Market::Spread, sigma: 13.5);

    // Home gives 3 and wins by 10.
    $predictions = $runner->run(
        [spreadGame(1, '2024-09-01', 30, 20, -3.0)],
        fn (PreGameContext $c) => 0.6,
        fn (BacktestGame $g) => null,
    );

    expect($predictions[0]->outcome)->toBe(1);
});

it('drops a push rather than calling it a loss', function () {
    // The bet is void and the money comes back, so it is no evidence
    // either way and belongs out of the sample - the same treatment a tie
    // already gets on the moneyline.
    $runner = new WalkForwardRunner(burnIn: 0, market: Market::Spread, sigma: 13.5);

    $predictions = $runner->run(
        [spreadGame(1, '2024-09-01', 24, 21, -3.0)],
        fn (PreGameContext $c) => 0.6,
        fn (BacktestGame $g) => null,
    );

    expect($predictions)->toBeEmpty();
});

it('skips a game whose spread never closed', function () {
    // Nothing to cover, so nothing to score. Scoring it against who won
    // is exactly the mistake being fixed.
    $runner = new WalkForwardRunner(burnIn: 0, market: Market::Spread, sigma: 13.5);

    $predictions = $runner->run(
        [spreadGame(1, '2024-09-01', 30, 20, null)],
        fn (PreGameContext $c) => 0.6,
        fn (BacktestGame $g) => null,
    );

    expect($predictions)->toBeEmpty();
});

it('restates the win probability as a cover probability', function () {
    // A heavy favourite giving a big number is far from a certainty to
    // cover, and the number has to move to say so.
    $runner = new WalkForwardRunner(burnIn: 0, market: Market::Spread, sigma: 13.5);

    $predictions = $runner->run(
        [spreadGame(1, '2024-09-01', 30, 20, -14.0)],
        fn (PreGameContext $c) => 0.80,
        fn (BacktestGame $g) => null,
    );

    expect($predictions[0]->probability)->toBeLessThan(0.60)
        ->and($predictions[0]->probability)->toBeGreaterThan(0.30);
});

it('leaves a pick-em spread reading like the moneyline', function () {
    // With no points given, covering and winning are the same event, so
    // the restatement must be close to the identity.
    $runner = new WalkForwardRunner(burnIn: 0, market: Market::Spread, sigma: 13.5);

    $predictions = $runner->run(
        [spreadGame(1, '2024-09-01', 30, 20, 0.0)],
        fn (PreGameContext $c) => 0.68,
        fn (BacktestGame $g) => null,
    );

    expect(abs($predictions[0]->probability - 0.68))->toBeLessThan(0.01);
});

it('still scores the moneyline on who won', function () {
    $runner = new WalkForwardRunner(burnIn: 0);

    $predictions = $runner->run(
        [spreadGame(1, '2024-09-01', 24, 21, -7.0)],
        fn (PreGameContext $c) => 0.75,
        fn (BacktestGame $g) => null,
    );

    // Won by 3 without covering 7: the moneyline says this was a win.
    expect($predictions[0]->outcome)->toBe(1)
        ->and($predictions[0]->probability)->toBe(0.75);
});
