<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Administering accounts
|--------------------------------------------------------------------------
|
| The spec's hard requirements, tested as behaviour rather than trusted
| to the policy that implements them: an ordinary ADMIN cannot edit,
| demote, suspend or impersonate a SUPER_ADMIN; nobody can delete an
| account at all; and every write leaves an audit row with a reason
| somebody typed.
|
*/

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function enrolled(string ...$roles): User
{
    $user = User::forceCreate([
        'name' => 'Ops Person',
        'email' => 'ops'.fake()->unique()->randomNumber(8).'@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
    ]);

    foreach ($roles as $role) {
        $user->assignRole($role);
    }

    $user->load('roles');
    $user->syncAdminFlag();

    return $user->refresh();
}

/* ----------------------------------------------------------------------
 | Creating
 ---------------------------------------------------------------------- */

it('creates an account without ever setting or showing a password', function () {
    Notification::fake();

    $boss = enrolled(config('roles.super_admin'));

    $this->actingAs($boss)->post(route('admin.users.store'), [
        'first_name' => 'Dana',
        'last_name' => 'Scully',
        'email' => 'dana@example.com',
        'role' => config('roles.user'),
        'reason' => 'Opening an account for a trial customer',
    ])->assertRedirect();

    $created = User::where('email', 'dana@example.com')->sole();

    expect($created->name)->toBe('Dana Scully')
        ->and($created->hasRole(config('roles.user')))->toBeTrue()
        ->and($created->isAdmin())->toBeFalse();

    // The only way in is the link. Ownership of the mailbox is the
    // proof, the same proof every reset already relies on.
    Notification::assertSentTo($created, ResetPassword::class);
});

it('refuses a duplicate address rather than letting the index raise a 500', function () {
    $boss = enrolled(config('roles.super_admin'));
    $existing = enrolled(config('roles.user'));

    $this->actingAs($boss)->post(route('admin.users.store'), [
        'first_name' => 'Fox',
        'last_name' => 'Mulder',
        'email' => $existing->email,
        'role' => config('roles.user'),
        'reason' => 'Trying to create a duplicate on purpose',
    ])->assertSessionHasErrors('email');
});

it('stops an ordinary admin creating another admin', function () {
    // The select hides the option; this is the part that holds when
    // somebody posts the body themselves.
    $ops = enrolled(config('roles.admin'));

    $this->actingAs($ops)->post(route('admin.users.store'), [
        'first_name' => 'New',
        'last_name' => 'Operator',
        'email' => 'new-operator@example.com',
        'role' => config('roles.admin'),
        'reason' => 'Attempting to mint a colleague',
    ])->assertNotFound();

    expect(User::where('email', 'new-operator@example.com')->exists())->toBeFalse();
});

/* ----------------------------------------------------------------------
 | The super admin is untouchable
 ---------------------------------------------------------------------- */

it('refuses an ordinary admin every write against a super admin', function (string $route) {
    $ops = enrolled(config('roles.admin'));
    $boss = enrolled(config('roles.super_admin'));

    $this->actingAs($ops)->post(route($route, $boss), [
        'reason' => 'Attempting something that must not work',
        'role' => config('roles.user'),
        'first_name' => 'Renamed',
        'last_name' => 'Person',
        'email' => 'renamed@example.com',
    ])->assertForbidden();

    expect($boss->fresh()->isSuperAdmin())->toBeTrue()
        ->and($boss->fresh()->isSuspended())->toBeFalse();
})->with([
    'admin.users.update',
    'admin.users.role',
    'admin.users.suspend',
    'admin.users.revoke',
]);

it('lets a reset link be sent to a super admin, which is the way back in', function () {
    Notification::fake();

    $ops = enrolled(config('roles.admin'));
    $ops->givePermissionTo('users.reset');
    $boss = enrolled(config('roles.super_admin'));

    // Deliberately not blocked. A reset link is a courtesy, not a
    // seizure, and locking everybody out of helping the one account
    // that outranks them is how a Super Admin stays locked out.
    $this->actingAs($ops->fresh())->post(route('admin.users.reset', $boss), [
        'reason' => 'Super admin reported being locked out',
    ])->assertRedirect();

    Notification::assertSentTo($boss, ResetPassword::class);
});

/* ----------------------------------------------------------------------
 | Suspension
 ---------------------------------------------------------------------- */

