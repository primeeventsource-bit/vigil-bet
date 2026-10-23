<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Security\Totp;

function verifiedUser(array $overrides = []): User
{
    return User::forceCreate(array_merge([
        'name' => 'Sam Reader',
        'email' => 'sam@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ], $overrides));
}

function userWithTwoFactor(): User
{
    $user = verifiedUser();

    $user->forceFill([
        'two_factor_secret' => Totp::generateSecret(),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => ['aaaa1111-bbbb2222', 'cccc3333-dddd4444'],
    ])->save();

    return $user->fresh();
}

it('signs a user straight in when two factor is off', function () {
    verifiedUser();

    $this->post(route('login'), ['email' => 'sam@example.com', 'password' => 'correct-horse-battery-staple'])
        ->assertRedirect(route('today'));

    $this->assertAuthenticated();
});

it('does not sign anybody in until the second factor passes', function () {
    userWithTwoFactor();

    $this->post(route('login'), ['email' => 'sam@example.com', 'password' => 'correct-horse-battery-staple'])
        ->assertRedirect(route('two-factor.challenge'));

    // The half-authenticated state is a session key, never a logged-in
    // user with a flag saying "not really".
    $this->assertGuest();
});

it('finishes the login on a valid code', function () {
    $user = userWithTwoFactor();

    $this->post(route('login'), ['email' => 'sam@example.com', 'password' => 'correct-horse-battery-staple']);

    $this->post(route('two-factor.challenge'), ['code' => Totp::at($user->two_factor_secret, Totp::timestep())])
        ->assertRedirect(route('today'));

    $this->assertAuthenticatedAs($user);
});

it('refuses a wrong code and leaves the user signed out', function () {
    userWithTwoFactor();

    $this->post(route('login'), ['email' => 'sam@example.com', 'password' => 'correct-horse-battery-staple']);

    $this->post(route('two-factor.challenge'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('accepts a recovery code once and never again', function () {
    $user = userWithTwoFactor();

    $this->post(route('login'), ['email' => 'sam@example.com', 'password' => 'correct-horse-battery-staple']);
    $this->post(route('two-factor.challenge'), ['code' => 'aaaa1111-bbbb2222'])
        ->assertRedirect(route('today'));

    expect($user->fresh()->two_factor_recovery_codes)->toBe(['cccc3333-dddd4444']);

    // The same code again, on a fresh attempt, must fail.
    $this->post(route('logout'));
    $this->post(route('login'), ['email' => 'sam@example.com', 'password' => 'correct-horse-battery-staple']);
    $this->post(route('two-factor.challenge'), ['code' => 'aaaa1111-bbbb2222'])
        ->assertSessionHasErrors('code');
});

it('cannot reach the challenge without first passing the password', function () {
    userWithTwoFactor();

    $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    $this->post(route('two-factor.challenge'), ['code' => '123456'])->assertRedirect(route('login'));

    $this->assertGuest();
});

it('expires a sign-in attempt that is left half finished', function () {
    userWithTwoFactor();

    $this->post(route('login'), ['email' => 'sam@example.com', 'password' => 'correct-horse-battery-staple']);

    $this->travel(6)->minutes();

    $this->post(route('two-factor.challenge'), ['code' => '123456'])->assertRedirect(route('login'));
    $this->assertGuest();
});

it('says the same thing whether the account exists or the password is wrong', function () {
    verifiedUser();

    $missing = $this->post(route('login'), ['email' => 'nobody@example.com', 'password' => 'whatever']);
    $wrong = $this->post(route('login'), ['email' => 'sam@example.com', 'password' => 'whatever']);

    expect($missing->getSession()->get('errors')->first('email'))
        ->toBe($wrong->getSession()->get('errors')->first('email'));
});

it('only counts enrolment as finished once a code has been proved', function () {
    $user = verifiedUser();

    $user->forceFill(['two_factor_secret' => Totp::generateSecret()])->save();

    // A secret exists, but nothing was ever scanned.
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('needs the password to turn two factor off', function () {
    $user = userWithTwoFactor();

    $this->actingAs($user)
        ->delete(route('two-factor.destroy'), ['password' => 'not-the-password'])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->actingAs($user)
        ->delete(route('two-factor.destroy'), ['password' => 'correct-horse-battery-staple']);

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('shows recovery codes once and not on the next page load', function () {
    $user = verifiedUser();

    $this->actingAs($user)->post(route('two-factor.store'));

    $secret = $user->fresh()->two_factor_secret;

    $this->actingAs($user)
        ->post(route('two-factor.confirm'), ['code' => Totp::at($secret, Totp::timestep())])
        ->assertSessionHas('two-factor.recovery-codes');

    // Flashed, so the next request no longer carries them.
    $this->actingAs($user)->get(route('two-factor.show'))
        ->assertSessionMissing('two-factor.recovery-codes');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});
