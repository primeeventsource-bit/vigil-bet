<?php

declare(strict_types=1);

use App\Services\Security\Totp;

/*
 * Checked against RFC 6238's own test vectors rather than against itself.
 * A TOTP implementation that only agrees with its own output is a
 * six-digit random number generator that both ends happen to share.
 *
 * The RFC's SHA-1 secret is the ASCII string "12345678901234567890"; what
 * goes in here is its base32 encoding, because that is what an
 * authenticator app is given.
 */
const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

it('matches the RFC 6238 test vectors', function (int $time, string $expected) {
    expect(Totp::at(RFC_SECRET, intdiv($time, 30)))->toBe($expected);
})->with([
    [59, '287082'],
    [1111111109, '081804'],
    [1111111111, '050471'],
    [1234567890, '005924'],
    [2000000000, '279037'],
]);

it('accepts a code from the step either side of now', function () {
    $secret = Totp::generateSecret();
    $now = time();

    foreach ([-30, 0, 30] as $drift) {
        $code = Totp::at($secret, Totp::timestep($now + $drift));

        expect(Totp::verify($secret, $code, null, $now))->not->toBeNull();
    }
});

it('refuses a code from two steps away', function () {
    $secret = Totp::generateSecret();
    $now = time();

    $stale = Totp::at($secret, Totp::timestep($now - 90));

    expect(Totp::verify($secret, $stale, null, $now))->toBeNull();
});

it('refuses a code that has already been spent', function () {
    $secret = Totp::generateSecret();
    $now = time();
    $code = Totp::at($secret, Totp::timestep($now));

    $step = Totp::verify($secret, $code, null, $now);

    expect($step)->not->toBeNull()
        // Replay inside the same thirty seconds is the one attack the
        // algorithm does nothing about on its own.
        ->and(Totp::verify($secret, $code, $step, $now))->toBeNull();
});

it('refuses anything that is not six digits', function (string $code) {
    expect(Totp::verify(Totp::generateSecret(), $code))->toBeNull();
})->with(['', '12345', '1234567', 'abcdef', '    ']);

it('mints a secret an authenticator app can read', function () {
    $secret = Totp::generateSecret();

    expect($secret)->toMatch('/^[A-Z2-7]+$/')
        ->and(strlen($secret))->toBe(32);
});

it('builds a provisioning uri with the brand on it', function () {
    $uri = Totp::provisioningUri(RFC_SECRET, 'sam@example.com', 'Vigil');

    expect($uri)->toStartWith('otpauth://totp/')
        ->and($uri)->toContain('issuer=Vigil')
        ->and($uri)->toContain('digits=6')
        ->and($uri)->toContain('period=30');
});
