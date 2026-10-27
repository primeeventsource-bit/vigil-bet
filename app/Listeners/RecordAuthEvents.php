<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Security events worth keeping, and the ones deliberately not kept.
 *
 * Listened for centrally rather than written at each call site, because
 * there are two places that call Auth::login() - password-only and
 * post-2FA - and a third would arrive one day without anybody thinking
 * to add a line to it.
 *
 * WHAT IS NOT RECORDED, and why it matters:
 *
 * `audit_log` is append-only, enforced by Postgres triggers that refuse
 * UPDATE and DELETE, and nothing prunes it. Anything written there is
 * written for the life of the database. That makes a per-attempt record
 * of failed logins an unbounded growth vector controlled by whoever is
 * guessing passwords - a slow denial of service with no cleanup
 * available, because the trigger would refuse the cleanup too.
 *
 * So failures are counted by the rate limiter, which already exists and
 * already forgets, and only the lockout is recorded: bounded by the
 * throttle, and the part an operator actually wants to see.
 *
 * Successful logins are not recorded either. "When did they last sign
 * in" is a question about the current state of one account, which is
 * what users.last_login_at answers in one indexed column, sortable and
 * filterable, instead of a row per person per day forever.
 *
 * What IS recorded is rare, deliberate and security-relevant: a
 * lockout, a completed password reset, and - written from their own
 * call sites - role changes, suspensions and session revocations.
 */
class RecordAuthEvents
{
    /**
     * Stamp the last sign-in. Not an audit row - a column.
     */
    public function handleLogin(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        // Quietly: this must not fire the saved hook, bump updated_at,
        // or turn every sign-in into a model event somebody else is
        // listening for.
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
    }

    /**
     * Somebody hit the throttle. Bounded by the limiter, so safe to keep.
     */
    public function handleLockout(Lockout $event): void
    {
        Audit::write(
            'auth.lockout',
            null,
            null,
            ['email' => (string) $event->request->input('email', '')],
            'Too many sign-in attempts'
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $user = $event->user;

        Audit::write(
            'auth.password_reset',
            $user instanceof User ? $user : null,
            null,
            null,
            'Password changed through a reset link'
        );
    }
}
