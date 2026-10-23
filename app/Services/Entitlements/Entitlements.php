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
    /** A signed-out visitor gets the free tier, never nothing. */
    public const ANONYMOUS_TIER = 'free';

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

    public function tier(?User $user): string
    {
        $tier = $user?->subscription?->effectiveTier();

        // An unknown tier is treated as free rather than trusted. A typo
        // in a config file must not become a free upgrade for everybody.
        return $tier !== null && array_key_exists($tier, (array) config('plans.tiers'))
            ? $tier
            : self::ANONYMOUS_TIER;
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
    public function tierRequiredFor(string $feature): ?string
    {
        foreach ((array) config('plans.tiers') as $key => $tier) {
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
