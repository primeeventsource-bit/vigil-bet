<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventLineMarker;
use App\Models\PublishedPick;
use App\Models\ShadowPick;
use App\Models\ShadowResult;
use App\Models\ValueBet;
use App\Services\Record\ShadowRecorder;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| The shadow record
|--------------------------------------------------------------------------
|
| What the engine would have bet, had the verdict gate let it. The tests
| that matter here are not about arithmetic - they are about the three
| properties that make it an experiment rather than a track record: it is
| written before kickoff, it is never edited, and it never reaches the
| public record or a customer.
|
*/

it('records a bet the gate declined on its merits', function () {
    $bet = shadowCandidate();

    app(ShadowRecorder::class)->record();

    $pick = ShadowPick::sole();

    expect($pick->value_bet_id)->toBe($bet->id)
        ->and($pick->price_american)->toBe(-110)
        ->and((float) $pick->model_prob)->toBe(0.62)
        // The reason it was declined travels with the row. A shadow
        // record that forgot why it was a shadow would read as a record.
        ->and($pick->market_verdict)->toBe('negative');
});

it('never puts a shadow pick on the public record', function () {
    shadowCandidate();

    app(ShadowRecorder::class)->record();

    /*
     * The property the whole table depends on. PublishedPick says of
     * itself that the public record is the sum of its rows "and nothing
     * else - no backtests, no paper results, no what the model would have
     * said", and this is exactly the thing that sentence excludes.
     */
    expect(ShadowPick::count())->toBe(1)
        ->and(PublishedPick::count())->toBe(0);
});

it('leaves a bet the gate would have taken anyway alone', function () {
    // Nothing was hidden by the gate here, so there is nothing to shadow.
    shadowCandidate(['shadow_recommendation' => 'pass', 'shadow_pass_reason' => 'below_threshold']);

    app(ShadowRecorder::class)->record();

    expect(ShadowPick::count())->toBe(0);
});

it('refuses to record a game that has already started', function () {
    shadowCandidate(hoursToKickoff: -2);

    $outcome = app(ShadowRecorder::class)->record();

    expect(ShadowPick::count())->toBe(0)
        ->and($outcome['skipped']['too_late'])->toBe(1);
});

it('refuses one inside the playable lead, however technically pre-kickoff', function () {
    // Eight minutes out is before kickoff and practically unplayable. An
    // experiment built on prices nobody could take flatters itself.
    // value_bets is append-only, so the row is written already
    // flagged now rather than updated afterwards. events is not, so
    // moving the kickoff is still fine.
    shadowCandidate(hoursToKickoff: 0, overrides: ['flagged_at' => now()]);
    Event::query()->update(['starts_at' => now()->addMinutes(8)]);

    app(ShadowRecorder::class)->record();

    expect(ShadowPick::count())->toBe(0);
});

it('records a game once however often it runs', function () {
    shadowCandidate();

    app(ShadowRecorder::class)->record();
    app(ShadowRecorder::class)->record();
    app(ShadowRecorder::class)->record();

    // Re-running must not pad the sample, which is how an experiment
    // stops measuring anything.
    expect(ShadowPick::count())->toBe(1);
});

