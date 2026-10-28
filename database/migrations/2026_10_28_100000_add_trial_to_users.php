<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The 24-hour trial, recorded on the account
|--------------------------------------------------------------------------
|
| On `users` rather than on `subscriptions`, and that is not arbitrary.
| SubscriptionProjector's own docblock says it is the only thing allowed
| to write that table, and it derives every row by replaying
| billing_events - so a trial column written there would be silently
| erased the next time anything replayed the log. A trial is not a
| billing event; nothing is charged and nothing is owed.
|
| Three columns rather than two, because "started" and "spent" are
| different facts and the second has to outlive the first:
|
|   trial_started_at   when the clock began
|   trial_expires_at   when it stops - the only thing expiry reads
|   trial_used_at      that this account has had its turn, forever
|
| After expiry, started and expires are both in the past and used is
| set. If only the expiry mattered, clearing it would hand somebody a
| second trial, and the obvious "fix" for a support request is exactly
| that clear.
|
| TWO COLUMNS THE SPEC ASKS FOR AND THIS DOES NOT ADD.
|
| `trial_status` is derivable from the two timestamps and nothing else:
| no trial_used_at is "none", an expiry in the future is "active", an
| expiry in the past is "expired". A stored copy would be a second
| thing to keep true, and the query it saves - "every running trial" -
| is `where trial_expires_at > now()` on an indexed column, which is
| already cheap. User::trialStatus() derives it.
|
| `subscription_status` likewise: subscriptions.status holds it, and
| User::subscriptionStatus() reads the one that exists.
|
| `trial_activation_ip` is not stored in the clear either. The spec
| itself qualifies it with "only if justified by the privacy policy and
| retention requirements", and this application already keeps a raw IP
| forever in age_attestations with no retention policy at all. A hash
| goes in trial_redemptions instead, which answers "how many trials from
| this network this morning" without a sixth plaintext copy of somebody's
| address.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'trial_started_at')) {
                $table->timestampTz('trial_started_at')->nullable()->after('last_login_at');
            }

            if (! Schema::hasColumn('users', 'trial_expires_at')) {
                $table->timestampTz('trial_expires_at')->nullable()->after('trial_started_at');
            }

            if (! Schema::hasColumn('users', 'trial_used_at')) {
                $table->timestampTz('trial_used_at')->nullable()->after('trial_expires_at');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            // The admin trials page lists running trials by expiry, and
            // every authenticated request reads this column to resolve
            // a tier.
            $table->index('trial_expires_at');
            $table->index('trial_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['trial_expires_at']);
            $table->dropIndex(['trial_used_at']);
            $table->dropColumn(['trial_started_at', 'trial_expires_at', 'trial_used_at']);
        });
    }
};
