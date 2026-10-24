<?php

declare(strict_types=1);

use App\Enums\JobStatus;
use App\Enums\Market;
use App\Enums\ModelStatus;
use App\Enums\ModelType;
use App\Enums\Sport;
use App\Jobs\Modeling\TrainModel;
use App\Modeling\Features\FeatureSchema;
use App\Models\Book;
use App\Models\Event;
use App\Models\EventLineMarker;
use App\Models\JobRun;
use App\Models\ModelVersion;
use App\Models\Team;
use App\Services\Modeling\ModelTrainer;
use Carbon\CarbonImmutable;

/**
 * A synthetic league where home teams really are stronger, so a working
 * rating system has something genuine to find and a stored version has
 * something real in it.
 */
function trainableLeague(int $games, bool $withMarket = true): void
{
    $teams = [];

    foreach (range(1, 8) as $i) {
        $teams[] = Team::create([
            'sport' => 'nfl', 'name' => "T{$i}", 'display_name' => "Team {$i}", 'slug' => "team-{$i}",
        ])->id;
    }

    $book = $withMarket ? Book::create(['key' => 'draftkings', 'title' => 'DK']) : null;
    $day = now()->subYears(2);

    for ($i = 0; $i < $games; $i++) {
        $home = $teams[$i % 8];
        $away = $teams[($i + 3) % 8];

        if ($home === $away) {
            continue;
        }

        $homeStrength = 8 - array_search($home, $teams, true);
        $awayStrength = 8 - array_search($away, $teams, true);

        $event = Event::create([
            'sport' => 'nfl',
            'home_team_id' => $home,
            'away_team_id' => $away,
            'starts_at' => $day->copy()->addDays($i),
            'season' => 2023 + intdiv($i, 100),
            'status' => 'final',
            'final_home_score' => 17 + $homeStrength + ($i % 5),
            'final_away_score' => 17 + $awayStrength + (($i * 3) % 5),
        ]);

        if ($book !== null) {
            foreach ([['home', -120], ['away', 100]] as [$side, $price]) {
                EventLineMarker::create([
                    'event_id' => $event->id,
                    'book_id' => $book->id,
                    'market' => Market::Moneyline->value,
                    'side' => $side,
                    'price_close_american' => $price,
                    'computed_at' => now(),
                ]);
            }
        }
    }
}

beforeEach(function () {
    $this->trainer = app(ModelTrainer::class);
});

it('stores a trained model as a version', function () {
    trainableLeague(400);

    $result = $this->trainer->train(Sport::Nfl, Market::Moneyline, burnIn: 50);

    $version = $result['version'];

    expect($version)->not->toBeNull()
        ->and($version->sport)->toBe(Sport::Nfl)
        ->and($version->market)->toBe(Market::Moneyline)
        ->and($version->version)->toBe(1)
        ->and($version->training_rows)->toBeGreaterThan(0)
        ->and($version->params['league']['ratings'])->not->toBeEmpty();
});

it('records what the model scored and what the verdict was', function () {
    trainableLeague(400);

    $version = $this->trainer->train(Sport::Nfl, Market::Moneyline, burnIn: 50)['version'];

    expect($version->holdout_metrics)->toHaveKeys(['log_loss', 'brier', 'auc', 'verdict', 'verdict_reason'])
        ->and($version->backtest_run_id)->not->toBeNull()
        // A stored version is not a claim the model is any good, so the
        // verdict travels with it.
        ->and($version->holdout_metrics['verdict'])->toBeIn(['proven', 'unproven', 'negative', 'insufficient_data']);
});

it('refuses to store a version it could not measure', function () {
    // No games at all. A row here would be a version number attached to a
    // guess, and something that only checks for a row could then activate it.
    $result = $this->trainer->train(Sport::Nfl, Market::Moneyline, burnIn: 50);

    expect($result['version'])->toBeNull()
        ->and($result['refusal'])->toContain('Nothing was scored out of sample')
        ->and(ModelVersion::count())->toBe(0);
});

it('refuses to store a regression that never reached its training size', function () {
    // 120 games is below the regression's minimum, so it abstains on every
    // one of them. An abstaining model scores nothing, which is what the
    // refusal reports - and nothing is stored either way.
    trainableLeague(120);

    $result = $this->trainer->train(Sport::Nfl, Market::Moneyline, ModelType::Logistic, burnIn: 20);

    expect($result['version'])->toBeNull()
        ->and($result['refusal'])->toContain('Nothing was scored out of sample')
        ->and(ModelVersion::count())->toBe(0);
});

it('numbers each version within its own lineage', function () {
    trainableLeague(400);

    $first = $this->trainer->train(Sport::Nfl, Market::Moneyline, burnIn: 50)['version'];
    $second = $this->trainer->train(Sport::Nfl, Market::Moneyline, burnIn: 50)['version'];

    expect($first->version)->toBe(1)
        ->and($second->version)->toBe(2);
});

it('activates the newest version and retires the one it replaces', function () {
    trainableLeague(400);

    $first = $this->trainer->train(Sport::Nfl, Market::Moneyline, burnIn: 50)['version'];

    expect($first->fresh()->status)->toBe(ModelStatus::Active);

    $second = $this->trainer->train(Sport::Nfl, Market::Moneyline, burnIn: 50)['version'];

    expect($second->fresh()->status)->toBe(ModelStatus::Active)
        ->and($first->fresh()->status)->toBe(ModelStatus::Retired)
        ->and($first->fresh()->retired_at)->not->toBeNull();
});

