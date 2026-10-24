<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * APPEND ONLY and versioned. The app reads the latest effective row, so a
 * stake suggested last month can still be explained by the settings that
 * were in force when it was made.
 */
class BankrollSetting extends Model
{
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starting_bankroll_cents' => 'integer',
            'unit_size_cents' => 'integer',
            'min_stake_cents' => 'integer',
            'max_stake_cents' => 'integer',
            'daily_loss_limit_cents' => 'integer',
            'weekly_loss_limit_cents' => 'integer',
            'kelly_fraction' => 'float',
            'max_stake_pct' => 'float',
            'max_daily_exposure_pct' => 'float',
            'edge_threshold' => 'float',
            'min_win_probability' => 'float',
            'min_book_count' => 'integer',
            'require_proven_market' => 'boolean',
            'effective_from' => 'datetime',
        ];
    }

    /**
     * What the stated maximum bet actually is as a share of bankroll.
     *
     * The percentage cap is a guard, not the plan. The plan is the stake
     * range I said I would bet. This reports what that range implies so the
     * risk is visible instead of being quietly clamped away.
     */
    public function impliedMaxStakePct(int $bankrollCents): float
    {
        if ($bankrollCents <= 0 || ! $this->max_stake_cents) {
            return 0.0;
        }

        return round(($this->max_stake_cents / $bankrollCents) * 100, 2);
    }

    /**
     * Whether a percentage guard is in force at all.
     *
     * Null means off, and off has to be a readable state rather than a
     * zero that every caller has to remember to treat specially. A
     * percentage of zero would mean no bet is ever allowed, which is the
     * opposite of what off means.
     */
    public function hasPercentageCap(): bool
    {
        return $this->max_stake_pct !== null && (float) $this->max_stake_pct > 0;
    }

    /** Whether any ceiling at all stands between Kelly and the bet. */
    public function hasStakeCeiling(): bool
    {
        return $this->hasPercentageCap() || ($this->max_stake_cents !== null && $this->max_stake_cents > 0);
    }

    /** Bankroll at which the stated maximum bet would sit on a given percentage. */
    public function bankrollNeededForPct(float $pct): int
    {
        if ($pct <= 0 || ! $this->max_stake_cents) {
            return 0;
        }

        return (int) ceil($this->max_stake_cents / ($pct / 100));
    }

    /**
     * How a single-bet share of bankroll reads. These are the conventional
     * bands, not a guarantee: a large bet size survives only while the edge
     * is real, and the edge is exactly what is unproven in month one.
     */
    public function riskBand(int $bankrollCents): string
    {
        $pct = $this->impliedMaxStakePct($bankrollCents);

        return match (true) {
            $pct <= 0.0 => 'unknown',
            $pct <= 2.0 => 'conservative',
            $pct <= 5.0 => 'aggressive',
            $pct <= 10.0 => 'very aggressive',
            default => 'ruin risk',
        };
    }

    /**
     * A new version of these settings, with some fields changed.
     *
     * The table is append-only, so a change is a new row and never an edit
     * to this one. That is the point rather than a constraint worked
     * around: a stake suggested last month has to stay explainable by the
     * settings that were in force when it was suggested, and it stops
     * being explainable the moment those settings can be rewritten.
     *
     * Everything not named in $changes is copied forward, so revising one
     * field cannot quietly reset the rest to a default.
     *
     * @param  array<string, mixed>  $changes
     */
    public function revise(array $changes): self
    {
        $carried = collect($this->getAttributes())
            ->except(['id', 'created_at', 'effective_from', 'note'])
            ->all();

        // $changes first: array union keeps the left side, so the other way
        // round would carry the old values forward over the new ones.
        return static::create($changes + $carried + [
            'effective_from' => now(),
        ]);
    }

    public static function current(): ?self
    {
        return static::query()
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}
