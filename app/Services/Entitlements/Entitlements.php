<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Models\EntitlementOverride;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The one place that answers "may this customer have this?".
 *
 * One place on purpose. The failure mode for entitlements is not a wrong
 * answer, it is two answers: a check in a controller, another in a Blade
 * template, a third in an API resource, and a Pro feature that is hidden
 * in the UI and served by the route behind it. A customer who edits a URL
 * finds that in about a minute.
 *
 * So everything goes through `can()`. Routes gate on it, the navigation
 * reads it, policies defer to it, and `§13` is tested against it: a Free
 * user must not reach Pro data through any route, action or JSON
 * response.
 */
class Entitlements
{
    /** A signed-out visitor gets a named tier, never nothing. */
    public const ANONYMOUS_TIER = 'guest';

    /** The trial is over and nothing was bought. */
    public const EXPIRED_TIER = 'expired';

    /** The 24 hours themselves. */
    public const TRIAL_TIER = 'trial';

    /**
     * Overrides already read this request, keyed by user id.
     *
     * Not an optimisation for its own sake. `can()` is called once per
     * feature per row - the picks table asks `@can('board.full')` on
     * every line - and without this each call queried
     * entitlement_overrides again. A sixteen-game board ran twenty
     * extra identical queries, and the count grew with the board, which
     * is exactly the shape of failure that works in development and
     * falls over on a real Sunday.
     *
     * Per request rather than cached across requests: an override
     * granted by an admin must take effect on the customer's next page
     * load, not whenever a cache expires.
     *
     * @var array<int, Collection<string, EntitlementOverride>>
     */
    private array $overrides = [];

    /**
     * Forget what has been read.
     *
     * Called after an override is written, so the same request can see
     * its own change - admin grants a feature and then renders a page
     * that has to reflect it.
     */
    public function forget(?User $user = null): void
    {
        if ($user === null) {
            $this->overrides = [];

            return;
        }

        unset($this->overrides[$user->id]);
    }

    /**
     * What this person may see, in five steps whose order is the design.
     *
     * The order matters more than any individual step. A paid
     * subscription is checked before the trial so that buying during
     * the 24 hours cannot be read as a downgrade when the clock runs
     * out; the trial is checked before expiry so an active one is never
     * mistaken for a spent one; and everything falls through to `guest`
     * rather than to anything permissive, because the failure mode of a
     * missing case should be too little access rather than too much.
     */
    public function tier(?User $user): string
    {
        if ($user === null) {
            return self::ANONYMOUS_TIER;
        }

        $paid = $this->paidTier($user);

        if ($paid !== null) {
            return $paid;
        }

        /*
         * Read-time, UTC, server-side. Deliberately not a scheduled
         * sweep: entitlement_overrides already decided this question
         * the same way, because a comp that outlives its date because
         * a scheduler was down is a comp nobody can explain.
         */
        if ($user->trial_expires_at !== null && $user->trial_expires_at->isFuture()) {
            return self::TRIAL_TIER;
        }

        // Started one and it is over. Distinct from never having
        // started, because the upgrade screen and the conversion
        // reports both need to tell those two apart.
        if ($user->trial_used_at !== null) {
            return self::EXPIRED_TIER;
        }

        return self::ANONYMOUS_TIER;
    }

    /**
     * The tier a subscription actually bought, or null if none did.
     *
     * Null covers three cases that all mean the same thing here: no row
     * at all, a row whose effective tier is one of the unsold states,
     * and a row naming a tier that no longer exists in config. The last
     * is treated as unknown rather than trusted - a typo must never
     * become an upgrade for everybody.
     */
    private function paidTier(User $user): ?string
    {
        $tier = $user->subscription?->effectiveTier();

        if ($tier === null) {
            return null;
        }

        /*
         * A tier that was renamed still means what it was sold as.
         *
         * subscriptions.tier is a free-form string with no foreign key,
         * and billing_events is append-only, so the history genuinely
         * contains tier names that no longer appear in config -
         * including 'free', which every lapsed row still says.
         */
        $tier = (string) (config('plans.renamed.'.$tier) ?? $tier);

        if (! array_key_exists($tier, (array) config('plans.tiers'))) {
            return null;
        }

        // An unsold tier on a subscription row is not a purchase. It
        // falls through so the trial columns get their say.
        return ((int) config("plans.tiers.{$tier}.monthly_cents", 0)) > 0 ? $tier : null;
    }

