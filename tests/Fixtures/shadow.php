<?php

declare(strict_types=1);

use App\Models\ShadowPick;
use App\Models\ValueBet;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The shadow record
|--------------------------------------------------------------------------
|
| Shared because more than one test file needs them. They lived in
| ShadowRecordTest.php, which meant any other file using them only
| worked when that one loaded first - green in the suite, broken on its
| own.
|
*/

/** A value bet the gate declined but the merits would have taken. */
function shadowCandidate(array $overrides = [], int $hoursToKickoff = 6): ValueBet
{
    $event = engineGame(overrides: ['starts_at' => now()->addHours($hoursToKickoff)]);

    return engineBoardEntry(null, $event, array_merge([
        // Declined, and declined for the one reason the experiment is about.
        'recommendation' => 'pass',
        'pass_reason' => 'market_unproven',
        'market_verdict' => 'negative',
        'shadow_recommendation' => 'bet',
        'shadow_pass_reason' => null,
        'model_prob' => 0.62,
        'best_price_american' => -110,
        'edge_at_price' => 0.0962,
        'kelly_fraction' => 0.04,
    ], $overrides));
}

/**
 * A duplicate written the way the real ones got there: before the guard.
 *
 * Production holds 61 of these from the hours between the recorder being
 * wrong and the trigger being added. They cannot be deleted, so the
 * readers have to cope with them, so the tests have to produce them -
 * and the only honest way is with the guard off, which is the state the
 * database was actually in when they were written.
 */
function duplicateOf(ShadowPick $pick): void
{
    DB::getDriverName() === 'pgsql'
        ? DB::unprepared('DROP TRIGGER IF EXISTS shadow_picks_one_per_selection ON shadow_picks')
        : DB::unprepared('DROP TRIGGER IF EXISTS shadow_picks_one_per_selection');

    /*
     * Its own value bet, because `value_bet_id` is unique - which is how
     * the real duplicates look too: one per rescan, each pointing at that
     * scan's own row for the same selection.
     */
    $rescan = engineBoardEntry(null, $pick->event, [
        'recommendation' => 'pass',
        'pass_reason' => 'market_unproven',
        'market_verdict' => 'negative',
        'shadow_recommendation' => 'bet',
        'shadow_pass_reason' => null,
        'market' => $pick->market,
        'side' => $pick->side,
        'line' => $pick->line,
        'model_prob' => $pick->model_prob,
        'best_price_american' => $pick->price_american,
    ]);

    DB::table('shadow_picks')->insert([
        'value_bet_id' => $rescan->id,
        'event_id' => $pick->event_id,
        'sport' => $pick->sport,
        'market' => $pick->market,
        'side' => $pick->side,
        'line' => $pick->line,
        'selection' => $pick->selection,
        'matchup' => $pick->matchup,
        'price_american' => $pick->price_american,
        'model_prob' => $pick->model_prob,
        'kickoff_at' => $pick->kickoff_at,
        'recorded_at' => now(),
        'created_at' => now(),
    ]);
}
