<?php

declare(strict_types=1);

use App\Data\BacktestGame;
use App\Data\PreGameContext;
use App\Enums\Sport;
use App\Modeling\Features\FeatureBuilder;
use App\Modeling\Features\FeatureSchema;
use Carbon\CarbonImmutable;

function pregame(array $overrides = []): PreGameContext
{
    return new PreGameContext(
        eventId: $overrides['eventId'] ?? 1,
        sport: $overrides['sport'] ?? Sport::Nfl,
        homeTeamId: $overrides['homeTeamId'] ?? 10,
        awayTeamId: $overrides['awayTeamId'] ?? 20,
        startsAt: $overrides['startsAt'] ?? CarbonImmutable::parse('2025-09-07 13:00:00'),
        neutralSite: $overrides['neutralSite'] ?? false,
        season: $overrides['season'] ?? 2025,
        homeRestDays: $overrides['homeRestDays'] ?? null,
        awayRestDays: $overrides['awayRestDays'] ?? null,
        roof: $overrides['roof'] ?? null,
        surface: $overrides['surface'] ?? null,
        divisional: $overrides['divisional'] ?? null,
    );
}

function playedGame(int $home, int $away, int $homeScore, int $awayScore): BacktestGame
{
    return new BacktestGame(
        eventId: random_int(1, 1_000_000),
        sport: Sport::Nfl,
        homeTeamId: $home,
        awayTeamId: $away,
        startsAt: CarbonImmutable::parse('2025-09-07 13:00:00'),
        homeScore: $homeScore,
        awayScore: $awayScore,
        season: 2025,
    );
}

it('produces a vector in the declared order, every time', function () {
    $builder = new FeatureBuilder;

    expect(array_keys($builder->build(pregame(), 0.6)))->toBe(FeatureSchema::names())
        ->and($builder->vector(pregame(), 0.6))->toHaveCount(FeatureSchema::count());
});

it('reads a team with no history as perfectly average, not as terrible', function () {
    $named = (new FeatureBuilder)->build(pregame(), 0.5);

    expect($named['home_form'])->toBe(0.0)
        ->and($named['away_form'])->toBe(0.0)
        ->and($named['home_margin_form'])->toBe(0.0)
        ->and($named['away_margin_form'])->toBe(0.0);
});

it('turns the elo probability into log odds', function () {
    $builder = new FeatureBuilder;

    expect($builder->build(pregame(), 0.5)['elo_logit'])->toBe(0.0)
        ->and($builder->build(pregame(), 0.75)['elo_logit'])->toBeGreaterThan(1.0)
        ->and($builder->build(pregame(), 0.25)['elo_logit'])->toBeLessThan(-1.0);
});

it('survives an elo probability pinned at zero or one', function () {
    $builder = new FeatureBuilder;

    expect(is_finite($builder->build(pregame(), 0.0)['elo_logit']))->toBeTrue()
        ->and(is_finite($builder->build(pregame(), 1.0)['elo_logit']))->toBeTrue();
});

it('waits for a few games before trusting form', function () {
    $builder = new FeatureBuilder(formWindow: 10, minimumHistory: 4);

    // Three wins is not yet a trend.
    foreach (range(1, 3) as $ignored) {
        $builder->learn(playedGame(10, 99, 30, 10));
    }

    expect($builder->build(pregame(), 0.5)['home_form'])->toBe(0.0);

    $builder->learn(playedGame(10, 99, 30, 10));

    expect($builder->build(pregame(), 0.5)['home_form'])->toBe(0.5);
});

it('centres form on zero so an even team reads as no opinion', function () {
    $builder = new FeatureBuilder(minimumHistory: 4);

    $builder->learn(playedGame(10, 99, 30, 10));
    $builder->learn(playedGame(10, 99, 30, 10));
    $builder->learn(playedGame(10, 99, 10, 30));
    $builder->learn(playedGame(10, 99, 10, 30));

    expect($builder->build(pregame(), 0.5)['home_form'])->toBe(0.0);
});

it('only remembers as far back as the window', function () {
    $builder = new FeatureBuilder(formWindow: 4, minimumHistory: 4);

    // Four losses, then four wins. The losses should have fallen out.
    foreach (range(1, 4) as $ignored) {
        $builder->learn(playedGame(10, 99, 0, 20));
    }

    foreach (range(1, 4) as $ignored) {
        $builder->learn(playedGame(10, 99, 20, 0));
    }

    expect($builder->gamesFor(10))->toBe(4)
        ->and($builder->build(pregame(), 0.5)['home_form'])->toBe(0.5);
});