it('leaves the live model alone when asked for a candidate', function () {
    trainableLeague(400);

    $live = $this->trainer->train(Sport::Nfl, Market::Moneyline, burnIn: 50)['version'];
    $candidate = $this->trainer->train(Sport::Nfl, Market::Moneyline, burnIn: 50, activate: false)['version'];

    expect($candidate->fresh()->status)->toBe(ModelStatus::Candidate)
        ->and($live->fresh()->status)->toBe(ModelStatus::Active);
});

it('can promote a candidate later', function () {
    trainableLeague(400);

    $live = $this->trainer->train(Sport::Nfl, Market::Moneyline, burnIn: 50)['version'];
    $candidate = $this->trainer->train(Sport::Nfl, Market::Moneyline, burnIn: 50, activate: false)['version'];

    $this->trainer->activate($candidate);

    expect($candidate->fresh()->status)->toBe(ModelStatus::Active)
        ->and($live->fresh()->status)->toBe(ModelStatus::Retired);
});

it('keeps separate lineages for separate models', function () {
    trainableLeague(600);

    $elo = $this->trainer->train(Sport::Nfl, Market::Moneyline, ModelType::Elo, burnIn: 50)['version'];
    $logistic = $this->trainer->train(Sport::Nfl, Market::Moneyline, ModelType::Logistic, burnIn: 50)['version'];

    // Activating a logistic model must not retire the Elo one: they are
    // different models of the same market, not successive versions.
    expect($logistic->version)->toBe(1)
        ->and($elo->fresh()->status)->toBe(ModelStatus::Active)
        ->and($logistic->fresh()->status)->toBe(ModelStatus::Active);
});

it('records the date the model was trained through', function () {
    trainableLeague(400);

    $newest = Event::where('status', 'final')->max('starts_at');

    $version = $this->trainer->train(Sport::Nfl, Market::Moneyline, burnIn: 50)['version'];

    // Everything at or before this is history the model has seen; anything
    // after it is what the model is entitled to predict.
    expect($version->trained_through_at->toDateString())
        ->toBe(CarbonImmutable::parse($newest)->toDateString());
});

it('stores the feature schema version for a regression', function () {
    trainableLeague(600);

    $version = $this->trainer->train(Sport::Nfl, Market::Moneyline, ModelType::Logistic, burnIn: 50)['version'];

    expect($version->feature_schema_version)->toBe(FeatureSchema::VERSION)
        ->and($version->params['regression']['weights'])->not->toBeEmpty()
        ->and($version->params['features_state'])->toHaveKey('history');
});

it('says why no calibration map was stored on a small sample', function () {
    trainableLeague(400);

    $version = $this->trainer->train(Sport::Nfl, Market::Moneyline, burnIn: 50)['version'];

    // 400 games leaves fewer than the minimum scored out of sample, and a
    // map fitted on that would be describing noise.
    expect($version->calibration['method'])->toBe('none')
        ->and($version->calibration['reason'])->toContain('out-of-sample predictions');
});

it('validates a calibration map on games the fit never saw', function () {
    trainableLeague(1200);

    $version = $this->trainer->train(Sport::Nfl, Market::Moneyline, burnIn: 50)['version'];

    $calibration = $version->calibration;

    expect($calibration)->toHaveKeys(['fitted_on', 'validated_on', 'calibration_error'])
        ->and($calibration['fitted_on'])->toBeGreaterThan(0)
        ->and($calibration['validated_on'])->toBeGreaterThan(0)
        // Disjoint slices: the fit never saw the games that judged it.
        ->and($calibration['fitted_on'] + $calibration['validated_on'])->toBeLessThanOrEqual($version->holdout_metrics['predictions']);

    // Whichever way it went, the decision is recorded rather than assumed.
    if ($calibration['method'] === 'none') {
        expect($calibration['reason'])->toContain('improved calibration');
    } else {
        expect($calibration['method'])->toBeIn(['isotonic', 'platt'])
            ->and($calibration['calibration_error'][$calibration['method']])
            ->toBeLessThan($calibration['calibration_error']['raw']);
    }
});

it('trains through the queued job and reports on the jobs screen', function () {
    trainableLeague(400);

    dispatch_sync(new TrainModel(Sport::Nfl, Market::Moneyline, burnIn: 50));

    $run = JobRun::where('job_key', 'like', 'model:train:%')->latest('id')->first();

    expect(ModelVersion::count())->toBe(1)
        ->and($run->status)->toBe(JobStatus::Success)
        ->and($run->context['model_version_id'])->toBe(ModelVersion::first()->id)
        ->and($run->context)->toHaveKey('verdict');
});

it('skips rather than fails when there is nothing to train on', function () {
    // A sport that was just added has no history, which is an ordinary
    // state and not a bug worth a red error on the Jobs screen.
    dispatch_sync(new TrainModel(Sport::Nfl, Market::Moneyline, burnIn: 50));

    $run = JobRun::where('job_key', 'like', 'model:train:%')->latest('id')->first();

    expect($run->status)->toBe(JobStatus::Skipped)
        ->and($run->error_message)->toContain('Nothing was scored out of sample')
        ->and(ModelVersion::count())->toBe(0);
});