it('refuses to be edited or deleted once written', function () {
    shadowCandidate();
    app(ShadowRecorder::class)->record();

    $pick = ShadowPick::sole();

    expect(fn () => $pick->update(['price_american' => -200]))
        ->toThrow(RuntimeException::class, 'append-only');

    expect(fn () => $pick->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});

it('refuses a row dated after kickoff, in the database', function () {
    $event = engineGame(overrides: ['starts_at' => now()->addHours(3)]);

    /*
     * Enforced below the application, because the application is the
     * thing that would be changed by somebody who wanted the numbers to
     * look better. A shadow record that could be written once the result
     * was known would answer nothing at all.
     */
    expect(rejectedByDatabase(fn () => ShadowPick::create([
        'value_bet_id' => engineBoardEntry(null, $event)->id,
        'event_id' => $event->id,
        'sport' => 'nfl',
        'market' => 'h2h',
        'side' => 'home',
        'selection' => 'Home',
        'price_american' => -110,
        'kickoff_at' => now()->addHours(3),
        'recorded_at' => now()->addHours(4),
        'created_at' => now(),
    ])))->toThrow(Exception::class);
});

it('settles against the real result and keeps the closing line value', function () {
    $bet = shadowCandidate(hoursToKickoff: 2);
    app(ShadowRecorder::class)->record();

    $pick = ShadowPick::sole();

    /*
     * The clock moves rather than the row. A shadow pick carries its own
     * kickoff_at and the table refuses edits, which is the whole point -
     * so the only way to reach settlement is the way production reaches
     * it, by the game actually happening.
     */
    $this->travel(4)->hours();

    // Home wins, and the price shortened after we recorded it: the
    // market came toward the pick.
    Event::query()->whereKey($pick->event_id)->update([
        'status' => 'final',
        'final_home_score' => 27,
        'final_away_score' => 20,
    ]);

    EventLineMarker::create([
        'event_id' => $pick->event_id,
        'book_id' => $bet->best_book_id,
        'market' => 'h2h',
        'side' => 'home',
        'price_close_american' => -140,
        'closed_at' => now()->subHours(3),
        'computed_at' => now(),
    ]);

    app(ShadowRecorder::class)->settle();

    $result = ShadowResult::sole();

    expect($result->status)->toBe('won')
        // -110 returns 0.909 units on a win.
        ->and($result->units)->toBe(0.9091)
        ->and($result->closing_price_american)->toBe(-140)
        /*
         * Positive: the implied probability at the close is higher than
         * at the price taken, so the market moved toward the pick. This
         * is the figure worth watching - it does not care that the pick
         * won, and at this sample size whether it won is mostly noise.
         */
        ->and($result->clv)->toBeGreaterThan(0);
});

it('leaves a game with no final score unsettled rather than guessing', function () {
    shadowCandidate(hoursToKickoff: 2);
    app(ShadowRecorder::class)->record();

    $this->travel(4)->hours();

    // Finished, but the score has not arrived yet.
    Event::query()->update(['status' => 'final']);

    $outcome = app(ShadowRecorder::class)->settle();

    expect(ShadowResult::count())->toBe(0)
        ->and($outcome['unresolved'])->toBe(1);
});

it('keeps the shadow page behind the admin gate', function () {
    /*
     * The property the experiment lives or dies on. A customer seeing
     * these numbers would be seeing results the product promises it does
     * not have - and the admin middleware answers 404 rather than 403,
     * so a non-admin cannot even learn the page exists.
     */
    $this->get(route('admin.shadow'))->assertRedirect(route('login'));

    $customer = betUser('pro');
    $this->actingAs($customer)->get(route('admin.shadow'))->assertNotFound();

    $admin = betUser('pro');
    $admin->forceFill(['is_admin' => true])->save();

    $this->actingAs($admin)->get(route('admin.shadow'))
        ->assertOk()
        // Labelled on the page itself, not only in a comment.
        ->assertSee('not results')
        ->assertSee('would have bet');
});

it('shows no win rate until something has been graded', function () {
    $admin = betUser('pro');
    $admin->forceFill(['is_admin' => true])->save();

    shadowCandidate();
    app(ShadowRecorder::class)->record();

    // Recorded but unsettled: a rate here would be a number with nothing
    // behind it, and an unmeasured figure and a zero look identical.
    $this->actingAs($admin)->get(route('admin.shadow'))
        ->assertOk()
        ->assertSee('nothing graded yet');
});

it('groups by sport without selecting ungrouped columns', function () {
    /*
     * This page threw in production while every test passed. The
     * by-sport aggregate was built with `(clone $settled)`, which
     * carries `shadow_results.*` into a grouped query - a plain error
     * on Postgres, silently allowed by SQLite.
     *
     * So the assertion is on the SQL rather than the response: under
     * SQLite the page renders happily with the bug present, and a
     * 200 here would mean nothing. Any grouped query that also asks
     * for whole-table columns fails, which catches the shape rather
     * than this one instance of it.
     */
    $admin = betUser('pro');
    $admin->forceFill(['is_admin' => true])->save();

    shadowCandidate();
    app(ShadowRecorder::class)->record();

    $grouped = [];

    DB::listen(function ($query) use (&$grouped) {
        if (str_contains(mb_strtolower($query->sql), 'group by')) {
            $grouped[] = $query->sql;
        }
    });

    $this->actingAs($admin)->get(route('admin.shadow'))->assertOk();

    // The page does group by something, or the assertion below is vacuous.
    expect($grouped)->not->toBeEmpty();

    foreach ($grouped as $sql) {
        expect($sql)->not->toMatch('/select[^(]*\b[a-z_"]+\.\*/i');
    }
});

it('shows a settled sport in the by-sport table', function () {
    $admin = betUser('pro');
    $admin->forceFill(['is_admin' => true])->save();

    $bet = shadowCandidate(hoursToKickoff: 2);
    app(ShadowRecorder::class)->record();

    $this->travel(4)->hours();

    Event::query()->whereKey($bet->event_id)->update([
        'status' => 'final',
        'final_home_score' => 27,
        'final_away_score' => 20,
    ]);

    app(ShadowRecorder::class)->settle();

    // The table only renders once something is settled, so the empty
    // path was the only one any test had ever exercised.
    $this->actingAs($admin)->get(route('admin.shadow'))
        ->assertOk()
        ->assertSee('By sport')
        ->assertSee('n = 1');
});

it('records a selection once across rescans, not once per scan', function () {
    /*
     * The bug this replaces: the recorder deduplicated on value_bet_id,
     * and the board is rescanned hourly, so each scan minted a fresh
     * value_bets row for the same game at the same price and the same
     * selection was written down again. 92 rows for 31 selections in six
     * hours, each of which would have been settled and counted alone.
     *
     * The existing "records a game once however often it runs" test
     * reused one value bet, so it could never see this.
     */
    $bet = shadowCandidate();
    app(ShadowRecorder::class)->record();

    expect(ShadowPick::count())->toBe(1);

    // The next scan: the same event, side and line, its own value_bets row.
    engineBoardEntry(null, $bet->event, [
        'recommendation' => 'pass',
        'pass_reason' => 'market_unproven',
        'market_verdict' => 'negative',
        'shadow_recommendation' => 'bet',
        'shadow_pass_reason' => null,
        'side' => $bet->side->value,
        'market' => $bet->market->value,
        'model_prob' => 0.62,
        'best_price_american' => -110,
        'edge_at_price' => 0.0962,
        'kelly_fraction' => 0.04,
    ]);

    $outcome = app(ShadowRecorder::class)->record();

    expect(ShadowPick::count())->toBe(1)
        ->and($outcome['recorded'])->toBe(0);
});

it('refuses a duplicate selection in the database, not only in the recorder', function () {
    // The application guard is the one that runs; this is the one that
    // holds when something writes the row by another route.
    $bet = shadowCandidate();
    app(ShadowRecorder::class)->record();

    $first = ShadowPick::sole();

    expect(rejectedByDatabase(fn () => ShadowPick::create([
        'value_bet_id' => $bet->id,
        'event_id' => $first->event_id,
        'sport' => $first->sport,
        'market' => $first->market,
        'side' => $first->side,
        'line' => $first->line,
        'selection' => $first->selection,
        'matchup' => $first->matchup,
        'price_american' => $first->price_american,
        'model_prob' => $first->model_prob,
        'kickoff_at' => $first->kickoff_at,
        'recorded_at' => now(),
        'created_at' => now(),
    ])))->toThrow(QueryException::class);

    expect(ShadowPick::count())->toBe(1);
});

it('counts one bet per selection even with duplicates already stored', function () {
    /*
     * Production holds 61 rows written before the guard existed. They
     * cannot be deleted - the table refuses it, and that refusal is why
     * the table is worth anything - so readers take the earliest row per
     * selection instead. This proves they do.
     */
    $bet = shadowCandidate();
    app(ShadowRecorder::class)->record();

    $first = ShadowPick::sole();

    duplicateOf($first);

    expect(ShadowPick::count())->toBe(2)
        ->and(ShadowPick::query()->canonical()->count())->toBe(1)
        // And the row kept is the first one written, not the later one.
        ->and(ShadowPick::query()->canonical()->sole()->id)->toBe($first->id);

    $admin = betUser('pro');
    $admin->forceFill(['is_admin' => true])->save();

    // The tile reads selections, not rows.
    $this->actingAs($admin)->get(route('admin.shadow'))
        ->assertOk()
        ->assertSeeInOrder(['Recorded', '1']);
});

it('settles a duplicated selection once, not once per duplicate', function () {
    $bet = shadowCandidate(hoursToKickoff: 2);
    app(ShadowRecorder::class)->record();

    $first = ShadowPick::sole();

    duplicateOf($first);

    $this->travel(4)->hours();

    Event::query()->whereKey($first->event_id)->update([
        'status' => 'final',
        'final_home_score' => 27,
        'final_away_score' => 20,
    ]);

    $outcome = app(ShadowRecorder::class)->settle();

    // One graded bet. Two would double the units and the CLV.
    expect($outcome['settled'])->toBe(1)
        ->and(ShadowResult::count())->toBe(1)
        ->and(ShadowResult::sole()->shadow_pick_id)->toBe($first->id);
});

it('records one row when several scans of a selection arrive in the same batch', function () {
    /*
     * This is what broke in production the moment the table was empty.
     *
     * record() excludes selections already recorded, and that query is
     * evaluated once, before anything is written. So three scans' rows
     * for the same selection all passed it together, the second insert
     * was refused by the database guard, and the exception aborted the
     * whole run - nothing at all was recorded.
     *
     * With rows already in the table the pre-check caught them and this
     * was invisible, which is why the earlier dedupe test could not see
     * it: that one calls record() twice, with a write in between.
     */
    $bet = shadowCandidate();

    // Two more scans of the same game, side and line, as the hourly
    // rescan produces them.
    foreach ([-105, -115] as $price) {
        engineBoardEntry(null, $bet->event, [
            'recommendation' => 'pass',
            'pass_reason' => 'market_unproven',
            'market_verdict' => 'negative',
            'shadow_recommendation' => 'bet',
            'shadow_pass_reason' => null,
            'market' => $bet->market->value,
            'side' => $bet->side->value,
            'model_prob' => 0.62,
            'best_price_american' => $price,
            'edge_at_price' => 0.09,
            'kelly_fraction' => 0.04,
        ]);
    }

    expect(ValueBet::query()->where('shadow_recommendation', 'bet')->count())->toBe(3);

    // One batch, three candidates for one selection.
    $outcome = app(ShadowRecorder::class)->record();

    expect($outcome['recorded'])->toBe(1)
        ->and($outcome['skipped']['already_recorded'])->toBe(2)
        ->and(ShadowPick::count())->toBe(1);
});
