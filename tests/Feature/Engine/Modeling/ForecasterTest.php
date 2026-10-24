<?php

declare(strict_types=1);

use App\Enums\JobStatus;
use App\Enums\Market;
use App\Enums\ModelStatus;
use App\Enums\ModelType;
use App\Enums\Side;
use App\Enums\Sport;
use App\Exceptions\AppendOnlyViolation;
use App\Jobs\Modeling\PredictUpcoming;
use App\Models\Event;
use App\Models\FeatureSet;
use App\Models\JobRun;
use App\Models\ModelVersion;
use App\Models\Prediction;
use App\Models\Team;
use App\Services\Modeling\Forecaster;
use App\Services\Modeling\ModelTrainer;

function league(int $games): array
{
    $teams = [];

    foreach (range(1, 8) as $i) {
        $teams[] = Team::create([
            'sport' => 'nfl', 'name' => "T{$i}", 'display_name' => "Team {$i}", 'slug' => "team-{$i}",
        ])->id;
    }

    $day = now()->subYears(2);

    for ($i = 0; $i < $games; $i++) {
        $home = $teams[$i % 8];
        $away = $teams[($i + 3) % 8];

        if ($home === $away) {
            continue;
        }

        $homeStrength = 8 - array_search($home, $teams, true);
        $awayStrength = 8 - array_search($away, $teams, true);

        Event::create([
            'sport' => 'nfl',
            'home_team_id' => $home,
            'away_team_id' => $away,
            'starts_at' => $day->copy()->addDays($i),
            'season' => 2023 + intdiv($i, 100),
            'status' => 'final',
            'final_home_score' => 17 + $homeStrength + ($i % 5),
            'final_away_score' => 17 + $awayStrength + (($i * 3) % 5),
        ]);
    }

    return $teams;
}

/** A game that has not been played, so the model is allowed to price it. */
function upcomingGame(array $teams, int $hoursAhead = 24, int $homeIndex = 0, int $awayIndex = 7): Event
{
    return Event::create([
        'sport' => 'nfl',
        'home_team_id' => $teams[$homeIndex],
        'away_team_id' => $teams[$awayIndex],
        'starts_at' => now()->addHours($hoursAhead),
        'season' => 2026,
        'status' => 'scheduled',
    ]);
}

function trainNfl(ModelType $model = ModelType::Elo): ModelVersion
{
    return app(ModelTrainer::class)->train(Sport::Nfl, Market::Moneyline, $model, burnIn: 50)['version'];
}

beforeEach(function () {
    $this->forecaster = app(Forecaster::class);
});

it('refuses to predict without an active model', function () {
    $teams = league(400);
    upcomingGame($teams);

    $result = $this->forecaster->forecast(Sport::Nfl);

    expect($result['refusal'])->toContain('No active model')
        ->and(Prediction::count())->toBe(0);
});

it('prices an upcoming game with the active model', function () {
    $teams = league(400);
    $version = trainNfl();
    $event = upcomingGame($teams);

    $result = $this->forecaster->forecast(Sport::Nfl);

    expect($result['written'])->toBe(1)
        ->and(Prediction::count())->toBe(2);

    $home = Prediction::where('side', Side::Home)->first();

    expect($home->event_id)->toBe($event->id)
        ->and($home->model_version_id)->toBe($version->id)
        ->and($home->market)->toBe(Market::Moneyline)
        ->and($home->prob)->toBeGreaterThan(0.0)->toBeLessThan(1.0);
});

it('prices both sides of the moneyline to one', function () {
    $teams = league(400);
    trainNfl();
    upcomingGame($teams);

    $this->forecaster->forecast(Sport::Nfl);

    $home = Prediction::where('side', Side::Home)->first();
    $away = Prediction::where('side', Side::Away)->first();

    // A tie pushes the bet rather than paying a side, so the pair splits
    // the games that produce a winner.
    expect($home->prob + $away->prob)->toEqualWithDelta(1.0, 1e-9);
});

