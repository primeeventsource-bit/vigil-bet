<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\Sport;
use App\Models\BacktestRun;
use App\Models\Book;
use App\Models\CalibrationBin;
use App\Models\Event;
use App\Models\EventLineMarker;
use App\Models\Team;
use App\Services\Modeling\BacktestService;

/**
 * A synthetic league where home teams really are stronger, so a working
 * Elo implementation has something genuine to find.
 */
function seedLeague(int $games, bool $withMarket = true): void
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

        // Stronger teams have lower index and score more.
        $homeStrength = 8 - array_search($home, $teams, true);
        $awayStrength = 8 - array_search($away, $teams, true);
        $homeScore = 17 + $homeStrength + ($i % 5);
        $awayScore = 17 + $awayStrength + (($i * 3) % 5);

        $event = Event::create([
            'sport' => 'nfl',
            'home_team_id' => $home,
            'away_team_id' => $away,
            'starts_at' => $day->copy()->addDays($i),
            'season' => 2023 + intdiv($i, 100),
            'status' => 'final',
            'final_home_score' => $homeScore,
            'final_away_score' => $awayScore,
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

it('records a backtest run with its verdict', function () {
    seedLeague(400);

    $result = app(BacktestService::class)->run(Sport::Nfl, Market::Moneyline, burnIn: 50);

    $run = BacktestRun::first();

    expect($run)->not->toBeNull()
        ->and($run->sport)->toBe(Sport::Nfl)
        ->and($run->n_predictions)->toBeGreaterThan(0)
        ->and($run->verdict)->not->toBeNull()
        ->and($run->metrics)->toHaveKeys(['log_loss', 'brier', 'improvement_t_stat']);
});

it('stores the calibration curve for plotting', function () {
    seedLeague(400);

    app(BacktestService::class)->run(Sport::Nfl, Market::Moneyline, burnIn: 50);

    expect(CalibrationBin::where('owner_type', 'backtest_run')->count())->toBeGreaterThan(0);
});

it('scores nothing when there are no completed games', function () {
    $result = app(BacktestService::class)->run(Sport::Nfl, Market::Moneyline, burnIn: 50);

    expect($result['summary']->predictions)->toBe(0)
        ->and($result['verdict']->verdict->value)->toBe('insufficient_data');
});

it('reports no benchmark when nothing has a closing line', function () {
    seedLeague(400, withMarket: false);

    $result = app(BacktestService::class)->run(Sport::Nfl, Market::Moneyline, burnIn: 50);

    expect($result['summary']->withMarket)->toBe(0)
        ->and($result['summary']->marketLogLoss)->toBeNull()
        ->and($result['verdict']->reason)->toContain('no benchmark');
});

it('keeps tuning and scoring on different games', function () {
    // The sweep picks K on early history; the reported metrics come only
    // from games the sweep never saw. Otherwise a parameter search becomes
    // a sales pitch.
    seedLeague(600);

    $tuned = app(BacktestService::class)->run(Sport::Nfl, Market::Moneyline, tune: true, burnIn: 50);
    $plain = app(BacktestService::class)->run(Sport::Nfl, Market::Moneyline, burnIn: 50);

    expect($tuned['params']['tuned'])->toBeTrue()
        ->and($tuned['params']['tuning_games'])->toBeGreaterThan(0)
        // Fewer games are scored, because the early ones were spent tuning.
        ->and($tuned['summary']->predictions)->toBeLessThan($plain['summary']->predictions);
});

it('learns that stronger teams win', function () {
    seedLeague(400);

    $result = app(BacktestService::class)->run(Sport::Nfl, Market::Moneyline, burnIn: 100);

    // Ordering power is the minimum bar for a rating system that works.
    expect($result['summary']->auc)->toBeGreaterThan(0.6);
});
