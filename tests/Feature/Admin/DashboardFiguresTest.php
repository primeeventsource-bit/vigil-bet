<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The admin dashboard's numbers
|--------------------------------------------------------------------------
|
| The spec asks for real database figures and no hardcoded demo metrics.
| The second half is the one worth testing, because a placeholder number
| on an operations dashboard is not a cosmetic problem - it is a figure
| somebody will make a decision on.
|
| MRR gets the most attention here. It is the easiest number on the page
| to compute wrongly in a way nobody notices: counting cancelled
| subscriptions, counting a yearly plan at its full price, or reading a
| tier name that no longer exists as nothing.
|
*/

function dashboardAdmin(): User
{
    $user = User::forceCreate([
        'name' => 'Dash Admin',
        'email' => 'dash'.fake()->unique()->randomNumber(8).'@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
        'is_admin' => true,
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
    ]);

    $user->assignRole(config('roles.admin'));
    $user->load('roles');

    return $user->refresh();
}

function subscriberOn(string $tier, string $interval = 'monthly', string $status = 'active'): User
{
    $user = User::forceCreate([
        'name' => 'Payer',
        'email' => 'pay'.fake()->unique()->randomNumber(8).'@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ]);

    Subscription::create([
        'user_id' => $user->id,
        'tier' => $tier,
        'interval' => $interval,
        'status' => $status,
        'current_period_end' => now()->addMonth(),
        'source' => 'test',
    ]);

    return $user;
}

/* ----------------------------------------------------------------------
 | Monthly recurring revenue
 ---------------------------------------------------------------------- */

it('adds up monthly recurring revenue from live subscriptions', function () {
    subscriberOn('core');   // 2999
    subscriberOn('pro');    // 5999
    subscriberOn('elite');  // 9999

    $this->actingAs(dashboardAdmin())->get(route('admin.overview'))
        ->assertOk()
        ->assertSee('$189.97');
});

it('divides a yearly plan by twelve rather than counting it whole', function () {
    // Otherwise the word "monthly" in the label is wrong by a factor of
    // twelve, in the flattering direction.
    subscriberOn('pro', 'yearly'); // 59990 / 12 = 4999

    $this->actingAs(dashboardAdmin())->get(route('admin.overview'))
        ->assertOk()
        ->assertSee('$49.99');
});

it('leaves a cancelled subscription out of recurring revenue', function () {
    /*
     * Charged cents and MRR answer different questions. A subscription
     * cancelled yesterday is money that arrived and is not money
     * expected to arrive again.
     */
    subscriberOn('elite', 'monthly', 'cancelled');

    $this->actingAs(dashboardAdmin())->get(route('admin.overview'))
        ->assertOk()
        ->assertDontSee('$99.99');
});

it('still counts a subscription on a tier that was renamed', function () {
    // billing history genuinely contains 'daily'. Reading it as an
    // unknown tier would quietly undercount revenue somebody is paying.
    subscriberOn('daily');

    $this->actingAs(dashboardAdmin())->get(route('admin.overview'))
        ->assertOk()
        ->assertSee('$29.99');
});

/* ----------------------------------------------------------------------
 | The rest of the panel
 ---------------------------------------------------------------------- */

it('counts administrators and says how many cannot get in', function () {
    $admin = dashboardAdmin();

    $locked = dashboardAdmin();
    $locked->forceFill(['two_factor_confirmed_at' => null])->save();

    $this->actingAs($admin)->get(route('admin.overview'))
        ->assertOk()
        // The useful half: an admin without MFA is locked out, not
        // insecure, so this is somebody who needs a nudge.
        ->assertSee('not enrolled, locked out until they are');
});

it('reports failed jobs as a count rather than a yes or no', function () {
    // "Some" and "eleven hundred" call for different mornings.
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Test',
        'failed_at' => now(),
    ]);

    $this->actingAs(dashboardAdmin())->get(route('admin.overview'))
        ->assertOk()
        ->assertSee('Failed jobs')
        ->assertSee('1 in the last day');
});

it('says nothing happened rather than showing an empty security panel', function () {
    $this->actingAs(dashboardAdmin())->get(route('admin.overview'))
        ->assertOk()
        ->assertSee('No lockouts, suspensions, role changes or password resets have been recorded.');
});

it('quotes no win rate when nothing has settled', function () {
    /*
     * The house rule, re-asserted on the dashboard: a rate with no
     * sample is a number pretending to be evidence.
     */
    $this->actingAs(dashboardAdmin())->get(route('admin.overview'))
        ->assertOk()
        ->assertSee('nothing settled yet, so no rate to quote');
});