it('favours the stronger side', function () {
    // Team 1 is the strongest in this synthetic league and team 8 the
    // weakest, so a rating system that works has to make team 1 at home a
    // favourite. Without this the rest of these tests would pass on noise.
    $teams = league(400);
    trainNfl();
    upcomingGame($teams, homeIndex: 0, awayIndex: 7);

    $this->forecaster->forecast(Sport::Nfl);

    expect(Prediction::where('side', Side::Home)->first()->prob)->toBeGreaterThan(0.5);
});

it('stamps every prediction before kickoff', function () {
    $teams = league(400);
    trainNfl();
    $event = upcomingGame($teams);

    $this->forecaster->forecast(Sport::Nfl);

    foreach (Prediction::all() as $prediction) {
        expect($prediction->predicted_at->lessThan($prediction->event_starts_at))->toBeTrue()
            ->and($prediction->event_starts_at->toIso8601String())
            ->toBe($event->starts_at->toIso8601String());
    }
});

it('never revises a prediction once written', function () {
    $teams = league(400);
    trainNfl();
    upcomingGame($teams);

    $this->forecaster->forecast(Sport::Nfl);

    $prediction = Prediction::first();

    expect(fn () => $prediction->update(['prob' => 0.99]))->toThrow(AppendOnlyViolation::class);
});

it('leaves a game that has already started alone', function () {
    $teams = league(400);
    trainNfl();
    upcomingGame($teams, hoursAhead: -2);

    $result = $this->forecaster->forecast(Sport::Nfl);

    expect($result['events'])->toBe(0)
        ->and(Prediction::count())->toBe(0);
});

it('ignores games beyond the horizon', function () {
    $teams = league(400);
    trainNfl();
    upcomingGame($teams, hoursAhead: 24);
    upcomingGame($teams, hoursAhead: 24 * 10);

    $result = $this->forecaster->forecast(Sport::Nfl, horizonHours: 72);

    expect($result['events'])->toBe(1)
        ->and($result['written'])->toBe(1);
});

it('writes one prediction per game per model version', function () {
    $teams = league(400);
    trainNfl();
    upcomingGame($teams);

    $this->forecaster->forecast(Sport::Nfl);
    $second = $this->forecaster->forecast(Sport::Nfl);

    expect($second['written'])->toBe(0)
        ->and($second['already_predicted'])->toBe(1)
        ->and(Prediction::count())->toBe(2);
});

it('predicts afresh when a new version is activated', function () {
    $teams = league(400);
    $first = trainNfl();
    upcomingGame($teams);

    $this->forecaster->forecast(Sport::Nfl);

    $second = trainNfl();

    expect($first->fresh()->status)->toBe(ModelStatus::Retired);

    $result = $this->forecaster->forecast(Sport::Nfl);

    // The old rows stand - history is never rewritten - and the new version
    // gets its own, so each number stays attributable to a model.
    expect($result['written'])->toBe(1)
        ->and(Prediction::where('model_version_id', $first->id)->count())->toBe(2)
        ->and(Prediction::where('model_version_id', $second->id)->count())->toBe(2);
});

it('catches the model up with games played since it was trained', function () {
    $teams = league(400);
    $version = trainNfl();

    // A result that landed after training. Elo ratings are state, not
    // parameters, so the model has to be told before it prices anything.
    Event::create([
        'sport' => 'nfl',
        'home_team_id' => $teams[7],
        'away_team_id' => $teams[0],
        'starts_at' => $version->trained_through_at->copy()->addDay(),
        'season' => 2026,
        'status' => 'final',
        'final_home_score' => 40,
        'final_away_score' => 3,
    ]);

    upcomingGame($teams);

    $result = $this->forecaster->forecast(Sport::Nfl);

    expect($result['caught_up_with'])->toBe(1)
        ->and(Prediction::first()->components['caught_up_with'])->toBe(1);
});

it('records how the number was made', function () {
    $teams = league(400);
    trainNfl();
    upcomingGame($teams);

    $this->forecaster->forecast(Sport::Nfl);

    $components = Prediction::where('side', Side::Home)->first()->components;

    expect($components)->toHaveKeys([
        'home_rating', 'away_rating', 'raw_probability', 'calibration',
        'margin_sigma', 'implied_margin', 'caught_up_with', 'trained_through',
    ])
        // The total is a league baseline and says so, because Elo and the
        // feature model only ever knew about margins.
        ->and($components['total_is_baseline'])->toBeTrue();
});

