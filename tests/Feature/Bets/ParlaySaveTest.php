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
        test()->actingAs($user)->post(route('parlays.add'), ['board_entry_id' => $id])
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

it('shows what the engine thought about each leg when it was saved', function () {
    $user = betUser();
    slipFor($user);

    $this->actingAs($user)->post(route('parlays.save'), ['stake' => '25.00']);

    /*
     * The slip only accepts picks from proven markets the engine
     * recommended, so these legs are ones it backed - and the page has to
     * say so for the comparison to mean anything.
     */
    $this->actingAs($user)->get(route('my-parlays'))
        ->assertOk()
        ->assertSee('VIGIL backed this');
});

it('requires a login, because a betting record is private', function () {
    $this->get(route('my-parlays'))->assertRedirect();
    $this->post(route('parlays.save'), ['stake' => '10'])->assertRedirect();
});