it('scores a finished game from both sides', function () {
    $builder = new FeatureBuilder(minimumHistory: 1);

    $builder->learn(playedGame(10, 20, 28, 14));

    $named = $builder->build(pregame(['homeTeamId' => 10, 'awayTeamId' => 20]), 0.5);

    expect($named['home_form'])->toBe(0.5)
        ->and($named['away_form'])->toBe(-0.5)
        ->and($named['home_margin_form'])->toBeGreaterThan(0.0)
        ->and($named['away_margin_form'])->toBe(-$named['home_margin_form']);
});

it('counts a tie as half a win to each side', function () {
    $builder = new FeatureBuilder(minimumHistory: 1);

    $builder->learn(playedGame(10, 20, 17, 17));

    $named = $builder->build(pregame(['homeTeamId' => 10, 'awayTeamId' => 20]), 0.5);

    expect($named['home_form'])->toBe(0.0)
        ->and($named['away_form'])->toBe(0.0);
});

it('scales margin form by the sport, so a blowout means the same everywhere', function () {
    $builder = new FeatureBuilder(minimumHistory: 1);

    // Fourteen points is a bigger deal in football than in basketball, and
    // the feature has to say so or one weight has to serve both.
    $builder->learn(playedGame(10, 20, 28, 14));

    $nfl = $builder->build(pregame(['sport' => Sport::Nfl]), 0.5)['home_margin_form'];
    $nba = $builder->build(pregame(['sport' => Sport::Nba]), 0.5)['home_margin_form'];

    expect($nfl)->toBeGreaterThan(0.0)
        ->and($nba)->toBeGreaterThan($nfl);
});

it('reads rest as a difference and ignores the offseason', function () {
    $builder = new FeatureBuilder;

    expect($builder->build(pregame(['homeRestDays' => 13, 'awayRestDays' => 6]), 0.5)['rest_diff'])->toBe(7.0)
        ->and($builder->build(pregame(['homeRestDays' => 6, 'awayRestDays' => 13]), 0.5)['rest_diff'])->toBe(-7.0)
        // Week one gives both sides months off, which is not an advantage.
        ->and($builder->build(pregame(['homeRestDays' => 220, 'awayRestDays' => 215]), 0.5)['rest_diff'])->toBe(0.0);
});

it('says nothing about rest when the schedule did not say', function () {
    // One side unknown makes the difference unknowable, but the side that
    // did report still gets its own flag.
    $named = (new FeatureBuilder)->build(pregame(['homeRestDays' => null, 'awayRestDays' => 4]), 0.5);

    expect($named['rest_diff'])->toBe(0.0)
        ->and($named['home_short_week'])->toBe(0.0)
        ->and($named['away_short_week'])->toBe(1.0);
});

it('flags a short week', function () {
    $builder = new FeatureBuilder;

    // Sunday to Thursday is four days, and that is a real disadvantage.
    expect($builder->build(pregame(['homeRestDays' => 4]), 0.5)['home_short_week'])->toBe(1.0)
        ->and($builder->build(pregame(['homeRestDays' => 7]), 0.5)['home_short_week'])->toBe(0.0);
});

it('recognises a fixed dome, whatever the source calls it', function () {
    $builder = new FeatureBuilder;

    foreach (['dome', 'Dome', 'INDOORS', 'indoor'] as $roof) {
        expect($builder->build(pregame(['roof' => $roof]), 0.5)['indoors'])->toBe(1.0);
    }

    foreach (['outdoors', 'Outdoors', null, ''] as $roof) {
        expect($builder->build(pregame(['roof' => $roof]), 0.5)['indoors'])->toBe(0.0);
    }
});

it('refuses to treat a shut retractable roof as knowing the roof was shut', function () {
    $builder = new FeatureBuilder;

    // The source says "closed" or "open" per game, decided on the day and
    // filled in afterwards. Reading either as indoors would train on
    // something unavailable when the bet is actually placed.
    foreach (['closed', 'open', 'retractable'] as $roof) {
        $named = $builder->build(pregame(['roof' => $roof]), 0.5);

        expect($named['indoors'])->toBe(0.0)
            ->and($named['retractable'])->toBe(1.0);
    }

    foreach (['dome', 'outdoors', null] as $roof) {
        expect($builder->build(pregame(['roof' => $roof]), 0.5)['retractable'])->toBe(0.0);
    }
});

it('keeps unknown divisional distinct from confidently not divisional', function () {
    $builder = new FeatureBuilder;

    // Only the NFL source publishes this. Everywhere else it is unknown, and
    // unknown has to read as the neutral default rather than as a firm no.
    expect($builder->build(pregame(['divisional' => true]), 0.5)['divisional'])->toBe(1.0)
        ->and($builder->build(pregame(['divisional' => false]), 0.5)['divisional'])->toBe(0.0)
        ->and($builder->build(pregame(['divisional' => null]), 0.5)['divisional'])->toBe(0.0);
});
