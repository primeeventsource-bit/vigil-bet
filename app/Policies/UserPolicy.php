<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Who may do what to an account.
 *
 * Three rules run before anything else, and they are the whole point of
 * the class:
 *
 * 1. A Super Admin is untouchable by anyone but themselves. No edit, no
 *    role change, no suspension, no deletion, no impersonation - and
 *    that holds against another Super Admin too, so the role cannot be
 *    used to remove itself from somebody else.
 *
 * 2. Nobody suspends, demotes or deletes themselves. An operator who
 *    locks themselves out at 2am is an outage, and the mistake is one
 *    keystroke away on a page full of other people's rows.
 *
 * 3. Everything else needs the matching permission. Holding ADMIN is not
 *    itself authority to do anything; the permissions are.
 *
 * Gate::before in VigilServiceProvider grants a Super Admin every ability
 * except those aimed at another Super Admin, so the guards below are
 * what a plain ADMIN is actually measured against.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.manage') || $actor->can('support.view');
    }

    public function view(User $actor, User $subject): bool
    {
        return $actor->can('users.manage') || $actor->can('support.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.manage');
    }

    public function update(User $actor, User $subject): bool
    {
        return $this->mayTouch($actor, $subject) && $actor->can('users.manage');
    }

    /**
     * Changing somebody's role.
     *
     * Only a Super Admin, and never their own. Granting ADMIN is how an
     * account with `users.manage` would otherwise climb: edit a
     * colleague up, or themselves, and the permission boundary is gone.
     */
    public function assignRole(User $actor, User $subject): bool
    {
        return $actor->isSuperAdmin()
            && ! $subject->isSuperAdmin()
            && ! $actor->is($subject);
    }

    public function suspend(User $actor, User $subject): bool
    {
        return $this->mayTouch($actor, $subject) && $actor->can('users.manage');
    }

    public function revokeSessions(User $actor, User $subject): bool
    {
        return $this->mayTouch($actor, $subject) && $actor->can('users.sessions');
    }

    public function resetPassword(User $actor, User $subject): bool
    {
        // Not mayTouch: sending somebody a reset link is a courtesy, not
        // a seizure, and it is the one thing worth being able to do for
        // a Super Admin who is locked out.
        return $actor->can('users.reset');
    }

    public function delete(User $actor, User $subject): bool
    {
        /*
         * Nobody. Accounts carry bets, a record and a self-exclusion
         * history, and deleting one would take evidence with it. The
         * product suspends instead, which is reversible and leaves the
         * trail intact. The method exists so that `can('delete', $user)`
         * has an answer rather than falling through to a default.
         */
        return false;
    }

    public function impersonate(User $actor, User $subject): bool
    {
        /*
         * Also nobody, and this one is not a placeholder. Impersonation
         * on a gambling product means an operator can place a bet that
         * the audit log will attribute to a customer. There is no reason
         * good enough for that, and the support pages show what is
         * needed without it.
         */
        return false;
    }

    /**
     * The two hard rules, applied before any permission is consulted.
     */
    private function mayTouch(User $actor, User $subject): bool
    {
        if ($subject->isSuperAdmin()) {
            return false;
        }

        return ! $actor->is($subject);
    }
}
