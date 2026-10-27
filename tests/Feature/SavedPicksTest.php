<?php

declare(strict_types=1);

use App\Models\Opinion;
use App\Models\SavedPick;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| My Picks
|--------------------------------------------------------------------------
|
| A shortlist, not a bet log. The two must not converge: My Bets records
| money somebody says they staked, this records interest with nothing
| down, and a watchlist that reported a profit nobody made would be the
| worst number on the site.
|
| The other half is isolation, which the spec asks for by name. One
| customer must not be able to read, add to, or clear another's list -
| and the last of those is the one an id in a URL makes easy.
|
*/

function picker(): User
{
    return User::forceCreate([
        'name' => 'Pick Keeper',
        'email' => 'picks'.fake()->unique()->randomNumber(8).'@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ])->refresh();
}

function anOpinion(): Opinion
{
    $event = engineGame('Buffalo Bills', 'Miami Dolphins', ['starts_at' => now()->addHours(5)]);

    return Opinion::create([
        'event_id' => $event->id,
        'sport' => 'nfl',
        'market' => 'h2h',
        'side' => 'home',
        'selection' => 'Miami Dolphins',
        'matchup' => 'Buffalo Bills at Miami Dolphins',
        'price_american' => -130,
        'model_prob' => 0.62,
        // Not nullable: an opinion with no kickoff cannot be graded,
        // and the column refuses one rather than letting it through.
        'kickoff_at' => now()->addHours(5),
        'published_at' => now(),
        // The table is append-only and sets no timestamps of its own.
        'created_at' => now(),
    ]);
}

/* ----------------------------------------------------------------------
 | Saving
 ---------------------------------------------------------------------- */

it('keeps a pick and shows it back', function () {
    $user = picker();
    $opinion = anOpinion();

    $this->actingAs($user)->post(route('my-picks.save', $opinion), [
        'note' => 'Watching the injury report',
    ])->assertRedirect();

    $this->actingAs($user)->get(route('my-picks'))
        ->assertOk()
        ->assertSee('Miami Dolphins')
        ->assertSee('Watching the injury report');
});

it('treats saving twice as saving once', function () {
    // The unique index would otherwise turn a double click into a 500.
    $user = picker();
    $opinion = anOpinion();

    $this->actingAs($user)->post(route('my-picks.save', $opinion));
    $this->actingAs($user)->post(route('my-picks.save', $opinion))->assertRedirect();

    expect(SavedPick::where('user_id', $user->id)->count())->toBe(1);
});

it('removes a pick without touching the opinion it pointed at', function () {
    $user = picker();
    $opinion = anOpinion();

    $this->actingAs($user)->post(route('my-picks.save', $opinion));
    $this->actingAs($user)->delete(route('my-picks.remove', $opinion))->assertRedirect();

    expect(SavedPick::where('user_id', $user->id)->count())->toBe(0)
        // The published record is not a customer's to edit.
        ->and(Opinion::find($opinion->id))->not->toBeNull();
});

/* ----------------------------------------------------------------------
 | Isolation
 ---------------------------------------------------------------------- */

it('shows one customer nothing of another customer list', function () {
    $mine = picker();
    $theirs = picker();
    $opinion = anOpinion();

    SavedPick::create([
        'user_id' => $theirs->getKey(),
        'opinion_id' => $opinion->getKey(),
        'note' => 'A private note that belongs to somebody else',
    ]);

    $this->actingAs($mine)->get(route('my-picks'))
        ->assertOk()
        ->assertDontSee('A private note that belongs to somebody else')
        ->assertSee('You have not saved anything yet.');
});

it('refuses to let one customer clear another list', function () {
    /*
     * The delete is scoped by user id as well as opinion id. Scoping it
     * by the opinion alone would mean the id in this URL is enough to
     * empty somebody else's shortlist.
     */
    $mine = picker();
    $theirs = picker();
    $opinion = anOpinion();

    SavedPick::create(['user_id' => $theirs->getKey(), 'opinion_id' => $opinion->getKey()]);

    $this->actingAs($mine)->delete(route('my-picks.remove', $opinion))->assertRedirect();

    expect(SavedPick::where('user_id', $theirs->getKey())->count())->toBe(1);
});

it('is closed to a stranger', function () {
    $this->get(route('my-picks'))->assertRedirect(route('login'));
});

/* ----------------------------------------------------------------------
 | It is not a bet log
 ---------------------------------------------------------------------- */

it('never claims a stake or a profit', function () {
    $user = picker();
    $opinion = anOpinion();

    $this->actingAs($user)->post(route('my-picks.save', $opinion));

    $page = $this->actingAs($user)->get(route('my-picks'))->assertOk()->getContent();

    // Saving records interest. Anything that reads as money on this
    // page is money nobody put down.
    expect($page)->not->toContain('Staked')
        ->and($page)->not->toContain('Profit')
        ->and($page)->toContain('Nothing here is staked');
});

it('reads the result from the opinion rather than keeping its own copy', function () {
    /*
     * A saved pick holds no selection, price or result of its own, so
     * it cannot drift from the public record it came from. Asserted
     * against the schema rather than the page, because this is a
     * property of the table.
     */
    $columns = Schema::getColumnListing('saved_picks');

    expect($columns)->toEqualCanonicalizing([
        'id', 'user_id', 'opinion_id', 'note', 'created_at', 'updated_at',
    ]);
});
