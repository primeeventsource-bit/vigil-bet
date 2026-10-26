<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\ModelStatus;
use App\Enums\Recommendation;
use App\Enums\Side;
use App\Enums\Verdict;
use App\Modeling\Odds\OddsConverter;
use App\Models\Book;
use App\Models\Event;
use App\Models\ModelVersion;
use App\Models\ParlayEvaluation;
use App\Models\Team;
use App\Models\ValueBet;
use App\Models\ValueScan;
use App\Models\VlEvent;
use App\Services\EdgeLab\Mirror;
use App\Support\Internal\Present;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Fixtures for the engine seam
|--------------------------------------------------------------------------
|
| Two kinds live here, and the difference matters.
|
| The `*Row` helpers below build a mirror payload: the shape a row has
| once it is in vl_board_entries and the product is reading it. They are
| written out by hand because that is the product's own storage format and
| the tests that use them are about what a screen does with a stored row.
|
| The `engine*` helpers build real engine records - a ValueScan, an Event,
| a ModelVersion - and let App\Support\Internal\Present produce the shape
| from them. Those are for the projection tests, and they are the reason
| this file no longer contains a single hand-written API response.
|
| It used to. When the engine was a second deployment, the projection
| tests faked HTTP and asserted against payloads typed out here, and the
| header of this file said so plainly: "these fixtures are the contract
| written down a second time. If Edge Lab's shape changes, they keep
| passing while production breaks."
|
| That is not a hypothetical any more - it happened. ParlayJudge read
| win_probability, shaded_edge and verdict from the judge's answer. The
| engine calls those model_joint_prob, ev_shaded and recommendation, and
| has never had a field by any of the first three names. The fake agreed
| with the code, the code agreed with the fake, and four of six columns on
| every parlay grade in production were null. One codebase and one
| database removes the gap the bug lived in, so the projection tests now
| seed the engine's own tables and read what the engine actually says.
|
*/

/**
 * A board row as the mirror holds it. Defaults to the honest common case:
 * a pass.
 */
function boardRow(int $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'value_scan_id' => 1,
        'event_id' => 100 + $id,
        'sport' => 'nfl',
        'market' => 'h2h',
        'side' => 'home',
        'line' => null,
        'selection' => 'Buffalo Bills',
        'model_prob' => 0.58,
        'fair_prob' => 0.56,
        'fair_prob_method' => 'multiplicative',
        'break_even_win_rate' => 0.583,
        'edge' => 0.02,
        'edge_at_price' => -0.003,
        'ev_per_dollar' => -0.01,
        'best_book' => 'hardrockbet',
        'best_book_title' => 'Hard Rock Bet',
        'best_price_american' => -140,
        'better_elsewhere' => false,
        'book_count' => 5,
        'price_pulled_at' => now()->subMinutes(3)->toIso8601String(),
        'break_even_price_american' => -138,
        'min_acceptable_price_american' => -120,
        'kelly_fraction' => null,
        'suggested_stake_cents' => 0,
        'suggested_stake_units' => 0,
        'threshold_used' => 0.03,
        'market_verdict' => 'insufficient_data',
        'recommendation' => 'pass',
        'pass_reason' => 'market_unproven',
        'reason' => 'Model unproven here.',
        'probability_rank' => null,
        'value_rank' => null,
        'model_version_id' => 7,
        'prediction_id' => 500 + $id,
        'flagged_at' => now()->subHour()->toIso8601String(),
        'created_at' => now()->subHour()->toIso8601String(),
    ], $overrides);
}

function eventRow(int $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'sport' => 'nfl',
        'starts_at' => now()->addHours(4)->toIso8601String(),
        'status' => 'scheduled',
        'season' => 2026,
        'week' => 8,
        'neutral_site' => false,
        'home' => ['id' => 1, 'name' => 'Buffalo Bills', 'abbreviation' => 'BUF'],
        'away' => ['id' => 2, 'name' => 'Miami Dolphins', 'abbreviation' => 'MIA'],
        'final_home_score' => null,
        'final_away_score' => null,
        'completed_at' => null,
    ], $overrides);
}

