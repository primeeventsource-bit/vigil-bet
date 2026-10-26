<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Verdict;
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
        return $query->where('recommendation', 'bet')
            ->whereIn('market_verdict', self::recommendableVerdicts())
            ->latestPerSelection();
    }

    /**
     * The verdicts that may surface a pick.
     *
     * Derived from the enum rather than restated here, because this list
     * used to be the single word 'proven' written out in four places -
     * and adding a tier would have left some of them behind, which is
     * how a market becomes publishable on one screen and not another.
     *
     * @return array<int, string>
     */
    public static function recommendableVerdicts(): array
    {
        return array_values(array_map(
            static fn (Verdict $verdict): string => $verdict->value,
            array_filter(Verdict::cases(), static fn (Verdict $verdict): bool => $verdict->allowsRecommendation()),
        ));
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
    /**
     * The engine's latest word on each selection.
     *
     * `current()` collapses re-syncs of one engine row; it cannot
     * collapse successive scans, because each writes a new row with its
     * own id. The board is rescanned hourly, so without this a customer
     * sees the same pick once per scan - two identical rows, same price,
     * same edge, which reads as a broken page rather than a stale one.
     *
     * A newer row of any kind supersedes, not only a newer `bet`. If the
     * last scan passed on a selection it had recommended an hour ago,
     * the pass is the current answer and the recommendation must stop
     * being shown.
     */
    public function scopeLatestPerSelection(Builder $query): Builder
    {
        return $query->whereNotExists(function ($later): void {
            $later->selectRaw('1')
                ->from('vl_board_entries as newer')
                ->whereColumn('newer.edge_lab_event_id', 'vl_board_entries.edge_lab_event_id')
                ->whereColumn('newer.market', 'vl_board_entries.market')
                // Null-aware, like the line below it. `side = side` is
                // false when both are null, so a column that went missing
                // would not widen the match - it would silently stop the
                // whole filter matching anything at all.
                ->whereRaw('(newer.side = vl_board_entries.side or (newer.side is null and vl_board_entries.side is null))')
                ->whereRaw('(newer.line = vl_board_entries.line or (newer.line is null and vl_board_entries.line is null))')
                ->whereColumn('newer.id', '>', 'vl_board_entries.id');
        });
    }

    /**
     * Entries on games that have not started.
     *
     * A pick on a game already under way is not one anybody can take,
     * and a finished game sitting on today's card with a Bet button
     * beside it is the product asserting something untrue about the
     * world. Kickoff lives on the mirrored event rather than here, so it
     * is read across rather than duplicated onto every entry.
     *
     * Deliberately not folded into `sellablePicks()`: the board page
     * lets a customer choose a past date on purpose, and that is a
     * different question from what is actionable now.
     */
    public function scopeBeforeKickoff(Builder $query): Builder
    {
        return $query->whereIn(
            'edge_lab_event_id',
            VlEvent::query()->current()->where('starts_at', '>', now())->select('edge_lab_id'),
        );
    }

    public function scopeNearMisses(Builder $query): Builder
    {
        return $query
            ->where('recommendation', 'pass')
            ->whereNotNull('edge_at_price')
            ->latestPerSelection()
            ->orderByDesc('edge_at_price');
    }

    public function isSellablePick(): bool
    {
        return $this->recommendation === 'bet'
            && in_array($this->market_verdict, self::recommendableVerdicts(), true);
    }
}
