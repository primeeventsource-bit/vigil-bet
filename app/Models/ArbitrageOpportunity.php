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
 * One arbitrage as it stood at the moment it was seen. The prices are
 * frozen onto the row because the board moves within seconds and the whole
 * value of the history is knowing what was actually on offer.
 */
class ArbitrageOpportunity extends Model
{
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'market' => Market::class,
            'legs' => 'array',
            'stake_split' => 'array',
            'line' => 'float',
            'implied_sum' => 'float',
            'profit_pct' => 'float',
            'reference_stake_cents' => 'integer',
            'guaranteed_profit_cents' => 'integer',
            'placeable' => 'boolean',
            'oldest_quote_seconds' => 'integer',
            'detected_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** Profit as a percentage of total stake, for display. */
    public function profitPercent(): float
    {
        return $this->profit_pct * 100.0;
    }
}
