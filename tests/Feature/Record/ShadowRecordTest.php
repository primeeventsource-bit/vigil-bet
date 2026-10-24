<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventLineMarker;
use App\Models\PublishedPick;
use App\Models\ShadowPick;
use App\Models\ShadowResult;
use App\Models\ValueBet;
use App\Services\Record\ShadowRecorder;

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
    shadowCandidate(hoursToKickoff: 0);
    ValueBet::query()->update(['flagged_at' => now()]);
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
    expect(fn () => ShadowPick::create([
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
    ]))->toThrow(Exception::class);
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
