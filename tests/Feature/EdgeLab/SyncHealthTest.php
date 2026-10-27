<?php

declare(strict_types=1);

use App\Models\SyncRun;
use App\Models\User;
use App\Services\EdgeLab\SyncHealth;

beforeEach(function () {
    config()->set('edgelab.enabled', true);
    config()->set('edgelab.stale_after_minutes.board', 90);
});

function boardSyncedMinutesAgo(int $minutes): SyncRun
{
    return SyncRun::create([
        'feed' => 'board',
        'status' => 'success',
        'cursor_after' => 10,
        'records_fetched' => 3,
        'records_written' => 3,
        'started_at' => now()->subMinutes($minutes),
        'finished_at' => now()->subMinutes($minutes),
    ]);
}

it('treats a feed that has never synced as stale', function () {
    // The alternative - fine until proven otherwise - renders a
    // confident, empty board on a deployment with a broken token.
    expect(app(SyncHealth::class)->isStale('board'))->toBeTrue()
        ->and(app(SyncHealth::class)->customerNotice(['board']))->toContain('has not synced yet');
});

it('is current inside the staleness window', function () {
    boardSyncedMinutesAgo(10);
    boardSyncedMinutesAgo(5);

    expect(app(SyncHealth::class)->isStale('board'))->toBeFalse();
});

it('says how old the data is once it falls behind', function () {
    boardSyncedMinutesAgo(200);

    $health = app(SyncHealth::class);

    expect($health->isStale('board'))->toBeTrue()
        ->and($health->customerNotice(['board']))->toContain('Data delayed')
        ->and($health->customerNotice(['board']))->toContain('3 hours ago');
});

it('does not count a failed run as a sync', function () {
    boardSyncedMinutesAgo(200);

    SyncRun::create([
        'feed' => 'board', 'status' => 'failed', 'message' => 'The engine refused the query.',
        'started_at' => now(), 'finished_at' => now(),
    ]);

    // A failure that reset the clock would be a board claiming to be
    // current because something tried and failed a moment ago.
    expect(app(SyncHealth::class)->isStale('board'))->toBeTrue();
});

it('says plainly when nothing is connected at all', function () {
    config()->set('edgelab.enabled', false);

    expect(app(SyncHealth::class)->isStale('board'))->toBeTrue()
        ->and(app(SyncHealth::class)->customerNotice())->toContain('not connected');
});

it('warns a customer on the daily card when the data is behind', function () {
    boardSyncedMinutesAgo(500);

    $user = User::forceCreate([
        'is_admin' => true,
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
        'name' => 'Sam', 'email' => 'sam@example.com',
        'password' => 'correct-horse-battery-staple', 'email_verified_at' => now(),
    ]);

    $this->actingAs($user)->get(route('today'))
        ->assertOk()
        ->assertSee('Data delayed')
        ->assertSee('Delayed');
});

it('shows the operator every feed', function () {
    boardSyncedMinutesAgo(5);

    $user = User::forceCreate([
        'is_admin' => true,
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
        'name' => 'Sam', 'email' => 'sam@example.com',
        'password' => 'correct-horse-battery-staple', 'email_verified_at' => now(),
    ]);

    $this->actingAs($user)->get(route('admin.sync-health'))
        ->assertOk()
        ->assertSee('Sync health')
        ->assertSee('board')
        ->assertSee('events')
        ->assertSee('models')
        ->assertSee('Current')
        ->assertSee('Never synced');
});

it('keeps sync health behind a login', function () {
    $this->get(route('admin.sync-health'))->assertRedirect(route('login'));
});