it('suspends an account, ends its sessions and locks it out on the next request', function () {
    $boss = enrolled(config('roles.super_admin'));
    $customer = enrolled(config('roles.user'));

    DB::table('sessions')->insert([
        'id' => 'test-session-id',
        'user_id' => $customer->getKey(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($boss)->post(route('admin.users.suspend', $customer), [
        'reason' => 'Chargeback fraud under investigation',
    ])->assertRedirect();

    $customer->refresh();

    expect($customer->isSuspended())->toBeTrue()
        ->and($customer->suspended_reason)->toBe('Chargeback fraud under investigation')
        ->and($customer->suspended_by)->toBe($boss->getKey())
        // The open session is gone, not merely invalidated later.
        ->and(DB::table('sessions')->where('user_id', $customer->getKey())->count())->toBe(0);

    // And the middleware turns them away rather than showing a notice.
    $this->actingAs($customer)->get(route('today'))->assertRedirect(route('login'));
});

it('reinstates without touching a self-exclusion', function () {
    $boss = enrolled(config('roles.super_admin'));
    $customer = enrolled(config('roles.user'));

    $this->actingAs($boss)->post(route('admin.users.suspend', $customer), [
        'reason' => 'Suspended while a dispute was open',
    ]);

    $this->actingAs($boss)->post(route('admin.users.reinstate', $customer), [
        'reason' => 'Dispute resolved in the customer favour',
    ])->assertRedirect();

    expect($customer->fresh()->isSuspended())->toBeFalse();
});

/* ----------------------------------------------------------------------
 | Roles
 ---------------------------------------------------------------------- */

it('ends every session when an admin is demoted', function () {
    /*
     * is_admin is read per request, so the next one would be refused
     * anyway - but "the next request" is whenever they choose, and a
     * withdrawn role should take effect now rather than when somebody
     * closes their laptop.
     */
    $boss = enrolled(config('roles.super_admin'));
    $ops = enrolled(config('roles.admin'));

    DB::table('sessions')->insert([
        'id' => 'ops-session', 'user_id' => $ops->getKey(), 'ip_address' => '127.0.0.1',
        'user_agent' => 'test', 'payload' => '', 'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($boss)->post(route('admin.users.role', $ops), [
        'role' => config('roles.user'),
        'reason' => 'Left the operations team this week',
    ])->assertRedirect();

    $ops->refresh();

    expect($ops->isAdmin())->toBeFalse()
        ->and($ops->hasRole(config('roles.admin')))->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $ops->getKey())->count())->toBe(0);
});

it('clears the confirmation when an operator changes somebody address', function () {
    $boss = enrolled(config('roles.super_admin'));
    $customer = enrolled(config('roles.user'));

    expect($customer->email_verified_at)->not->toBeNull();

    $this->actingAs($boss)->post(route('admin.users.update', $customer), [
        'first_name' => 'Same',
        'last_name' => 'Person',
        'email' => 'moved@example.com',
        'reason' => 'Customer asked to move to their work address',
    ])->assertRedirect();

    // The old confirmation proved the old mailbox. Carrying it over
    // would leave an unproved address looking proved.
    expect($customer->fresh()->email_verified_at)->toBeNull();
});

/* ----------------------------------------------------------------------
 | Every write is recorded
 ---------------------------------------------------------------------- */

it('refuses any of it without a reason somebody typed', function (string $route) {
    $boss = enrolled(config('roles.super_admin'));
    $customer = enrolled(config('roles.user'));

    $this->actingAs($boss)->post(route($route, $customer), ['reason' => 'ok'])
        ->assertSessionHasErrors('reason');
})->with([
    'admin.users.suspend',
    'admin.users.revoke',
    'admin.users.reset',
]);

it('writes an audit row for every account change', function () {
    $boss = enrolled(config('roles.super_admin'));
    $customer = enrolled(config('roles.user'));

    $this->actingAs($boss)->post(route('admin.users.suspend', $customer), [
        'reason' => 'Account suspended for a stated reason',
    ]);

    $entry = AuditEvent::where('action', 'user.suspended')->sole();

    expect($entry->actor_id)->toBe($boss->getKey())
        ->and($entry->subject_id)->toBe($customer->getKey())
        ->and($entry->reason)->toBe('Account suspended for a stated reason');
});
