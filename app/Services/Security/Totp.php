<?php

declare(strict_types=1);

namespace App\Services\Security;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Time-based one-time passwords, RFC 6238.
 *
 * Written here rather than pulled in: it is about forty lines of HMAC and
 * base32, every line of it checkable against the RFC's own test vectors,
 * and a dependency in the login path is a dependency that can change
 * under you.
 *
 * Three details that are the whole security of the thing:
 *
 *  - Codes are compared with `hash_equals`, never `===`. A byte-by-byte
 *    comparison that returns early leaks, in its timing, how much of a
 *    guess was right, and six digits is few enough for that to matter.
 *  - The window is one step either side of now, and no more. That covers a
 *    clock a few seconds out and a code typed slowly; wider is just a
 *    longer life for a code somebody else has already seen.
 *  - A code that has been used is not accepted again inside its own
 *    window. Replay inside thirty seconds is the one attack the algorithm
 *    does nothing about by itself, so the caller gets the timestep back
 *    and is expected to remember it.
 */
final class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    /** One step either side. See the class note. */
    public const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A fresh shared secret, base32 as every authenticator app expects.
     *
     * 160 bits, which is what RFC 4226 asks for and twice the shortest
     * interoperable secret.
     */
    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 16) {
            throw new InvalidArgumentException('A TOTP secret must be at least 128 bits.');
        }

        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * The code for one timestep. Exposed mainly so tests can check this
     * against the RFC vectors rather than against itself.
     */
    public static function at(#[SensitiveParameter] string $secret, int $timestep): string
    {
        $key = self::base32Decode($secret);

        if ($key === '') {
            throw new InvalidArgumentException('TOTP secret is not valid base32.');
        }

        // The counter is eight bytes, big endian. pack('J') is 64-bit BE.
        $hash = hash_hmac('sha1', pack('J', $timestep), $key, true);

        // Dynamic truncation, RFC 4226 section 5.3: the low nibble of the
        // last byte picks where in the digest to read the code from.
        $offset = ord($hash[19]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function timestep(?int $at = null): int
    {
        return intdiv($at ?? time(), self::PERIOD);
    }

    /**
     * Check a code, and say which timestep it belonged to.
     *
     * Returns the timestep on success so the caller can store it and
     * refuse the same code a second time. Null means no match.
     */
    public static function verify(
        #[SensitiveParameter] string $secret,
        #[SensitiveParameter] string $code,
        ?int $after = null,
        ?int $at = null,
    ): ?int {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $now = self::timestep($at);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $now + $offset;

            // Already spent. The algorithm cannot tell a replay from a
            // first use, so the caller's memory is the only thing that can.
            if ($after !== null && $step <= $after) {
                continue;
            }

            if (hash_equals(self::at($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** The otpauth:// URI an authenticator app scans. */
    public static function provisioningUri(
        #[SensitiveParameter] string $secret,
        string $account,
        string $issuer,
    ): string {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');

        if ($secret === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($secret) as $char) {
            $index = strpos(self::BASE32, $char);

            if ($index === false) {
                return '';
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
