<?php

declare(strict_types=1);

use App\Models\SyncRun;
use App\Models\User;
use App\Models\VlBoardEntry;
use App\Services\EdgeLab\Mirror;

/*
 * The rule the whole product rests on: no pick is sold from an unproven
 * market. A board row saying "bet" is the engine's view of that bet; the
 * market verdict is whether the market earned the right to produce one at
 * all. Checking only the first is the bug this file exists to prevent.
 */

function mirrorEntry(array $overrides = []): VlBoardEntry
{
    $row = boardRow($overrides['edge_lab_id'] ?? 1, $overrides);

    app(Mirror::class)->write(VlBoardEntry::class, $row, [
        'edge_lab_id' => (int) $row['id'],
        'edge_lab_event_id' => $row['event_id'],
        'sport' => $row['sport'],
        'market' => $row['market'],
        'side' => $row['side'],
        'selection' => $row['selection'],
        'edge_at_price' => $row['edge_at_price'],
        'best_price_american' => $row['best_price_american'],
        'best_book_title' => $row['best_book_title'],
        'market_verdict' => $row['market_verdict'],
        'recommendation' => $row['recommendation'],
        'pass_reason' => $row['pass_reason'],
        'reason' => $row['reason'],
    ]);

    return VlBoardEntry::query()->latest('id')->firstOrFail();
}

function signedInUser(): User
{
    return User::forceCreate([
        'name' => 'Sam',
        'email' => 'sam@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ]);
}

function freshBoardSync(): void
{
    SyncRun::create([
        'feed' => 'board', 'status' => 'success', 'cursor_after' => 1,
        'started_at' => now()->subMinute(), 'finished_at' => now()->subMinute(),
    ]);
}

it('sells a pick only when the market is proven', function () {
    mirrorEntry(['id' => 1, 'recommendation' => 'bet', 'market_verdict' => 'proven']);

    expect(VlBoardEntry::query()->sellablePicks()->count())->toBe(1);
});

it('refuses to sell a bet from a market that is not proven', function (string $verdict) {
    mirrorEntry(['id' => 1, 'recommendation' => 'bet', 'market_verdict' => $verdict]);

    // The engine said bet. The gate says the market never earned it.
    expect(VlBoardEntry::query()->sellablePicks()->count())->toBe(0)
        ->and(VlBoardEntry::firstOrFail()->isSellablePick())->toBeFalse();
})->with(['unproven', 'negative', 'insufficient_data']);

it('shows no picks and says so when nothing is proven', function () {
    freshBoardSync();
    mirrorEntry(['id' => 1, 'recommendation' => 'bet', 'market_verdict' => 'negative']);

    $this->actingAs(signedInUser())->get(route('today'))
        ->assertOk()
        ->assertSee('No proven picks today')
        ->assertDontSee('Pick · proven market', escape: false);
});

it('labels a near miss a pass every time it is shown', function () {
    freshBoardSync();
    mirrorEntry(['id' => 1, 'recommendation' => 'pass', 'market_verdict' => 'unproven']);

    $this->actingAs(signedInUser())->get(route('today'))
        ->assertOk()
        ->assertSee('No proven picks today')
        ->assertSee('Pass')
        ->assertSee('Model unproven here.');
});

it('puts the responsible gambling line on the daily card', function () {
    freshBoardSync();

    $this->actingAs(signedInUser())->get(route('today'))
        ->assertOk()
        ->assertSee(config('vigil.responsible_gambling_line'));
});