    /**
     * The tiers that are actually for sale.
     *
     * `free` is the floor every signed-out visitor stands on, not a
     * product, so it never belongs on a pricing page, in a checkout
     * form or in an admin tier picker offering an upgrade. Anything
     * with a price is sellable; anything without is plumbing.
     *
     * @return array<int, string>
     */
    public function sellableTiers(): array
    {
        return array_keys(array_filter(
            (array) config('plans.tiers'),
            static fn (array $tier): bool => ($tier['monthly_cents'] ?? 0) > 0
        ));
    }

    /**
     * Whether this user may use this feature.
     *
     * An override beats the plan in both directions, which is the point
     * of having one: support can grant a feature while a billing problem
     * is sorted out, and can withdraw one without editing a tier.
     */
    public function can(?User $user, string $feature): bool
    {
        if ($user !== null) {
            $override = $this->overrides($user)->get($feature);

            if ($override !== null) {
                return (bool) $override->granted;
            }
        }

        return in_array($feature, $this->grantsFor($this->tier($user)), true);
    }

    public function cannot(?User $user, string $feature): bool
    {
        return ! $this->can($user, $feature);
    }

    /**
     * A numeric cap, or null for no cap.
     *
     * Null is unlimited and zero is none, and they are different answers
     * - a caller that treats a missing limit as zero locks everybody out,
     * and one that treats zero as missing lets everybody through.
     */
    public function limit(?User $user, string $key): ?int
    {
        $limits = (array) config('plans.tiers.'.$this->tier($user).'.limits', []);

        $value = $limits[$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    /** Whether a count has reached the cap for this user. */
    public function withinLimit(?User $user, string $key, int $used): bool
    {
        $limit = $this->limit($user, $key);

        return $limit === null || $used < $limit;
    }

    /** The lowest tier that would grant a feature, for an upgrade prompt. */
    /**
     * The cheapest tier somebody could BUY to get this feature.
     *
     * Only sellable tiers are considered, and that is the whole point
     * of the method. The trial grants almost everything Pro does, so
     * without this filter the upgrade page would answer "you need the
     * free trial" to a person whose trial has just run out - which is
     * both useless and faintly insulting. `guest` and `expired` are
     * excluded for the same reason: neither is something to upgrade to.
     *
     * Config order still decides which sellable tier wins, so Core
     * comes before Pro comes before Elite.
     */
    public function tierRequiredFor(string $feature): ?string
    {
        foreach ((array) config('plans.tiers') as $key => $tier) {
            if (((int) ($tier['monthly_cents'] ?? 0)) <= 0) {
                continue;
            }

            if (in_array($feature, (array) ($tier['grants'] ?? []), true)) {
                return $key;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    public function grantsFor(string $tier): array
    {
        return array_values((array) config('plans.tiers.'.$tier.'.grants', []));
    }

    /**
     * Every feature key the product knows about.
     *
     * Read from the plans rather than kept as a second list, so a feature
     * granted by a tier and a feature that exists cannot drift apart.
     *
     * @return array<int, string>
     */
    public function allFeatures(): array
    {
        return collect(config('plans.tiers'))
            ->flatMap(fn (array $tier) => $tier['grants'] ?? [])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Active overrides for a user, keyed by feature.
     *
     * An expired override is not an override. Expiry is checked on read
     * rather than swept by a job, because a comp that outlives its date
     * because a scheduler was down is the exact failure this is meant to
     * prevent.
     *
     * @return Collection<string, EntitlementOverride>
     */
    private function overrides(User $user): Collection
    {
        return $this->overrides[$user->id] ??= EntitlementOverride::query()
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->keyBy('feature');
    }
}
