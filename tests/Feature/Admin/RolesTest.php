<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Entitlements\Entitlements;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Roles, and the lines they must not cross
|--------------------------------------------------------------------------
|
| Two authorization systems now live in this application, and the whole
| risk of that arrangement is the two quietly becoming one.
|
| Spatie answers "may this person operate the product". Entitlements
| answers "what did this person's subscription buy". A Super Admin who
| gained plan features would see a different board from the customer they
| are helping; a paying customer who gained an administrative permission
| would be a breach. Neither failure announces itself, so both are tested
| here rather than trusted to the comment that explains them.
|
*/

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function roleUser(string ...$roles): User
{
    $user = User::forceCreate([
        'name' => 'Role '.fake()->unique()->randomNumber(6),
        'email' => 'role'.fake()->unique()->randomNumber(8).'@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
        // Enrolled, so RequireAdminTwoFactor is not what is being
        // measured in tests about something else.
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
 | The separation between operating and paying
 ---------------------------------------------------------------------- */

it('gives a super admin every administrative permission without listing them', function () {
    // The role carries no permissions at all - Gate::before does the
    // work - so a permission invented next year is covered without
    // anybody remembering to come back and tick it.
    $super = roleUser(config('roles.super_admin'));

    foreach (array_keys((array) config('roles.permissions')) as $permission) {
        expect($super->can($permission))->toBeTrue("super admin was refused {$permission}");
    }
});

it('gives a super admin no plan feature they have not paid for', function () {
    /*
     * The important one. Gate::before returns true for everything
     * EXCEPT the keys Entitlements owns, so an operator on the free
     * tier sees exactly what a free customer sees.
     */
    $super = roleUser(config('roles.super_admin'));

    expect($super->tier())->toBe('guest')
        ->and($super->can('board.full'))->toBeFalse()
        ->and($super->can('bankroll'))->toBeFalse()
        ->and($super->can('modellab'))->toBeFalse();
});

it('gives a paying customer no administrative permission', function () {
    $customer = roleUser(config('roles.user'));

    foreach (array_keys((array) config('roles.permissions')) as $permission) {
        expect($customer->can($permission))->toBeFalse("customer was granted {$permission}");
    }
});

it('keeps plan features out of the permission list entirely', function () {
    // A config-level check, so the two files cannot converge by
    // somebody pasting a feature key into the wrong one.
    $permissions = array_keys((array) config('roles.permissions'));
    $features = app(Entitlements::class)->allFeatures();

    expect(array_intersect($permissions, $features))->toBe([]);
});

/* ----------------------------------------------------------------------
 | The admin flag stays true to the roles
 ---------------------------------------------------------------------- */

it('raises the admin flag when a role is granted and drops it when revoked', function () {
    /*
     * is_admin is a cache of "holds an administrative role" and it is
     * what EnsureAdmin reads on every request. A stale true would admit
     * somebody whose role was taken away this morning.
     */
    $user = roleUser(config('roles.user'));
    expect($user->fresh()->is_admin)->toBeFalse();

    $user->assignRole(config('roles.admin'));
    $user->load('roles');
    $user->syncAdminFlag();
    expect($user->fresh()->is_admin)->toBeTrue();

    $user->removeRole(config('roles.admin'));
    $user->load('roles');
    $user->syncAdminFlag();
    expect($user->fresh()->is_admin)->toBeFalse();
});

/* ----------------------------------------------------------------------
 | A super admin cannot be touched
 ---------------------------------------------------------------------- */

it('refuses an ordinary admin every move against a super admin', function (string $ability) {
    $admin = roleUser(config('roles.admin'));
    $target = roleUser(config('roles.super_admin'));

    expect($admin->can($ability, $target))->toBeFalse("ADMIN was allowed to {$ability} a SUPER_ADMIN");
})->with(['update', 'suspend', 'assignRole', 'delete', 'impersonate', 'revokeSessions']);

it('refuses a super admin the same moves against another super admin', function (string $ability) {
    // Gate::before deliberately declines to answer when the subject is
    // a Super Admin, so the policy decides - otherwise two of them
    // could dismantle each other.
    $actor = roleUser(config('roles.super_admin'));
    $target = roleUser(config('roles.super_admin'));

    expect($actor->can($ability, $target))->toBeFalse("SUPER_ADMIN was allowed to {$ability} another");
})->with(['update', 'suspend', 'assignRole', 'delete']);

it('refuses everybody deletion and impersonation, including a super admin', function () {
    /*
     * Not an oversight. An account carries bets, a public record and a
     * self-exclusion history, so deleting one destroys evidence; and
     * impersonation on a gambling product means an operator can place a
     * bet the audit log will attribute to a customer.
     */
    $super = roleUser(config('roles.super_admin'));
    $customer = roleUser(config('roles.user'));

    expect($super->can('delete', $customer))->toBeFalse()
        ->and($super->can('impersonate', $customer))->toBeFalse();
});

it('stops anybody changing their own role or suspending themselves', function () {
    $super = roleUser(config('roles.super_admin'));
    $admin = roleUser(config('roles.admin'));

    expect($super->can('assignRole', $super))->toBeFalse()
        ->and($admin->can('suspend', $admin))->toBeFalse();
});

it('lets only a super admin administer administrators', function () {
    $super = roleUser(config('roles.super_admin'));
    $admin = roleUser(config('roles.admin'));
    $customer = roleUser(config('roles.user'));

    expect($super->can('admin-management.viewAny'))->toBeTrue()
        ->and($admin->can('admin-management.viewAny'))->toBeFalse()
        ->and($customer->can('admin-management.viewAny'))->toBeFalse();
});

/* ----------------------------------------------------------------------
 | Route gating
 ---------------------------------------------------------------------- */

it('keeps every admin route a 404 for a customer, roles or not', function () {
    // The existing behaviour, re-asserted now that a second
    // authorization system is in the building. Spatie's own middleware
    // aborts 403; this product must not.
    $customer = roleUser(config('roles.user'));

    $routes = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.'))
        ->filter(fn ($route) => in_array('GET', $route->methods(), true))
        ->reject(fn ($route) => str_contains($route->uri(), '{'));

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $this->actingAs($customer)->get('/'.ltrim($route->uri(), '/'))
            ->assertNotFound();
    }
});
