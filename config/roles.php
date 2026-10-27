<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Administrative roles and permissions
|--------------------------------------------------------------------------
|
| This file answers "who may operate the product". It is not, and must not
| become, the answer to "what has this customer paid for" - that question
| belongs to config/plans.php and is decided by the Entitlements service.
|
| The two are kept apart on purpose. A role here never grants a plan
| feature, and a plan feature is never a permission here. Mixing them is
| how an admin quietly ends up with a paid customer's entitlements, or a
| customer ends up with an operator's, and neither failure announces
| itself. RolesSeparationTest holds the line.
|
| SUPER_ADMIN is deliberately not given a permission list. It is granted
| everything by a Gate::before in VigilServiceProvider, so a permission
| added here next year is covered without anybody remembering to come
| back and tick it.
|
*/

return [

    'super_admin' => 'SUPER_ADMIN',
    'admin' => 'ADMIN',
    'user' => 'USER',

    /*
     * Every administrative permission, with the sentence an operator sees
     * beside the checkbox. The description is part of the definition
     * rather than the template, because a permission whose meaning is
     * only legible to whoever wrote it will eventually be ticked by
     * somebody who guessed.
     */
    'permissions' => [
        'users.manage' => 'Create, edit, suspend and reactivate customer accounts',
        'users.sessions' => 'Sign a customer out of every device',
        'users.reset' => 'Send a customer a password reset link',
        'subscriptions.view' => 'See plans, billing events and subscription history',
        'subscriptions.manage' => 'Change a customer plan, grant access, issue a refund',
        'sports.manage' => 'Manage sports, teams and feed configuration',
        'predictions.review' => 'See the engine board, model state and shadow record',
        'predictions.publish' => 'Publish approved opinions and picks',
        'analytics.view' => 'See platform analytics, AI cost and sync health',
        'support.view' => 'See a customer activity history for support purposes',
    ],

    /*
     * What a plain ADMIN gets on creation. Not a ceiling - a Super Admin
     * ticks whichever boxes a given person needs - but a default that
     * errs towards reading rather than writing. An administrator who can
     * see everything and change little is a support function; one who can
     * change everything on day one is a liability nobody chose.
     */
    /*
     * Abilities nobody gets, ever, including a Super Admin.
     *
     * Gate::before short-circuits the policy that would otherwise
     * refuse these, so without this list the before-hook silently
     * overrides a `return false` that was written to be absolute. That
     * is not theoretical: the roles test caught exactly that.
     *
     * Deleting an account would destroy bets, a public record and a
     * self-exclusion history - evidence, not just rows. Impersonation on
     * a gambling product means an operator can place a bet the audit log
     * will attribute to a customer. Neither has a good enough reason.
     */
    'never' => [
        'delete',
        'impersonate',
    ],

    'admin_defaults' => [
        'users.manage',
        'subscriptions.view',
        'predictions.review',
        'analytics.view',
        'support.view',
    ],

];