/**
 * The game a board entry points at, mirrored.
 *
 * Needed because the dashboard only offers picks on games that have not
 * started, and the only way it can know that is to read the event. A
 * board entry with no event is a pick on a game nothing knows about,
 * and every surface that filters by kickoff is right to drop it.
 *
 * Four test files grew their own board-entry helper and three of them
 * wrote no event at all, so this lives here rather than in any of them.
 */
function mirrorEvent(int $edgeLabId, array $overrides = []): void
{
    // A test that wrote its own event meant it. This fills a gap,
    // it does not take over.
    if (VlEvent::query()->where('edge_lab_id', $edgeLabId)->exists()) {
        return;
    }

    $row = eventRow($edgeLabId, $overrides);

    app(Mirror::class)->write(VlEvent::class, $row, [
        'edge_lab_id' => (int) $row['id'],
        'sport' => $row['sport'],
        'starts_at' => Carbon::parse((string) $row['starts_at']),
        'status' => $row['status'],
        'home_name' => $row['home']['name'] ?? null,
        'home_abbreviation' => $row['home']['abbreviation'] ?? null,
        'away_name' => $row['away']['name'] ?? null,
        'away_abbreviation' => $row['away']['abbreviation'] ?? null,
        'final_home_score' => $row['final_home_score'],
        'final_away_score' => $row['final_away_score'],
    ]);
}

