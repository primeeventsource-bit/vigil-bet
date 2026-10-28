<?php

declare(strict_types=1);

namespace App\Services\Trial;

use App\Models\Subscription;
use App\Models\TrialRedemption;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * How many trials became customers, and which plan they chose.
 *
 * Every rate here is null when there is nothing to divide by, never
 * zero. "0% conversion" out of no trials is a figure somebody will act
 * on, and it is not a measurement of anything - the same rule the public
 * record, the shadow record and the opinion record already follow.
 *
 * Conversion is read from the subscription, not from a flag written at
 * the moment somebody paid. A flag would need something to write it and
 * would be wrong the first time anybody changed plan; a join is correct
 * by construction and costs nothing at this size.
 */
class TrialReport
{
    /** @return array<string, mixed> */
    public function summary(): array
    {
        $activated = User::query()->whereNotNull('trial_started_at')->count();

        /*
         * Converted means: started a trial, and now holds a tier that
         * has a price. Not "bought something at some point" - somebody
         * who subscribed and then cancelled is not a current customer
         * and counting them would make the number drift upward forever.
         */
        $sellable = $this->sellableTiers();

        $converted = User::query()
            ->whereNotNull('trial_started_at')
            ->whereHas('subscription', fn ($q) => $q
                ->whereIn('tier', $sellable)
                ->whereIn('status', ['active', 'trialing']))
            ->count();

        return [
            'activated' => $activated,
            'running' => User::query()->where('trial_expires_at', '>', now())->count(),
            'ended' => User::query()
                ->whereNotNull('trial_used_at')
                ->where(fn ($q) => $q->whereNull('trial_expires_at')->orWhere('trial_expires_at', '<=', now()))
                ->count(),

            // Registered, verified, and never pressed the button. The
            // number a trial funnel is usually losing people at.
            'never_started' => User::query()
                ->whereNull('trial_started_at')
                ->whereNotNull('email_verified_at')
                ->count(),

            'converted' => $converted,
            'conversion_rate' => $activated === 0 ? null : round($converted / $activated, 4),

            'by_tier' => $this->byTier(),
            'median_hours_to_convert' => $this->medianHoursToConvert(),

            'operator_granted' => TrialRedemption::query()->where('granted_by_operator', true)->count(),
        ];
    }

    /**
     * Which plan converts to, per tier.
     *
     * @return array<string, int>
     */
    public function byTier(): array
    {
        $counts = Subscription::query()
            ->whereIn('tier', $this->sellableTiers())
            ->whereIn('status', ['active', 'trialing'])
            ->whereHas('user', fn ($q) => $q->whereNotNull('trial_started_at'))
            ->selectRaw('tier, count(*) as n')
            ->groupBy('tier')
            ->pluck('n', 'tier')
            ->all();

        // Every sellable tier appears, including the ones nobody chose.
        // A tier missing from a report reads as a tier nobody can buy.
        $out = [];

        foreach ($this->sellableTiers() as $tier) {
            $out[$tier] = (int) ($counts[$tier] ?? 0);
        }

        return $out;
    }

    /**
     * How long a conversion takes, as a median rather than a mean.
     *
     * A mean is pulled around by one person who subscribed three months
     * later, and on a handful of conversions that is most of them. Null
     * below a sample worth quoting.
     */
    public function medianHoursToConvert(int $minimum = 3): ?float
    {
        $hours = User::query()
            ->whereNotNull('trial_started_at')
            ->whereHas('subscription', fn ($q) => $q
                ->whereIn('tier', $this->sellableTiers())
                ->whereIn('status', ['active', 'trialing']))
            ->with('subscription:id,user_id,created_at')
            ->get(['id', 'trial_started_at'])
            ->map(fn (User $u) => $u->subscription?->created_at === null
                ? null
                : $u->trial_started_at->diffInHours($u->subscription->created_at))
            ->filter(fn (?float $h) => $h !== null && $h >= 0)
            ->sort()
            ->values();

        if ($hours->count() < $minimum) {
            return null;
        }

        $middle = (int) floor($hours->count() / 2);

        return $hours->count() % 2 === 1
            ? round((float) $hours[$middle], 1)
            : round(((float) $hours[$middle - 1] + (float) $hours[$middle]) / 2, 1);
    }

    /** Trials running right now, soonest to end first. */
    public function running(int $limit = 50): Collection
    {
        return User::query()
            ->where('trial_expires_at', '>', now())
            ->with('subscription')
            ->orderBy('trial_expires_at')
            ->limit($limit)
            ->get(['id', 'name', 'email', 'trial_started_at', 'trial_expires_at']);
    }

    /** Trials that have ended, most recent first. */
    public function ended(int $limit = 50): Collection
    {
        return User::query()
            ->whereNotNull('trial_used_at')
            ->where(fn ($q) => $q->whereNull('trial_expires_at')->orWhere('trial_expires_at', '<=', now()))
            ->with('subscription')
            ->orderByDesc('trial_expires_at')
            ->limit($limit)
            ->get(['id', 'name', 'email', 'trial_started_at', 'trial_expires_at']);
    }

    /** @return array<int, string> */
    private function sellableTiers(): array
    {
        return array_keys(array_filter(
            (array) config('plans.tiers'),
            static fn (array $tier): bool => ($tier['monthly_cents'] ?? 0) > 0
        ));
    }
}
