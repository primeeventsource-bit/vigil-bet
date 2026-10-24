<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Enums\Sport;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One middle as it stood when it was seen.
 *
 * The row carries two estimates of the same thing on purpose. The board's
 * own estimate of how often the gap lands is always available and needs no
 * model; the model's is there only when one has priced the game. They are
 * never averaged, because when they disagree that disagreement is the
 * finding.
 */
class MiddleOpportunity extends Model
{
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'market' => Market::class,
            'leg_a' => 'array',
            'leg_b' => 'array',
            'middle_low' => 'float',
            'middle_high' => 'float',
            'width' => 'float',
            'outs' => 'integer',
            'implied_sum' => 'float',
            'break_even_hit_prob' => 'float',
            'market_hit_prob' => 'float',
            'model_hit_prob' => 'float',
            'net_cost_pct' => 'float',
            'hit_pct' => 'float',
            'ev_market' => 'float',
            'ev_model' => 'float',
            'stake_split' => 'array',
            'reference_stake_cents' => 'integer',
            'placeable' => 'boolean',
            'oldest_quote_seconds' => 'integer',
            'detected_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Whether the board itself says the gap lands often enough to pay for
     * the overround. Null when no market estimate could be formed.
     */
    public function beatsBreakEven(): ?bool
    {
        return $this->market_hit_prob === null
            ? null
            : $this->market_hit_prob > $this->break_even_hit_prob;
    }
}
