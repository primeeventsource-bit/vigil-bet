<?php

declare(strict_types=1);

use App\Data\BacktestGame;
use App\Enums\Sport;
use App\Modeling\Backtest\WalkForwardRunner;
use App\Modeling\Features\FeatureBuilder;
use App\Modeling\Features\FeatureSchema;
use App\Modeling\Predictors\LogisticPredictor;
use App\Modeling\Ratings\EloLeague;
use App\Modeling\Ratings\EloRater;
use Carbon\CarbonImmutable;

function predictor(int $minimumTraining = 300, int $refitEvery = 100): LogisticPredictor
{
    return new LogisticPredictor(
        new EloLeague(new EloRater),
        new FeatureBuilder,
        minimumTraining: $minimumTraining,
        refitEvery: $refitEvery,
    );
}

/**
 * A synthetic season where the result is a coin flip and nothing in the
 * features knows anything about it.
 *
 * @return array<int, BacktestGame>
 */
function coinFlipSeason(int $games, int $seed = 1337): array
{
    mt_srand($seed);

    $start = CarbonImmutable::parse('2020-09-01 13:00:00');
    $season = [];

    for ($i = 0; $i < $games; $i++) {
        $home = 1 + ($i % 16);
        $away = 17 + ($i % 16);
        $homeWins = mt_rand(0, 1) === 1;

        $season[] = new BacktestGame(
            eventId: $i + 1,
            sport: Sport::Nfl,
            homeTeamId: $home,
            awayTeamId: $away,
            startsAt: $start->addDays($i),
            homeScore: $homeWins ? 24 : 17,
            awayScore: $homeWins ? 17 : 24,
            season: 2020 + intdiv($i, 256),
            homeRestDays: 7,
            awayRestDays: 7,
            roof: $i % 3 === 0 ? 'dome' : 'outdoors',
            divisional: $i % 4 === 0,
        );
    }

    return $season;
}

it('abstains until it has enough history to fit on', function () {
    $model = predictor(minimumTraining: 50);
    $games = coinFlipSeason(60);

    expect($model->probability($games[0]->context()))->toBeNull()
        ->and($model->isTrained())->toBeFalse();

    foreach (array_slice($games, 0, 49) as $game) {
        $model->learn($game);
    }

    expect($model->isTrained())->toBeFalse();

    $model->learn($games[49]);

    expect($model->isTrained())->toBeTrue()
        ->and($model->probability($games[50]->context()))->toBeFloat();
});

it('refits on a schedule rather than on every single game', function () {
    $model = predictor(minimumTraining: 50, refitEvery: 25);

    foreach (coinFlipSeason(120) as $game) {
        $model->learn($game);
    }

    // First fit at 50, then at 75, 100 - not 70 times.
    expect($model->refits())->toBe(3)
        ->and($model->trainingSize())->toBe(120);
});

it('keeps a probability inside the open unit interval', function () {
    $model = predictor(minimumTraining: 100);
    $games = coinFlipSeason(300);

    foreach ($games as $game) {
        $model->learn($game);
    }

    foreach (array_slice($games, 0, 50) as $game) {
        $p = $model->probability($game->context());

        expect($p)->toBeGreaterThan(0.0)->toBeLessThan(1.0);
    }
});

it('trains nothing on a tie but still shows it to elo and to form', function () {
    $model = predictor(minimumTraining: 10);

    $tie = new BacktestGame(
        eventId: 1,
        sport: Sport::Nfl,
        homeTeamId: 1,
        awayTeamId: 2,
        startsAt: CarbonImmutable::parse('2020-09-01 13:00:00'),
        homeScore: 17,
        awayScore: 17,
        season: 2020,
    );

    $model->learn($tie);

    expect($model->trainingSize())->toBe(0)
        ->and($model->league()->gamesLearned())->toBe(1);
});

