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
        // The top tier, whatever it is called this month. The command
        // exists to make an account that can reach the whole product,
        // so its default has to follow the top of config rather than a
        // name that was the top when it was written.
        ->and(app(Entitlements::class)->tier($user))
        ->toBe(array_key_last((array) config('plans.tiers')));

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

    $this->artisan('vigil:owner', ['email' => 'owner@example.com', '--tier' => 'core'])
        ->assertSuccessful();

    $user = User::sole();

    expect($user->password)->toBe($before)
        // The point of the re-run: the tier is put back.
        ->and($user->subscription->tier)->toBe('core')
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

it('makes the account an admin, because nothing else can', function () {
    $this->artisan('vigil:owner', ['email' => 'owner@example.com'])->assertSuccessful();

    // There is no other way to set the flag, and an admin area nobody
    // can reach is not an admin area.
    expect(User::sole()->isAdmin())->toBeTrue();

    /*
     * Sent to enrol a second factor, not into the overview. The command
     * bootstraps an operator account into an environment that may have
     * no mailer; it cannot bootstrap a phone. So the account is an admin
     * the moment it exists and reaches the admin area one enrolment
     * later, which is the order the requirement implies.
     */
    $this->actingAs(User::sole())->get(route('admin.overview'))
        ->assertRedirect(route('two-factor.show'));

    User::sole()->forceFill([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs(User::sole()->fresh())->get(route('admin.overview'))->assertOk();
});

it('can create an account without admin access', function () {
    $this->artisan('vigil:owner', ['email' => 'owner@example.com', '--no-admin' => true])
        ->assertSuccessful();

    expect(User::sole()->isAdmin())->toBeFalse();

    $this->actingAs(User::sole())->get(route('admin.overview'))->assertNotFound();
});
