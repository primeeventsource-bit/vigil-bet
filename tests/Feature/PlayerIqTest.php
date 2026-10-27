<?php

declare(strict_types=1);

use App\Models\PlayerRating;
use App\Models\Subscription;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Player IQ
|--------------------------------------------------------------------------
|
| Two things to hold here.
|
| The gate, because this is one of the two grants that let Elite claim
| anything Pro did not already have. If Pro could reach it, the tier
| above would be a higher price for the same product.
|
| And the restraint. The page renders a 0-99 number on a scale borrowed
| from games that mean something else by it. Every rating has to carry
| its sample size, anything under the floor has to be absent entirely,
| and a stale recalculation has to say so rather than imply currency.
|
*/

function ratedUser(?string $tier): User
{
    $user = User::forceCreate([
        'name' => 'Rated Reader',
        'email' => 'iq'.fake()->unique()->randomNumber(8).'@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ]);

    if ($tier !== null) {
        Subscription::create([
            'user_id' => $user->id,
            'tier' => $tier,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
            'source' => 'test',
        ]);
    }

    return $user->refresh();
}

function rating(array $overrides = []): PlayerRating
{
    return PlayerRating::create(array_merge([
        'sport' => 'nba',
        'player_external_id' => 'p'.fake()->unique()->randomNumber(8),
        'player_name' => 'Jordan Example',
        'peer_group' => 'guard',
        'overall' => 88,
        'components' => ['scoring' => 91, 'playmaking' => 84, 'defence' => 70],
        'games' => 40,
        'measured_from' => now()->subMonths(3),
        'measured_to' => now()->subDay(),
        'as_of' => now()->subHour(),
        'created_at' => now(),
    ], $overrides));
}

/* ----------------------------------------------------------------------
 | The gate
 ---------------------------------------------------------------------- */

it('is the thing Elite buys and Pro does not', function () {
    rating();

    // Pro holds every other feature key in the product. If it reached
    // this page too, Elite would be selling nothing.
    $this->actingAs(ratedUser('pro'))->get(route('player-iq'))
        ->assertRedirect(route('upgrade', ['feature' => 'playeriq']));

    $this->actingAs(ratedUser('elite'))->get(route('player-iq'))->assertOk();
});

it('is closed to a free account', function () {
    $this->actingAs(ratedUser(null))->get(route('player-iq'))
        ->assertRedirect(route('upgrade', ['feature' => 'playeriq']));
});

it('is closed to a stranger', function () {
    // Its own test rather than a second assertion in the one above:
    // actingAs persists for the life of the test, so a guest request
    // made after it is not a guest request at all.
    $this->get(route('player-iq'))->assertRedirect(route('login'));
});

/* ----------------------------------------------------------------------
 | What the page may and may not imply
 ---------------------------------------------------------------------- */

it('says what the number is before showing one', function () {
    rating();

    $page = $this->actingAs(ratedUser('elite'))->get(route('player-iq'))->assertOk()->getContent();

    // A percentile, not a scouting grade, and explicitly not the number
    // a video game would show by the same name.
    expect($page)->toContain('percentile')
        ->and($page)->toContain('not a prediction');
});

it('leaves out a player whose sample is too small to mean anything', function () {
    /*
     * A percentile from three games is noise wearing a number - and a
     * number is exactly what somebody will act on.
     */
    rating(['player_name' => 'Thin Sample', 'games' => 2]);
    rating(['player_name' => 'Real Sample', 'games' => 40]);

    $this->actingAs(ratedUser('elite'))->get(route('player-iq'))
        ->assertOk()
        ->assertSee('Real Sample')
        ->assertDontSee('Thin Sample');
});

it('shows the sample size beside every rating', function () {
    rating(['player_name' => 'Jordan Example', 'games' => 40, 'overall' => 88]);

    $this->actingAs(ratedUser('elite'))->get(route('player-iq'))
        ->assertOk()
        ->assertSee('88')
        // A 88 over eleven games and a 88 over four hundred are
        // different statements about the same player.
        ->assertSee('40');
});

it('admits when a rating is not current', function () {
    $stale = rating(['as_of' => now()->subDays(5)]);

    $this->actingAs(ratedUser('elite'))
        ->get(route('player-iq.show', ['sport' => $stale->sport, 'player' => $stale->player_external_id]))
        ->assertOk()
        ->assertSee('This rating is not current.');
});

it('does not cry stale over a rating computed this morning', function () {
    $fresh = rating(['as_of' => now()->subHours(2)]);

    $this->actingAs(ratedUser('elite'))
        ->get(route('player-iq.show', ['sport' => $fresh->sport, 'player' => $fresh->player_external_id]))
        ->assertOk()
        ->assertDontSee('This rating is not current.');
});

it('compares a player only against their own position', function () {
    $guard = rating(['player_name' => 'A Guard', 'peer_group' => 'guard']);
    rating(['player_name' => 'A Centre', 'peer_group' => 'centre', 'overall' => 99]);

    // The rating is a percentile within a peer group. Ranking a centre
    // beside a guard would be a different claim, and a meaningless one.
    $this->actingAs(ratedUser('elite'))
        ->get(route('player-iq.show', ['sport' => $guard->sport, 'player' => $guard->player_external_id]))
        ->assertOk()
        ->assertSee('A Guard')
        ->assertDontSee('A Centre');
});

it('says so plainly when there is nothing rated', function () {
    $this->actingAs(ratedUser('elite'))->get(route('player-iq'))
        ->assertOk()
        ->assertSee('No rated players here.');
});
