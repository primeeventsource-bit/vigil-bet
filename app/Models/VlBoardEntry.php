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
    /**
     * The selections the engine came closest to betting, one each.
     *
     * One row per selection, not one per scan. current() keeps the
     * latest mirror of each edge_lab_id, which is right for a mirror and
     * wrong here: every scan writes its own board entry for the same
     * game, so the dashboard listed "Rutgers Scarlet Knights" five times
     * and called them five near-misses.
     *
     * Identity is the event, market, side and line. The line belongs in
     * it because on a spread a different number is a different bet; it is
     * compared null-safely, since a moneyline has none and `null = null`
     * is unknown.
     *
     * Spelled out rather than `is not distinct from`, which Postgres has
     * and SQLite does not, and the tests run on SQLite.
     */
    public function scopeNearMisses(Builder $query): Builder
    {
        return $query
            ->where('recommendation', 'pass')
            ->whereNotNull('edge_at_price')
            ->whereNotExists(function ($later): void {
                $later->selectRaw('1')
                    ->from('vl_board_entries as newer')
                    ->whereColumn('newer.edge_lab_event_id', 'vl_board_entries.edge_lab_event_id')
                    ->whereColumn('newer.market', 'vl_board_entries.market')
                    ->whereColumn('newer.side', 'vl_board_entries.side')
                    ->whereRaw('(newer.line = vl_board_entries.line or (newer.line is null and vl_board_entries.line is null))')
                    ->whereColumn('newer.id', '>', 'vl_board_entries.id');
            })
            ->orderByDesc('edge_at_price');
    }

    public function isSellablePick(): bool
    {
        return $this->recommendation === 'bet' && $this->market_verdict === 'proven';
    }
}
