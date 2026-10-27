<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Administering the administrators.
 *
 * Every method here is Super Admin only, which is the spec's rule stated
 * once rather than repeated at each call site: an ADMIN must not be able
 * to create another ADMIN, widen their own permissions, or revoke a
 * colleague's.
 *
 * This is not a policy on a model - there is no Admin model, an
 * administrator is a User with a role. It is registered against the
 * string 'admin-management' and reached as Gate::allows('admin-management.*').
 */
class AdminPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isSuperAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isSuperAdmin();
    }

    /**
     * Granting or withdrawing individual permissions.
     *
     * Refused against another Super Admin for the same reason the user
     * policy refuses everything else: the role must not become a way of
     * dismantling itself in somebody else's account.
     *
     * Refused against oneself too. A Super Admin already has every
     * ability through Gate::before, so there is nothing legitimate to
     * grant themselves here - only something to take away by accident.
     */
    public function grantPermissions(User $actor, User $subject): bool
    {
        return $actor->isSuperAdmin()
            && ! $subject->isSuperAdmin()
            && ! $actor->is($subject);
    }

    public function revoke(User $actor, User $subject): bool
    {
        return $this->grantPermissions($actor, $subject);
    }
}
