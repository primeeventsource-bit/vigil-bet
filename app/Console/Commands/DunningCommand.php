<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BillingEvent;
use App\Models\Subscription;
use App\Notifications\DowngradedNotification;
use App\Services\Payments\SubscriptionProjector;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * Closes out dunning: three retries over seven days, then the free plan.
 *
 * The retries themselves belong to the processor - it holds the card and
 * it decides when to try again. What this command owns is the deadline,
 * and it owns it because the processor cannot be relied on to announce
 * it: a customer left in `past_due` because a final webhook never
 * arrived would keep a paid tier indefinitely.
 *
 * The emails at each attempt are sent when the failure arrives, in
 * WebhookController. This is only the end of the road.
 *
 * Nothing is deleted. §5 is explicit, and it is also the right thing:
 * somebody whose card expired should find their bets intact when they
 * come back.
 */
class DunningCommand extends Command
{
    protected $signature = 'vigil:dunning {--dry-run : List what would happen and change nothing}';

    protected $description = 'Downgrade subscriptions whose dunning window has run out';

    public function handle(SubscriptionProjector $projector): int
    {
        $schedule = (array) config('payments.dunning.retry_after_days', [1, 3, 7]);
        $attempts = count($schedule);
        $window = (int) (max($schedule) ?: 7);
        $dry = (bool) $this->option('dry-run');

        $overdue = Subscription::query()
            ->where('status', 'past_due')
            ->where('tier', '!=', 'free')
            ->with('user')
            ->get();

        if ($overdue->isEmpty()) {
            $this->components->info('No subscription is past due.');

            return self::SUCCESS;
        }

        $downgraded = 0;

        foreach ($overdue as $subscription) {
            $user = $subscription->user;

            if ($user === null) {
                continue;
            }

            $failures = $projector->failuresSinceLastSuccess($user);
            $since = $projector->firstFailureAt($user);

            /*
             * Either condition ends it: the agreed number of attempts
             * have failed, or the window has run out however many
             * attempts the processor managed. The second is there
             * because a processor that gives up early and stops sending
             * would otherwise leave the customer past due forever.
             */
            $exhausted = $failures >= $attempts
                || ($since !== null && $since->diffInDays(now()) >= $window);

            if (! $exhausted) {
                $this->components->twoColumnDetail(
                    $user->email,
                    'attempt '.$failures.' of '.$attempts.', waiting',
                );

                continue;
            }

            if ($dry) {
                $this->components->twoColumnDetail($user->email, '<fg=yellow>would downgrade</>');

                continue;
            }

            $from = $subscription->tier;

            $projector->record($user, BillingEvent::DOWNGRADED, [
                'driver' => $subscription->gateway ?? 'none',
                'gateway_customer_id' => $subscription->gateway_customer_id,
                'gateway_subscription_id' => $subscription->gateway_subscription_id,
                'tier' => 'free',
                'reason' => 'Dunning exhausted after '.$failures.' failed payments',
            ]);

            Audit::write('billing.downgraded', $user, ['tier' => $from], ['tier' => 'free'],
                'Dunning exhausted after '.$failures.' failed payments');

            $user->notify(new DowngradedNotification($from));

            $this->components->twoColumnDetail($user->email, '<fg=red>downgraded to free</>');
            $downgraded++;
        }

        $this->newLine();
        $this->components->info($dry
            ? 'Dry run. Nothing changed.'
            : $downgraded.' '.str('subscription')->plural($downgraded).' moved to the free plan.');

        return self::SUCCESS;
    }
}
