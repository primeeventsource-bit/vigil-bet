<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Http;

/**
 * Responses shaped exactly as edge-lab/docs/INTERNAL_API.md describes.
 *
 * Worth saying plainly: these fixtures are the contract written down a
 * second time. If Edge Lab's shape changes, they keep passing while
 * production breaks - the ordinary failure of testing against a fake.
 * What they do prove is everything on Vigil's side of the wire: the
 * cursor, the breaker, the digest and the gate, which is where the logic
 * actually lives.
 */
function edgeLabPage(array $rows, ?int $nextSince = null, ?int $since = null): array
{
    return [
        'data' => $rows,
        'meta' => [
            'count' => count($rows),
            'since' => $since,
            'next_since' => $nextSince,
            'as_of' => now()->toIso8601String(),
        ],
    ];
}

/** A board row. Defaults to the honest common case: a pass. */
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

/**
 * A fake that honours the keyset cursor, as the real API does.
 *
 * A fake that ignores `since` and answers with the same page forever will
 * let a client loop on it without ever noticing, which is exactly the bug
 * the cursor exists to prevent. This one returns only rows whose id is
 * greater than the cursor, and `next_since: null` once there are none -
 * so a client that mishandles the cursor hangs the test rather than
 * passing it.
 *
 * @param  array<int, array<string, mixed>>  $allRows  ordered by id
 */
function cursorAwareFake(array $allRows): Closure
{
    return function ($request) use ($allRows) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $since = isset($query['since']) ? (int) $query['since'] : 0;
        $limit = isset($query['limit']) ? (int) $query['limit'] : 200;

        $rows = array_values(array_filter($allRows, fn (array $r) => (int) $r['id'] > $since));
        $page = array_slice($rows, 0, $limit);
        $last = $page === [] ? null : (int) $page[count($page) - 1]['id'];

        return Http::response(edgeLabPage(
            $page,
            // Null once caught up, which is what the contract promises.
            nextSince: count($rows) > count($page) ? $last : null,
            since: $since ?: null,
        ));
    };
}
