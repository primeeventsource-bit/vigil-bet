<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\MirrorsEdgeLab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * APPEND ONLY. One evaluated candidate as Edge Lab judged it.
 *
 * Every number on this row was read from the engine. Vigil does not
 * recompute an edge, round a stake or improve a probability, and the
 * absence of any method here that does arithmetic is deliberate.
 */
class VlBoardEntry extends Model
{
    use AppendOnly, HasFactory, MirrorsEdgeLab;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'line' => 'float',
            'model_prob' => 'float',
            'fair_prob' => 'float',
            'edge' => 'float',
            'edge_at_price' => 'float',
            'ev_per_dollar' => 'float',
            'kelly_fraction' => 'float',
            'best_price_american' => 'integer',
            'suggested_stake_cents' => 'integer',
            'payload' => 'array',
            'flagged_at' => 'datetime',
            'observed_at' => 'datetime',
        ];
    }

    /**
     * Entries Vigil is allowed to present as a pick.
     *
     * Both conditions, always. `recommendation` is the engine's decision
     * about this bet; `market_verdict` is whether the market has earned
     * the right to produce one at all. A row can say "bet" in a market
     * that was never proven - the gate is what stops it reaching a
     * customer, and checking only the recommendation is exactly the bug
     * that would sell an unproven pick.
     */
    public function scopeSellablePicks(Builder $query): Builder
    {
        return $query->where('recommendation', 'bet')->where('market_verdict', 'proven');
    }

    public function scopePasses(Builder $query): Builder
    {
        return $query->where('recommendation', 'pass');
    }

    /**
     * Near misses: examined, declined, and worth showing when there are no
     * proven picks. The spec calls this a feature rather than an empty
     * state, and it is only honest while it is labelled a pass.
     */
    public function scopeNearMisses(Builder $query): Builder
    {
        return $query->where('recommendation', 'pass')->whereNotNull('edge_at_price')->orderByDesc('edge_at_price');
    }

    public function isSellablePick(): bool
    {
        return $this->recommendation === 'bet' && $this->market_verdict === 'proven';
    }
}
