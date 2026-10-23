<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Entitlements\Entitlements;
use Illuminate\Support\Facades\Hash;

it('creates an account that can sign in and reach everything', function () {
    $this->artisan('vigil:owner', ['email' => 'Owner@Example.com'])
        ->assertSuccessful();

    $user = User::sole();

    // Lowercased, verified, and on a tier - all three are needed before
    // the account can get past the login, the verification notice and
    // the entitlement middleware respectively.
    expect($user->email)->toBe('owner@example.com')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(app(Entitlements::class)->tier($user))->toBe('pro');

    foreach (app(Entitlements::class)->allFeatures() as $feature) {
        expect(app(Entitlements::class)->can($user, $feature))->toBeTrue();
    }
});

it('does not give the owner account a paid period it never paid for', function () {
    $this->artisan('vigil:owner', ['email' => 'owner@example.com'])->assertSuccessful();

    $subscription = User::sole()->subscription;

    // Otherwise it turns up in the billing records at step 6 looking
    // exactly like revenue.
    expect($subscription->current_period_end)->toBeNull()
        ->and($subscription->source)->toBe('owner_command');
});

it('leaves an existing password alone when it is run again', function () {
    $this->artisan('vigil:owner', ['email' => 'owner@example.com', '--password' => 'known-password-here'])
        ->assertSuccessful();

    $before = User::sole()->password;

    $this->artisan('vigil:owner', ['email' => 'owner@example.com', '--tier' => 'daily'])
        ->assertSuccessful();

    $user = User::sole();

    expect($user->password)->toBe($before)
        // The point of the re-run: the tier is put back.
        ->and($user->subscription->tier)->toBe('daily')
        ->and(User::count())->toBe(1);
});

it('replaces the password only when asked to', function () {
    $this->artisan('vigil:owner', ['email' => 'owner@example.com', '--password' => 'known-password-here'])
        ->assertSuccessful();

    expect(Hash::check('known-password-here', User::sole()->password))->toBeTrue();

    $this->artisan('vigil:owner', ['email' => 'owner@example.com', '--reset-password' => true])
        ->assertSuccessful();

    expect(Hash::check('known-password-here', User::sole()->password))->toBeFalse();
});

it('refuses a tier that does not exist', function () {
    $this->artisan('vigil:owner', ['email' => 'owner@example.com', '--tier' => 'platinum'])
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('refuses something that is not an email address', function () {
    $this->artisan('vigil:owner', ['email' => 'not-an-address'])->assertFailed();

    expect(User::count())->toBe(0);
});

it('records that an owner account was made, because it is a privileged one', function () {
    $this->artisan('vigil:owner', ['email' => 'owner@example.com'])->assertSuccessful();
    $this->artisan('vigil:owner', ['email' => 'owner@example.com'])->assertSuccessful();

    expect(AuditEvent::pluck('action')->all())->toBe(['owner.created', 'owner.topped_up']);
});
