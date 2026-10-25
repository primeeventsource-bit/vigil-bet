<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Saving a slip, through the page
|--------------------------------------------------------------------------
|
| The builder graded a slip and forgot it. These tests cover the path a
| customer actually takes - build, save, see it on their own record - and
| in particular the one thing the spec is emphatic about: a saved parlay
| is not a wager until they say it is.
|
*/

use App\Models\BankrollEntry;
use App\Models\UserParlay;

beforeEach(function () {
    config()->set('ai.enabled', false);
    config()->set('edgelab.enabled', true);
});

function slipFor($user, int $legs = 2): void
{
    foreach (slipOf($legs) as $id) {
        test()->actingAs($user)->post(route('parlays.add'), $id)
            ->assertSessionHasNoErrors();
    }
}

it('saves a slip as a draft and says it is not a wager', function () {
    $user = betUser();
    slipFor($user);

    $this->actingAs($user)
        ->post(route('parlays.save'), ['stake' => '25.00'])
        ->assertRedirect(route('my-parlays'))
        ->assertSessionHas('status');

    $parlay = UserParlay::query()->sole();

    expect($parlay->status)->toBe('draft')
        ->and($parlay->stake_cents)->toBe(2500)
        ->and($parlay->legs)->toHaveCount(2)
        ->and(BankrollEntry::query()->where('kind', BankrollEntry::STAKE)->count())->toBe(0);
});

it('records a placed parlay and takes the stake off the bankroll', function () {
    $user = betUser();
    slipFor($user);

    $this->actingAs($user)->post(route('parlays.save'), [
        'stake' => '25.00',
        'placed' => '1',
        'book' => 'draftkings',
        'accepted_price' => '260',
        'ticket_reference' => 'DK-9981',
    ])->assertRedirect(route('my-parlays'));

    $parlay = UserParlay::query()->sole();

    expect($parlay->status)->toBe('pending')
        ->and($parlay->accepted_price_american)->toBe(260)
        ->and($parlay->ticket_reference)->toBe('DK-9981')
        ->and(BankrollEntry::query()->where('kind', BankrollEntry::STAKE)->sum('amount_cents'))->toBe(-2500);
});

it('empties the slip after saving, so the next save is not a duplicate', function () {
    $user = betUser();
    slipFor($user);

    $this->actingAs($user)->post(route('parlays.save'), ['stake' => '10']);

    /*
     * A slip left full after saving makes the obvious next action a
     * second identical parlay, which is how a tracker ends up reporting
     * bets nobody placed.
     */
    $this->actingAs($user)->get(route('parlays'))
        ->assertOk()
        ->assertSee('Nothing on the slip');
});

it('refuses a single-leg slip, which is a straight bet', function () {
    $user = betUser();
    slipFor($user, 1);

    $this->actingAs($user)
        ->post(route('parlays.save'), ['stake' => '10'])
        ->assertSessionHasErrors('slip');

    expect(UserParlay::query()->count())->toBe(0);
});

it('refuses a save with no stake', function () {
    $user = betUser();
    slipFor($user);

    $this->actingAs($user)
        ->post(route('parlays.save'), ['stake' => ''])
        ->assertSessionHasErrors('stake');
});

it('keeps a paper parlay off the bankroll', function () {
    $user = betUser();
    slipFor($user);

    $this->actingAs($user)->post(route('parlays.save'), [
        'stake' => '25.00',
        'placed' => '1',
        'is_paper' => '1',
    ]);

    expect(UserParlay::query()->sole()->is_paper)->toBeTrue()
        ->and(BankrollEntry::query()->where('kind', BankrollEntry::STAKE)->count())->toBe(0);
});

it('shows a customer their own parlays and nobody else’s', function () {
    $mine = betUser();
    $theirs = betUser();

    slipFor($mine);
    $this->actingAs($mine)->post(route('parlays.save'), ['stake' => '25.00']);

    $this->actingAs($theirs)->get(route('my-parlays'))
        ->assertOk()
        ->assertSee('Nothing saved yet');

    $this->actingAs($mine)->get(route('my-parlays'))
        ->assertOk()
        ->assertSee('2-leg parlay');
});

it('says the engine had no view, when it had none', function () {
    /*
     * The ordinary case now, and the one the old version of this test
     * could not reach. A customer picking a game off Today's Games is
     * usually picking one the engine never published an opinion on, and
     * the record has to say that rather than imply agreement.
     */
    $user = betUser();
    slipFor($user);

    $this->actingAs($user)->post(route('parlays.save'), ['stake' => '25.00']);

    $this->actingAs($user)->get(route('my-parlays'))
        ->assertOk()
        ->assertSee('VIGIL had no qualifying prediction');
});

it('says the engine backed a leg when it did', function () {
    $user = betUser();

    /*
     * A priced selection the engine also recommended. The board entry is
     * matched on game, market and side - the same selection, not merely
     * the same game - so the comparison is about this bet.
     */
    $selection = pricedSelection();
    provenEntry([
        'id' => 9001,
        'edge_lab_event_id' => $selection['event_id'],
        'market' => $selection['market'],
        'side' => $selection['side'],
        'selection' => 'Engine agreed',
    ]);

    $this->actingAs($user)->post(route('parlays.add'), $selection)->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('parlays.add'), pricedSelection())->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('parlays.save'), ['stake' => '25.00']);

    $this->actingAs($user)->get(route('my-parlays'))
        ->assertOk()
        ->assertSee('VIGIL backed this');
});

it('requires a login, because a betting record is private', function () {
    $this->get(route('my-parlays'))->assertRedirect();
    $this->post(route('parlays.save'), ['stake' => '10'])->assertRedirect();
});