it('reports what it learned, by feature name', function () {
    $model = predictor(minimumTraining: 100);

    foreach (coinFlipSeason(200) as $game) {
        $model->learn($game);
    }

    expect(array_keys($model->coefficients()))->toBe(FeatureSchema::names());
});

it('explains one game as a contribution per feature', function () {
    $model = predictor(minimumTraining: 100);
    $games = coinFlipSeason(200);

    foreach ($games as $game) {
        $model->learn($game);
    }

    $explained = $model->explain($games[0]->context());

    expect(array_keys($explained))->toBe(FeatureSchema::names())
        ->and(array_sum($explained))->toBeFloat();
});

it('records the feature schema version it was trained against', function () {
    $model = predictor(minimumTraining: 100);

    foreach (coinFlipSeason(150) as $game) {
        $model->learn($game);
    }

    $params = $model->toParams();

    expect($params['feature_version'])->toBe(FeatureSchema::VERSION)
        ->and($params['features'])->toBe(FeatureSchema::names())
        ->and($params['regression'])->not->toBeNull()
        ->and($params['league']['ratings'])->not->toBeEmpty();
});

/*
 * The leakage test that does not rely on reading the code.
 *
 * These outcomes are coin flips. Nothing in the features knows anything
 * about them, so the only honest log loss is log 2, about 0.693, and the
 * bound is two sided because leakage can land on either side of it:
 *
 *  - below 0.6 means the model could see the result when it predicted;
 *  - above 0.9 means it saw the result while training and then leaned hard
 *    on a feature that is pure noise at prediction time.
 *
 * The upper bound is the one that fires in practice, which is worth knowing.
 * Swapping the two blocks inside learn() so each game's own result lands in
 * its own features was tried deliberately against this test: the loss went
 * to 1.07, not to 0.1, because walk-forward punishes a confidently wrong
 * weight rather than rewarding the cheat. A one-sided bound would have let
 * that bug through.
 */
it('cannot beat a coin flip on coin flips', function () {
    $model = predictor(minimumTraining: 200, refitEvery: 50);

    $predictions = (new WalkForwardRunner(0))->run(
        coinFlipSeason(900),
        fn ($context) => $model->probability($context),
        fn (BacktestGame $game) => $model->learn($game),
    );

    expect(count($predictions))->toBeGreaterThan(500);

    $loss = 0.0;

    foreach ($predictions as $prediction) {
        $p = min(1 - 1e-15, max(1e-15, $prediction->probability));
        $loss += $prediction->outcome === 1 ? -log($p) : -log(1 - $p);
    }

    $loss /= count($predictions);

    expect($loss)->toBeGreaterThan(0.6)->toBeLessThan(0.9);
});

/*
 * And the reason the bound above has any force: form genuinely is sensitive
 * to the game being learned. If it were not, the ordering inside learn()
 * would not matter and the test above would be decoration.
 */
it('changes its view of a team once it has been shown the game', function () {
    $builder = new FeatureBuilder(minimumHistory: 1);
    $game = coinFlipSeason(1)[0];

    $before = $builder->build($game->context(), 0.5);

    $builder->learn($game);

    $after = $builder->build($game->context(), 0.5);

    expect($before['home_form'])->toBe(0.0)
        ->and($after['home_form'])->not->toBe(0.0);
});

/*
 * The other half of the same argument: when a feature genuinely does carry
 * the signal, the model has to find it. A test that only proves the model
 * cannot cheat would also pass if the model learned nothing at all.
 */
