<?php

declare(strict_types=1);

namespace App\Services\Trial;

use App\Models\TrialRedemption;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Starting, measuring and ending the 24 hours.
 *
 * Three rules hold this together, and each exists because its absence
 * is a way somebody gets a second trial or an unending one.
 *
 * ONE TRIAL PER IDENTITY. Enforced by a unique index on a hash of the
 * normalised address, not by a check-then-write - two requests arriving
 * together would both pass a check. The insert is the check.
 *
 * ONE ACTIVATION PER ACCOUNT. Enforced by locking the user row for the
 * length of the transaction, so a double-submitted form cannot start two
 * clocks and leave the later expiry winning.
 *
 * EXPIRY IS READ, NEVER SWEPT. Nothing runs on a schedule to end a
 * trial. `trial_expires_at` is compared to the server clock on every
 * request, in UTC, which is the same decision entitlement_overrides
 * already made: a grant that outlives its date because a worker was
 * down is a grant nobody can explain to the person who paid.
 */
class TrialService
{
    public function __construct(private readonly Geo $geo) {}

    public function hours(): int
    {
        return max(1, (int) config('plans.trial_hours', 24));
    }

    /** Started one, and it has not run out. */
    public function active(?User $user): bool
    {
        return $user?->trial_expires_at !== null && $user->trial_expires_at->isFuture();
    }

    /** Started one, and it has. */
    public function expired(?User $user): bool
    {
        return $user?->trial_used_at !== null && ! $this->active($user);
    }

    /**
     * May this person start one.
     *
     * Two questions, not one: has this account already started a trial,
     * and has the person behind it started one under another address.
     * The second is what the redemption table is for.
     */
    public function eligible(?User $user): bool
    {
        if ($user === null || ! $user->hasVerifiedEmail()) {
            return false;
        }

        if ($user->trial_started_at !== null) {
            return false;
        }

        return ! $this->redeemed($user->email);
    }

    public function redeemed(string $email): bool
    {
        return TrialRedemption::query()
            ->where('email_hash', EmailIdentity::hash($email))
            ->exists();
    }

    /** What is left, or null when no trial is running. */
    public function remaining(?User $user): ?Carbon
    {
        return $this->active($user) ? $user->trial_expires_at : null;
    }

    /**
     * Start the clock.
     *
     * Everything that must agree happens in one transaction with the
     * user row locked: the columns, the redemption, and the audit entry.
     * A redemption row with no trial on the account would be the worst
     * outcome available - the person is refused a trial they never had.
     *
     * @throws RuntimeException when the trial cannot be granted
     */
    public function activate(User $user, ?Request $request = null): User
    {
        $ip = $request?->ip();

        $location = $this->geo->locate($ip);

        try {
            return DB::transaction(function () use ($user, $ip, $location): User {
                /*
                 * Locked and re-read. The instance handed in may have
                 * been loaded before a concurrent request started its
                 * own activation, and acting on that copy is how two
                 * clocks get started.
                 */
                $fresh = User::query()->lockForUpdate()->findOrFail($user->getKey());

                if (! $fresh->hasVerifiedEmail()) {
                    throw new RuntimeException('The email address has not been confirmed.');
                }

                if ($fresh->trial_started_at !== null) {
                    throw new RuntimeException('This account has already used its trial.');
                }

                $startedAt = now();
                $expiresAt = $startedAt->copy()->addHours($this->hours());

                $fresh->forceFill([
                    'trial_started_at' => $startedAt,
                    'trial_expires_at' => $expiresAt,
                    // Set now, not at expiry. Nothing runs at expiry, and
                    // a flag written by a job that did not run is a
                    // second trial.
                    'trial_used_at' => $startedAt,
                ])->save();

                /*
                 * The unique index is the real control. An insert that
                 * collides means another address belonging to the same
                 * person already redeemed, and the transaction unwinds
                 * the columns above with it.
                 */
                TrialRedemption::create([
                    'email_hash' => EmailIdentity::hash($fresh->email),
                    'user_id' => $fresh->getKey(),
                    'redeemed_at' => $startedAt,
                    'country' => $location['country'],
                    'region' => $location['region'],
                    'asn' => $location['asn'],
                    'ip_hash' => EmailIdentity::hashIp($ip),
                    'granted_by_operator' => false,
                    'created_at' => $startedAt,
                ]);

                Audit::write('trial.activated', $fresh, null, [
                    'expires_at' => $expiresAt->toIso8601String(),
                    'hours' => $this->hours(),
                    'country' => $location['country'],
                ], 'Trial started by the account holder');

                return $fresh;
            });
        } catch (QueryException $e) {
            // The unique index firing is the expected outcome for a
            // repeat, not an error worth a stack trace.
            if (str_contains(mb_strtolower($e->getMessage()), 'unique')) {
                throw new RuntimeException('A trial has already been used for this address.');
            }

            throw $e;
        }
    }

    /**
     * An operator granting a trial to somebody who already had one.
     *
     * A separate path on purpose. It writes a second redemption row
     * rather than removing the first, so the history stays true and the
     * exception is visible as an exception.
     */
    public function grant(User $user, int $hours, User $operator, string $reason): User
    {
        return DB::transaction(function () use ($user, $hours, $reason): User {
            $fresh = User::query()->lockForUpdate()->findOrFail($user->getKey());

            $startedAt = now();
            $expiresAt = $startedAt->copy()->addHours($hours);

            $before = [
                'expires_at' => $fresh->trial_expires_at?->toIso8601String(),
                'consumed_at' => $fresh->trial_used_at?->toIso8601String(),
            ];

            $fresh->forceFill([
                'trial_started_at' => $fresh->trial_started_at ?? $startedAt,
                'trial_expires_at' => $expiresAt,
                'trial_used_at' => $startedAt,
            ])->save();

            /*
             * updateOrCreate rather than create: the identity is very
             * likely already in the table, which is why an operator is
             * doing this at all. The row is marked as an operator grant
             * so a reviewer can tell it from a self-service one.
             */
            TrialRedemption::query()->updateOrCreate(
                ['email_hash' => EmailIdentity::hash($fresh->email)],
                [
                    'user_id' => $fresh->getKey(),
                    'redeemed_at' => $startedAt,
                    'granted_by_operator' => true,
                    'created_at' => $startedAt,
                ]
            );

            Audit::write('trial.granted', $fresh, $before, [
                'expires_at' => $expiresAt->toIso8601String(),
                'hours' => $hours,
            ], $reason);

            return $fresh;
        });
    }

    /**
     * Other activations from the same network, for a human to weigh.
     *
     * Never consulted by activate(). It is shown on the admin pages and
     * that is all it is for - a shared office, a campus and a mobile
     * carrier all produce exactly this shape, and so does one person
     * with a VPN.
     */
    public function neighbours(?string $ipHash, int $hours = 24): int
    {
        if ($ipHash === null) {
            return 0;
        }

        return TrialRedemption::query()->fromSameNetwork($ipHash, $hours)->count();
    }
}
