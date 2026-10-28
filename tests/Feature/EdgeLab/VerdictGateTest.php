<?php

declare(strict_types=1);

use App\Models\Subscription;
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

    mirrorEvent((int) $row['event_id'], $overrides['event'] ?? []);

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

/* A subscriber: see the note on dashUser() in DashboardTest. */
function signedInUser(): User
{
    $user = User::forceCreate([
        'name' => 'Sam',
        'email' => 'sam@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ]);

    Subscription::create([
        'user_id' => $user->id, 'tier' => 'pro', 'status' => 'active',
        'current_period_end' => now()->addMonth(), 'source' => 'test',
    ]);

    return $user->refresh();
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

it('refuses to sell a bet from a market measured as losing', function () {
    mirrorEntry(['id' => 1, 'recommendation' => 'bet', 'market_verdict' => 'negative']);

    /*
     * The one tier still refused, and the dataset this test used to run
     * over told the old story: `unproven` and `insufficient_data` were
     * refused beside it, which meant the engine could sell from no
     * market at all.
     *
     * `negative` stays out because it is not unvalidated. It has been
     * measured and the finding is that the model loses to the price
     * there - a different thing from having no record yet.
     */
    expect(VlBoardEntry::query()->sellablePicks()->count())->toBe(0)
        ->and(VlBoardEntry::firstOrFail()->isSellablePick())->toBeFalse();
});

it('sells a bet from a market that simply has not proved itself yet', function (string $verdict) {
    mirrorEntry(['id' => 1, 'recommendation' => 'bet', 'market_verdict' => $verdict]);

    /*
     * The change this release is for. These markets have not beaten the
     * closing line - most never will - and the product does not claim
     * they have. What it does is show the engine's opinion, which is
     * the thing customers subscribe to.
     *
     * The card carries the verdict in words beside the confidence
     * figure, so "not yet proven" reaches the reader at the same moment
     * the pick does.
     */
    expect(VlBoardEntry::query()->sellablePicks()->count())->toBe(1)
        ->and(VlBoardEntry::firstOrFail()->isSellablePick())->toBeTrue();
})->with(['calibrated', 'unproven', 'insufficient_data']);

it('shows no picks and says so when nothing is proven', function () {
    freshBoardSync();
    mirrorEntry(['id' => 1, 'recommendation' => 'bet', 'market_verdict' => 'negative']);

    $this->actingAs(signedInUser())->get(route('today'))
        ->assertOk()
        ->assertSee('No predictions are currently available')
        ->assertDontSee('Pick · proven market', escape: false);
});

it('labels a near miss a pass every time it is shown', function () {
    freshBoardSync();
    mirrorEntry(['id' => 1, 'recommendation' => 'pass', 'market_verdict' => 'unproven']);

    $this->actingAs(signedInUser())->get(route('today'))
        ->assertOk()
        ->assertSee('No predictions are currently available')
        ->assertSee('Pass')
        ->assertSee('Model unproven here.');
});

it('puts the responsible gambling line on the daily card', function () {
    freshBoardSync();

    $this->actingAs(signedInUser())->get(route('today'))
        ->assertOk()
        ->assertSee(config('vigil.responsible_gambling_line'));
});
