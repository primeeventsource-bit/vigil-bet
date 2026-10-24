<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PassReason;
use App\Enums\Recommendation;
use App\Enums\Sport;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND ONLY. One judged parlay, with its legs frozen as they stood.
 */
class ParlayEvaluation extends Model
{
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'recommendation' => Recommendation::class,
            'pass_reason' => PassReason::class,
            'legs' => 'array',
            'leg_count' => 'integer',
            'correlated' => 'boolean',
            'model_joint_prob' => 'float',
            'fair_joint_prob' => 'float',
            'shaded_joint_prob' => 'float',
            'shade_used' => 'float',
            'offered_price_american' => 'integer',
            'offered_decimal' => 'float',
            'break_even' => 'float',
            'hold' => 'float',
            'hold_per_leg' => 'float',
            'ev_per_dollar' => 'float',
            'ev_shaded' => 'float',
            'kelly_fraction' => 'float',
            'suggested_stake_cents' => 'integer',
            'bankroll_cents' => 'integer',
            'evaluated_at' => 'datetime',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(ValueScan::class, 'value_scan_id');
    }

    public function scopePicks(Builder $query): Builder
    {
        return $query->where('recommendation', Recommendation::Bet);
    }

    public function isPick(): bool
    {
        return $this->recommendation === Recommendation::Bet;
    }

    /** How many legs have to land, said the way a slip reads. */
    public function describe(): string
    {
        return $this->leg_count.'-leg parlay';
    }
}