it('states a projected margin consistent with the probability it priced', function () {
    $teams = league(400);
    trainNfl();
    upcomingGame($teams, homeIndex: 0, awayIndex: 7);

    $this->forecaster->forecast(Sport::Nfl);

    $home = Prediction::where('side', Side::Home)->first();

    // The margin is a restatement of the probability under the sport's
    // margin spread, so a favourite must project positive and the two can
    // never disagree about who is ahead.
    expect($home->prob)->toBeGreaterThan(0.5)
        ->and($home->projected_margin)->toBeGreaterThan(0.0)
        ->and($home->sigma)->toBeGreaterThan(0.0)
        ->and($home->projected_home_score)->toBeGreaterThan($home->projected_away_score);
});

it('writes no spread or total rows', function () {
    // Deliberate: the moneyline is the only market a model here has been
    // trained and measured on. The projection is recorded so a spread can
    // be priced the day a cover model is actually backtested.
    $teams = league(400);
    trainNfl();
    upcomingGame($teams);

    $this->forecaster->forecast(Sport::Nfl);

    expect(Prediction::where('market', '!=', Market::Moneyline->value)->count())->toBe(0);
});

it('stores the feature vector behind a regression prediction', function () {
    $teams = league(600);
    trainNfl(ModelType::Logistic);
    upcomingGame($teams);

    $this->forecaster->forecast(Sport::Nfl);

    $home = Prediction::where('side', Side::Home)->first();
    $set = FeatureSet::find($home->feature_set_id);

    expect($set)->not->toBeNull()
        ->and($set->payload['features'])->toHaveKey('elo_logit')
        // The leakage guarantee, recorded rather than asserted: everything
        // that fed this vector had already happened before kickoff.
        ->and($set->max_source_at->lessThan($set->event_starts_at))->toBeTrue()
        ->and($home->feature_contributions)->not->toBeNull();
});

it('attributes the explanation to the side it describes', function () {
    $teams = league(600);
    trainNfl(ModelType::Logistic);
    upcomingGame($teams);

    $this->forecaster->forecast(Sport::Nfl);

    expect(Prediction::where('side', Side::Home)->first()->feature_contributions)->not->toBeNull()
        ->and(Prediction::where('side', Side::Away)->first()->feature_contributions)->toBeNull();
});

it('runs through the queued job and reports on the jobs screen', function () {
    $teams = league(400);
    trainNfl();
    upcomingGame($teams);

    dispatch_sync(new PredictUpcoming(Sport::Nfl));

    $run = JobRun::where('job_key', 'model:predict')->latest('id')->first();

    expect(Prediction::count())->toBe(2)
        ->and($run->status)->toBe(JobStatus::Success)
        ->and($run->records_out)->toBe(1)
        ->and($run->context)->toHaveKey('version');
});

it('skips rather than fails when a sport has no model', function () {
    dispatch_sync(new PredictUpcoming(Sport::Nba));

    $run = JobRun::where('job_key', 'model:predict')->latest('id')->first();

    expect($run->status)->toBe(JobStatus::Skipped)
        ->and($run->error_message)->toContain('No active model');
});

it('offers no opinion on a preseason game', function () {
    // Same reason it is not trained on: the rating describes a team that
    // intends to win, and a preseason lineup is not that team. A price on
    // it would be a confident number about something the model has never
    // learned, which the board would then be entitled to bet.
    $teams = league(400);
    trainNfl();

    upcomingGame($teams)->forceFill(['season_type' => 'pre'])->save();

    $result = $this->forecaster->forecast(Sport::Nfl);

    expect($result['written'])->toBe(0)
        ->and(Prediction::count())->toBe(0);
});

it('still prices the postseason and anything unlabelled', function () {
    $teams = league(400);
    trainNfl();

    upcomingGame($teams, 24)->forceFill(['season_type' => 'post'])->save();
    upcomingGame($teams, 48, 1, 6)->forceFill(['season_type' => null])->save();

    expect($this->forecaster->forecast(Sport::Nfl)['written'])->toBe(2);
});
