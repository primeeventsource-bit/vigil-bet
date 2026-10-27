<?php

declare(strict_types=1);

use App\Models\BillingEvent;
use App\Models\Subscription;
use App\Services\Payments\SubscriptionProjector;
use Illuminate\Database\Migrations\Migration;

/*
|--------------------------------------------------------------------------
| Move live subscriptions onto the new tier names
|--------------------------------------------------------------------------
|
| Three rules, and the reasoning for each.
|
| Written through the projector, not as an UPDATE. SubscriptionProjector
| is the only thing allowed to write `subscriptions`, and it derives every
| row by folding billing_events. An UPDATE here would be overwritten the
| next time anything replayed the log - silently, and only for the
| customers a webhook happened to touch.
|
| `pro` maps to `elite`, not to the new `pro`. Both existing rows are
| owner accounts sitting on what was the top tier and holding every
| feature the product had. The new `pro` grants strictly less than the
| old one did, so mapping by name would take away access somebody has,
| on the day of a rename, for no reason they could discover.
|
| `daily` is NOT touched. config('plans.renamed') resolves it to `core`
| at read time, which means the history keeps saying what was actually
| sold while access lands in the right place. Rewriting it would be
| falsifying a record to save a lookup.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        $projector = app(SubscriptionProjector::class);

        Subscription::query()
            ->where('tier', 'pro')
            ->with('user')
            ->each(function (Subscription $subscription) use ($projector): void {
                if ($subscription->user === null) {
                    return;
                }

                $projector->record($subscription->user, BillingEvent::PLAN_CHANGED, [
                    'tier' => 'elite',
                    // Carried forward rather than rebuilt: the projector
                    // folds `?? $state[...]`, so a field omitted here
                    // keeps whatever the log already established.
                    'interval' => $subscription->interval,
                    'period_end' => $subscription->current_period_end,
                    'gateway_customer_id' => $subscription->gateway_customer_id,
                    'gateway_subscription_id' => $subscription->gateway_subscription_id,
                    'occurred_at' => now(),
                    'reason' => 'Tier rename: pro became elite so no access was lost',
                ]);
            });
    }

    public function down(): void
    {
        /*
         * Nothing. billing_events is append-only and enforced by a
         * Postgres trigger, so the events written above cannot be
         * deleted even by a rollback - and a compensating PLAN_CHANGED
         * back to `pro` would be a second real event claiming a plan
         * change that never happened. Rolling the code back is enough:
         * config('plans.renamed') would need the inverse entry, which
         * is a decision for whoever rolls back, not an assumption to
         * bake in here.
         */
    }
};