function modelRow(int $id, string $verdict = 'insufficient_data', array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'sport' => 'nfl',
        'market' => 'h2h',
        'model_type' => 'logistic',
        'version' => 1,
        'label' => null,
        'status' => 'active',
        'describe' => 'NFL Moneyline logistic v1',
        'verdict' => $verdict,
        'verdict_reason' => 'Still collecting closed games before a verdict is possible.',
        'allows_picks' => $verdict === 'proven',
        'predictions' => 120,
        'log_loss' => 0.68,
        'market_log_loss' => 0.67,
        'log_loss_improvement' => -0.01,
        'calibration_error' => 0.02,
        'market_calibration_error' => 0.01,
        'brier' => 0.24,
        'gate' => [
            'min_predictions' => 300,
            'min_log_loss_improvement' => 0.0,
            'min_t_stat' => 1.64,
            'calibration_tolerance' => 0.01,
        ],
        'training_rows' => 5000,
        'trained_at' => now()->subWeek()->toIso8601String(),
        'trained_through_at' => now()->subWeek()->toIso8601String(),
        'activated_at' => now()->subWeek()->toIso8601String(),
        'retired_at' => null,
        'backtest_run_id' => 3,
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Real engine records
|--------------------------------------------------------------------------
*/

/**
 * A game in the engine's schedule, with two teams of its own.
 *
 * Fresh teams per call, and a kickoff nudged by the caller, because
 * `events` is uniquely keyed on sport, kickoff and the two sides - two
 * fixtures that happened to pick the same values would be refused by the
 * database rather than quietly collapsing into one row.
 */
function engineGame(string $home = 'Buffalo Bills', string $away = 'Miami Dolphins', array $overrides = []): Event
{
    $salt = Str::random(6);

    $homeTeam = Team::create([
        'sport' => $overrides['sport'] ?? 'nfl',
        'name' => $home,
        'display_name' => $home,
        'abbreviation' => mb_strtoupper(mb_substr($home, 0, 3)),
        'slug' => Str::slug($home).'-'.$salt,
    ]);

    $awayTeam = Team::create([
        'sport' => $overrides['sport'] ?? 'nfl',
        'name' => $away,
        'display_name' => $away,
        'abbreviation' => mb_strtoupper(mb_substr($away, 0, 3)),
        'slug' => Str::slug($away).'-'.$salt,
    ]);

    return Event::create(array_merge([
        'sport' => 'nfl',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'starts_at' => now()->addHours(4),
        'status' => 'scheduled',
        'season' => 2026,
        'week' => 8,
    ], $overrides));
}

/** A scan, which is what a board row belongs to. */
function engineScan(): ValueScan
{
    return ValueScan::create([
        'scanned_at' => now()->subMinutes(5),
        'settings' => ['edge_threshold' => 0.03],
        'bankroll_cents' => 500000,
        'candidates' => 0,
        'picks' => 0,
        'created_at' => now(),
    ]);
}

/**
 * One evaluated candidate on the engine's board.
 *
 * A pass by default, with an unproven market behind it, because that is
 * what nearly every real row is and a fixture that defaulted to a sellable
 * pick would make the gate look permissive in every test that used it.
 */
function engineBoardEntry(?ValueScan $scan = null, ?Event $event = null, array $overrides = []): ValueBet
{
    $scan ??= engineScan();
    $event ??= engineGame();

    $book = Book::query()->firstOrCreate(
        ['key' => 'hardrockbet'],
        ['title' => 'Hard Rock Bet', 'is_tracked' => true, 'is_bettable' => true],
    );

    $price = (int) ($overrides['best_price_american'] ?? -140);
    $modelProb = (float) ($overrides['model_prob'] ?? 0.58);
    $fairProb = (float) ($overrides['fair_prob'] ?? 0.56);

    return ValueBet::create(array_merge([
        'value_scan_id' => $scan->id,
        'event_id' => $event->id,
        'sport' => $event->sport->value,
        'market' => Market::Moneyline->value,
        'side' => Side::Home->value,
        'model_prob' => $modelProb,
        'fair_prob' => $fairProb,
        'break_even_win_rate' => OddsConverter::americanToProbability($price),
        'edge' => round($modelProb - $fairProb, 6),
        'edge_at_price' => round($modelProb - OddsConverter::americanToProbability($price), 6),
        'ev_per_dollar' => -0.01,
        'best_book_id' => $book->id,
        'best_price_american' => $price,
        'book_count' => 5,
        'price_pulled_at' => now()->subMinutes(3),
        'market_verdict' => Verdict::Insufficient->value,
        'recommendation' => Recommendation::Pass->value,
        'pass_reason' => 'market_unproven',
        'reason' => 'Model unproven here.',
        'threshold_used' => 0.03,
        'suggested_stake_cents' => 0,
        'flagged_at' => now()->subMinutes(5),
        'created_at' => now(),
    ], $overrides));
}

/** A trained model version and the verdict its gate produced. */
function engineModelVersion(string $verdict = 'insufficient_data', array $overrides = []): ModelVersion
{
    return ModelVersion::create(array_merge([
        'sport' => 'nfl',
        'market' => Market::Moneyline->value,
        'model_type' => 'logistic',
        'version' => 1,
        'params' => ['weights' => []],
        'trained_at' => now()->subWeek(),
        'trained_through_at' => now()->subWeek(),
        'training_rows' => 5000,
        'holdout_metrics' => [
            'verdict' => $verdict,
            'verdict_reason' => 'Still collecting closed games before a verdict is possible.',
            'predictions' => 120,
            'log_loss' => 0.68,
            'market_log_loss' => 0.67,
            'log_loss_improvement' => -0.01,
            'calibration_error' => 0.02,
            'market_calibration_error' => 0.01,
            'brier' => 0.24,
        ],
        'status' => ModelStatus::Active->value,
        'activated_at' => now()->subWeek(),
    ], $overrides));
}

/**
 * A parlay verdict in the engine's own shape.
 *
 * Built by Present::parlay() from an unsaved ParlayEvaluation rather than
 * typed out, so the field names in a test are the field names in the
 * database by construction. This is the fixture whose hand-written
 * ancestor hid the ParlayJudge mapping bug described at the top of this
 * file.
 */
function engineParlayVerdict(array $overrides = []): array
{
    $parlay = new ParlayEvaluation(array_merge([
        'leg_count' => 3,
        'sport' => 'nfl',
        'legs' => [],
        'correlated' => false,
        'model_joint_prob' => 0.186,
        'fair_joint_prob' => 0.1856,
        'shaded_joint_prob' => 0.1702,
        'shade_used' => 0.05,
        'offered_price_american' => 380,
        'offered_decimal' => 4.8,
        'break_even' => 0.2083,
        'hold' => 0.0925,
        'hold_per_leg' => 0.0474,
        'ev_per_dollar' => -0.0213,
        'ev_shaded' => -0.041,
        'kelly_fraction' => null,
        'suggested_stake_cents' => 0,
        'recommendation' => Recommendation::Pass->value,
        'reason' => 'The book keeps too much of this one.',
        'evaluated_at' => now(),
    ], $overrides));

    return Present::parlay($parlay) + ['stored' => false];
}
