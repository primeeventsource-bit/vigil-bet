<?php

declare(strict_types=1);

namespace App\Services\Trial;

use SensitiveParameter;

/**
 * Who a person is, for the purpose of "one trial each".
 *
 * The problem this solves is the cheapest repeat there is: the same
 * person signing up again as `name+2@gmail.com`, or with a dot moved.
 * Both arrive at the same inbox, and treating them as two people is
 * treating a trivially altered address as a second human being.
 *
 * The problem it must NOT create is merging two real people. That is
 * the worse failure by a distance: one of them is told their trial is
 * already spent and has no way to argue with it.
 *
 * So normalisation is narrow and provider-aware:
 *
 *   Everywhere  - trim, lowercase.
 *   Everywhere  - strip a +suffix. The convention is near universal and
 *                 its whole purpose is that mail still arrives.
 *   Gmail only  - strip dots from the local part, because Google
 *                 explicitly ignores them. At most other hosts a dot is
 *                 significant, and `j.smith@` and `js.mith@` can be two
 *                 different employees.
 *
 * Nothing here is a disposable-address list. Those go stale, they block
 * people with legitimate privacy habits, and the cost of being wrong is
 * 24 hours of access against somebody who could simply use a different
 * mailbox.
 *
 * The result is never stored in the clear. hash() is an HMAC keyed on
 * the application key, so trial_redemptions answers "has this person had
 * a turn" without being readable as a list of who signed up.
 */
final class EmailIdentity
{
    /**
     * Hosts that ignore dots in the local part.
     *
     * Google's own documentation states this for gmail.com and its
     * googlemail alias. Nothing else is assumed.
     *
     * @var array<int, string>
     */
    private const DOTLESS_HOSTS = ['gmail.com', 'googlemail.com'];

    /** The normalised address, for comparison only. */
    public static function normalise(#[SensitiveParameter] string $email): string
    {
        $email = mb_strtolower(trim($email));

        if (! str_contains($email, '@')) {
            // Not an address. Returned unchanged rather than guessed at;
            // validation is somebody else's job and a malformed value
            // should not silently become a different valid one.
            return $email;
        }

        [$local, $host] = explode('@', $email, 2);

        /*
         * The +suffix goes first and unconditionally. A local part that
         * is entirely a suffix - "+foo@host" - would normalise to
         * nothing, so that case keeps what it had.
         */
        if (str_contains($local, '+')) {
            $stripped = explode('+', $local, 2)[0];
            $local = $stripped !== '' ? $stripped : $local;
        }

        if (in_array($host, self::DOTLESS_HOSTS, true)) {
            $local = str_replace('.', '', $local);
        }

        return $local.'@'.$host;
    }

    /**
     * The stored form: HMAC-SHA256 over the normalised address.
     *
     * Keyed rather than a bare hash. A bare sha256 of an email address
     * is reversible by anybody with a word list, which is everybody, so
     * it would be storage pretending to be protection.
     */
    public static function hash(#[SensitiveParameter] string $email): string
    {
        return hash_hmac('sha256', self::normalise($email), self::key());
    }

    /** The same construction for an IP, so no second plaintext copy exists. */
    public static function hashIp(#[SensitiveParameter] ?string $ip): ?string
    {
        return $ip === null || $ip === '' ? null : hash_hmac('sha256', $ip, self::key());
    }

    private static function key(): string
    {
        $key = (string) config('app.key');

        // Laravel stores the key base64-prefixed. Hashing the prefix
        // along with it would still work, but decoding keeps the value
        // the same if the encoding ever changes.
        return str_starts_with($key, 'base64:')
            ? (string) base64_decode(substr($key, 7), true)
            : $key;
    }
}
