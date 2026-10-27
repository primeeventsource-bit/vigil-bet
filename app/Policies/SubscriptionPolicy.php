<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

/**
 * Who may see and change what a customer is paying for.
 *
 * Split deliberately: seeing a plan is a support function and reads
 * nothing sensitive, while changing one moves money and access. The two
 * carry different permissions so an administrator can be given the first
 * without the second, which is what most support work actually needs.
 *
 * A customer's own subscription is readable by them. That is not an
 * administrative question, but it belongs here so there is one file to
 * read when asking "who can see this row".
 */
class SubscriptionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('subscriptions.view');
    }

    public function view(User $actor, Subscription $subscription): bool
    {
        return $actor->getKey() === $subscription->user_id
            || $actor->can('subscriptions.view');
    }

    /**
     * Changing a tier by hand.
     *
     * Never against a Super Admin: tier is access, and an ADMIN able to
     * move a Super Admin to free could take the admin pages away from
     * the one account that outranks them.
     */
    public function manage(User $actor, Subscription $subscription): bool
    {
        if ($subscription->user?->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            return false;
        }

        return $actor->can('subscriptions.manage');
    }

    public function refund(User $actor): bool
    {
        return $actor->can('subscriptions.manage');
    }
}
