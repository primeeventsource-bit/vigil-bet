<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\FeatureFlag;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;

/**
 * Switches that can be thrown without a deployment.
 *
 * §11 asks for feature flags. The reason they live in the database rather
 * than in config is the only reason they are ever worth having: turning
 * something off at two in the morning should not need a deploy.
 *
 * Every flag arrives with a sentence saying what it does, because a flag
 * nobody can explain is a flag nobody dares turn off - which is how a
 * codebase ends up with forty of them and no idea which are load-bearing.
 */
class FeatureFlags
{
    /**
     * The flags this product knows about, and what each does.
     *
     * Declared here rather than created ad hoc, so the admin page lists
     * every switch that exists rather than only the ones somebody has
     * touched - and so a flag read before it was ever written gets its
     * documented default instead of a null.
     *
     * @var array<string, array{description: string, default: bool}>
     */
    public const KNOWN = [
        'ai.reads' => [
            'description' => 'Generate Vigil Reads. Off falls back to the templated explanation.',
            'default' => true,
        ],
        'alerts.send' => [
            'description' => 'Deliver alerts. Off still raises them, so nothing is lost while it is off.',
            'default' => true,
        ],
        'picks.publish' => [
            'description' => 'Publish picks to the public record. Off is the kill switch for the record.',
            'default' => true,
        ],
        'billing.checkout' => [
            'description' => 'Offer checkout. Off takes the product off sale without a deploy.',
            'default' => false,
        ],
        'edgelab.sync' => [
            'description' => 'Pull from Edge Lab. Off stops the syncs and the board goes stale.',
            'default' => true,
        ],
    ];

    private const CACHE_KEY = 'vigil.feature_flags';

    /**
     * Whether a flag is on.
     *
     * An unknown key is false rather than true. A typo must not switch
     * something on, and a flag that does not exist is not a feature that
     * is enabled.
     */
    public function enabled(string $key): bool
    {
        if (! array_key_exists($key, self::KNOWN)) {
            return false;
        }

        return (bool) ($this->all()[$key] ?? self::KNOWN[$key]['default']);
    }

    public function disabled(string $key): bool
    {
        return ! $this->enabled($key);
    }

    /**
     * Every flag, stored value or documented default.
     *
     * Cached briefly: this is read on most requests and a flag taking a
     * minute to take effect is a fair trade for not querying it on every
     * page load. Cleared on write, so a deliberate change is immediate.
     *
     * @return array<string, bool>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinute(), function (): array {
            $stored = FeatureFlag::query()->pluck('enabled', 'key')->all();

            $flags = [];

            foreach (self::KNOWN as $key => $meta) {
                $flags[$key] = (bool) ($stored[$key] ?? $meta['default']);
            }

            return $flags;
        });
    }

    /**
     * Throw a switch, and record who did it and why.
     *
     * The reason is required. A flag flipped at two in the morning with
     * no note is a mystery by breakfast, and §11 asks for every admin
     * write to be audited.
     */
    public function set(string $key, bool $enabled, User $actor, string $reason): FeatureFlag
    {
        if (! array_key_exists($key, self::KNOWN)) {
            throw new \InvalidArgumentException('Unknown feature flag: '.$key);
        }

        $before = $this->enabled($key);

        $flag = FeatureFlag::updateOrCreate(['key' => $key], [
            'enabled' => $enabled,
            'description' => self::KNOWN[$key]['description'],
            'updated_by' => $actor->id,
        ]);

        Cache::forget(self::CACHE_KEY);

        Audit::write(
            'flag.'.($enabled ? 'enabled' : 'disabled'),
            $flag,
            ['enabled' => $before],
            ['enabled' => $enabled],
            $reason,
        );

        return $flag;
    }

    /** @return array<int, array<string, mixed>> */
    public function describe(): array
    {
        $stored = FeatureFlag::query()->with('updatedBy')->get()->keyBy('key');
        $values = $this->all();

        $rows = [];

        foreach (self::KNOWN as $key => $meta) {
            $row = $stored->get($key);

            $rows[] = [
                'key' => $key,
                'description' => $meta['description'],
                'enabled' => $values[$key],
                'default' => $meta['default'],
                // Whether somebody has touched it, which is different
                // from whether it is on.
                'overridden' => $row !== null && $row->enabled !== $meta['default'],
                'updated_by' => $row?->updatedBy?->name,
                'updated_at' => $row?->updated_at,
            ];
        }

        return $rows;
    }
}