it('finds a real signal when one is actually there', function () {
    mt_srand(99);

    $start = CarbonImmutable::parse('2020-09-01 13:00:00');
    $games = [];

    for ($i = 0; $i < 800; $i++) {
        // Home teams win four times out of five indoors and lose four out of
        // five outdoors. Nothing else differs.
        $indoors = $i % 2 === 0;
        $homeWins = $indoors ? mt_rand(1, 10) <= 8 : mt_rand(1, 10) <= 2;

        $games[] = new BacktestGame(
            eventId: $i + 1,
            sport: Sport::Nfl,
            homeTeamId: 1 + ($i % 16),
            awayTeamId: 17 + ($i % 16),
            startsAt: $start->addDays($i),
            homeScore: $homeWins ? 24 : 17,
            awayScore: $homeWins ? 17 : 24,
            season: 2020 + intdiv($i, 256),
            homeRestDays: 7,
            awayRestDays: 7,
            roof: $indoors ? 'dome' : 'outdoors',
            divisional: false,
        );
    }

    $model = predictor(minimumTraining: 200, refitEvery: 100);

    foreach ($games as $game) {
        $model->learn($game);
    }

    $indoorGame = $games[0]->context();
    $outdoorGame = $games[1]->context();

    expect($model->coefficients()['indoors'])->toBeGreaterThan(0.5)
        ->and($model->probability($indoorGame))->toBeGreaterThan(0.6)
        ->and($model->probability($outdoorGame))->toBeLessThan(0.4);
});

/*
 * A stored model has to be a whole model.
 *
 * Weights alone are not enough: the rolling form window is state, and a
 * model restored without it would price the first game of the day as though
 * every team had just arrived in the league. These prove the round trip is
 * lossless for everything that reaches a probability.
 */

it('rebuilds a trained model that prices a game identically', function () {
    $model = predictor(minimumTraining: 100);
    $season = coinFlipSeason(300);

    foreach ($season as $game) {
        $model->learn($game);
    }

    // A game it has not been shown, priced before and after a round trip.
    $next = coinFlipSeason(1, seed: 99)[0]->context();

    $rebuilt = LogisticPredictor::fromParams($model->toParams());

    expect($rebuilt->isTrained())->toBeTrue()
        ->and($rebuilt->probability($next))->toBe($model->probability($next));
});

it('carries the form window through the round trip', function () {
    $model = predictor(minimumTraining: 100);

    foreach (coinFlipSeason(300) as $game) {
        $model->learn($game);
    }

    $rebuilt = LogisticPredictor::fromParams($model->toParams());

    $context = coinFlipSeason(1, seed: 7)[0]->context();

    // Same ratings and same form: the vectors must match feature for
    // feature, not merely land on a similar number. Drop the form state
    // from storage and the form entries of this vector go to zero.
    expect($rebuilt->vector($context))->toBe($model->vector($context));
});

it('reports the size of the training set it was fitted on', function () {
    $model = predictor(minimumTraining: 100);

    foreach (coinFlipSeason(300) as $game) {
        $model->learn($game);
    }

    // The samples themselves are not carried - a restored version never
    // refits - but how much it learned from is part of its description.
    $rebuilt = LogisticPredictor::fromParams($model->toParams());

    expect($rebuilt->trainingSize())->toBe($model->trainingSize())
        ->and($rebuilt->trainingSize())->toBeGreaterThan(0);
});

it('does not refit itself after being restored', function () {
    // A restored version is the thing predictions are attributed to. If it
    // refitted, the weights named in a prediction row would not be the
    // weights that made it. Its ratings and form still move when it is
    // shown a game - that is how any Elo works - but the fit does not.
    $model = predictor(minimumTraining: 100);

    foreach (coinFlipSeason(300) as $game) {
        $model->learn($game);
    }

    $rebuilt = LogisticPredictor::fromParams($model->toParams());
    $context = coinFlipSeason(1, seed: 11)[0]->context();
    $before = $rebuilt->probability($context);

    expect($rebuilt->refits())->toBe($model->refits());

    // It can still be shown games - the walk-forward runner does - but it
    // will not refit off the back of them, because the samples it would
    // need were deliberately not restored.
    foreach (coinFlipSeason(50, seed: 5) as $game) {
        $rebuilt->learn($game);
    }

    expect($rebuilt->refits())->toBe($model->refits())
        ->and($before)->not->toBeNull();
});
