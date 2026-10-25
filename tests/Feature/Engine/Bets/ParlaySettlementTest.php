<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Settling a parlay
|--------------------------------------------------------------------------
|
| The parlay builder kept its slip in the session, graded it for value and
| forgot it. That answers "is this worth taking" and never "how have mine
| done", which is the question a tracker exists for.
|
| Most of these tests are about the two ways a home-made tracker gets
| parlays wrong. The first is treating a pushed leg as a loss, which
| understates a bettor's record more than any other single mistake. The
| second is settling on a price the sportsbook never offered.
|
*/

use App\Models\ParlayLeg;
use App\Models\User;
use App\Models\UserParlay;
use App\Services\Parlay\ParlaySettler;

function parlayFor(array $legs, array $attributes = []): UserParlay
{
    $user = User::factory()->create();

    $parlay = UserParlay::create(array_merge([
        'user_id' => $user->id,
        'stake_cents' => 10000,
        'computed_price_american' => 600,
        'accepted_price_american' => 600,
        'status' => 'pending',
        'placed_at' => now()->subDay(),
    ], $attributes));

    foreach ($legs as $i => $leg) {
        ParlayLeg::create([
            'user_parlay_id' => $parlay->id,
            'sport' => 'nfl',
            'market' => 'h2h',
            'side' => 'home',
            'selection' => 'Leg '.$i,
            'price_american' => $leg['price'] ?? -110,
            'status' => $leg['status'],
        ]);
    }

    return $parlay->fresh('legs');
}

function settle(UserParlay $parlay): UserParlay
{
    app(ParlaySettler::class)->settleParlay($parlay);

    return $parlay->fresh('legs');
}

it('holds a parlay while any leg is still running', function (): void {
    $parlay = settle(parlayFor([
        ['status' => 'won'],
        ['status' => 'pending'],
    ]));

    expect($parlay->status)->toBe('pending')
        ->and($parlay->return_cents)->toBeNull()
        ->and($parlay->settled_at)->toBeNull();
});

it('loses the moment one leg loses, without waiting for the rest', function (): void {
    /*
     * A lost leg decides a parlay on its own, so there is nothing to
     * wait for. Holding it pending would leave a known loss sitting in
     * the pending column and overstate what is still live.
     */
    $parlay = settle(parlayFor([
        ['status' => 'lost'],
        ['status' => 'pending'],
    ]));

    expect($parlay->status)->toBe('lost')
        ->and($parlay->return_cents)->toBe(0);
});

it('pays out on the price the sportsbook accepted, not the one we computed', function (): void {
    /*
     * The accepted price is routinely worse than the product of the legs
     * and occasionally better. Settling on our own figure would invent
     * money the user never had.
     */
    $parlay = settle(parlayFor([
        ['status' => 'won'],
        ['status' => 'won'],
    ], ['computed_price_american' => 600, 'accepted_price_american' => 500]));

    // $100 at +500 returns $600 including the stake.
    expect($parlay->status)->toBe('won')
        ->and($parlay->return_cents)->toBe(60000)
        ->and($parlay->profitCents())->toBe(50000);
});

it('falls back to the computed price when no book price was recorded', function (): void {
    $parlay = settle(parlayFor([
        ['status' => 'won'],
        ['status' => 'won'],
    ], ['accepted_price_american' => null, 'computed_price_american' => 300]));

    expect($parlay->return_cents)->toBe(40000);
});

/*
 * The pushed leg. Every sportsbook drops it and reprices the parlay on
 * what is left; a tracker that counts it as a loss understates its owner.
 */
it('drops a pushed leg and settles the parlay on what is left', function (): void {
    $parlay = settle(parlayFor([
        ['status' => 'won', 'price' => 100],
        ['status' => 'push', 'price' => 100],
    ], ['accepted_price_american' => 300, 'computed_price_american' => 300]));

    /*
     * +300 is decimal 4.0, the product of two 2.0 legs. Removing one
     * divides it out, leaving 2.0 - which is what the book does when it
     * voids a leg, and it keeps the book's margin spread over the legs
     * that remain rather than handing it back.
     */
    expect($parlay->status)->toBe('won')
        ->and($parlay->return_cents)->toBe(20000);
});

it('treats a voided leg the same way, since neither ran', function (): void {
    $parlay = settle(parlayFor([
        ['status' => 'won', 'price' => 100],
        ['status' => 'void', 'price' => 100],
    ], ['accepted_price_american' => 300, 'computed_price_american' => 300]));

    expect($parlay->status)->toBe('won')
        ->and($parlay->return_cents)->toBe(20000);
});

it('returns the stake when every leg pushed, which is not a win', function (): void {
    /*
     * Recorded as a push rather than a win. The money is the same and the
     * record is not: a parlay where nothing was decided did not beat
     * anybody, and counting it as a win would inflate a win rate with
     * games that never resolved.
     */
    $parlay = settle(parlayFor([
        ['status' => 'push'],
        ['status' => 'push'],
    ]));

    expect($parlay->status)->toBe('push')
        ->and($parlay->return_cents)->toBe(10000)
        ->and($parlay->profitCents())->toBe(0);
});

it('still loses when a leg lost and another pushed', function (): void {
    $parlay = settle(parlayFor([
        ['status' => 'lost'],
        ['status' => 'push'],
    ]));

    expect($parlay->status)->toBe('lost')
        ->and($parlay->return_cents)->toBe(0);
});

it('leaves a draft alone, because a draft is not a wager', function (): void {
    /*
     * The spec is explicit that a saved parlay must not be treated as
     * real money until the user records it as placed. A settler that
     * graded drafts would put money on bets nobody made.
     */
    $parlay = settle(parlayFor([
        ['status' => 'won'],
        ['status' => 'won'],
    ], ['status' => 'draft', 'placed_at' => null]));

    expect($parlay->status)->toBe('draft')
        ->and($parlay->return_cents)->toBeNull();
});

it('does not settle the same parlay twice', function (): void {
    /*
     * The job is scheduled, so it meets the same parlay repeatedly. A
     * second settlement would write a second bankroll entry and pay the
     * customer twice on paper.
     */
    $parlay = parlayFor([['status' => 'won'], ['status' => 'won']]);

    settle($parlay);
    $first = $parlay->fresh();

    app(ParlaySettler::class)->settleParlay($first);
    $second = $parlay->fresh();

    expect($second->return_cents)->toBe($first->return_cents)
        ->and($second->settled_at->toIso8601String())->toBe($first->settled_at->toIso8601String())
        ->and($second->events()->where('type', 'settled')->count())->toBe(1);
});

it('writes one audit row for the settlement', function (): void {
    $parlay = settle(parlayFor([['status' => 'won'], ['status' => 'won']]));

    $event = $parlay->events()->where('type', 'settled')->sole();

    expect($event->payload['status'])->toBe('won')
        // +600 is decimal 7.0, so $100 returns $700 including the stake.
        ->and($event->payload['return_cents'])->toBe(70000)
        ->and($event->user_id)->toBe($parlay->user_id);
});

it('refuses a parlay with no legs rather than calling it a win', function (): void {
    /*
     * Vacuously, every leg of an empty parlay won. A settler that said so
     * would pay out on nothing.
     */
    $parlay = settle(parlayFor([]));

    expect($parlay->status)->toBe('pending')
        ->and($parlay->return_cents)->toBeNull();
});
