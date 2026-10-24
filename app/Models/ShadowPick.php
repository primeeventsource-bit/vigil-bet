<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * APPEND ONLY, and never written after kickoff.
 *
 * A bet the engine would have made if the verdict gate had let it. Not a
 * pick: nobody was shown it, nobody could have placed it, and it is not
 * part of any record offered to a customer.
 *
 * It exists because the gate is otherwise a closed loop. A market only
 * becomes proven by accumulating graded predictions, and nothing in the
 * product accumulates evidence about whether the engine's *own* selections
 * would have won - only whether its probabilities beat the market's. This
 * table answers the first question, out of sample and in public view of
 * nobody, so that an eventual proven verdict has something behind it.
 *
 * PublishedPick says of itself that the public record is the sum of its
 * rows "and nothing else - no backtests, no paper results, no what the
 * model would have said". This is exactly the thing that sentence
 * excludes, which is why it lives in its own table, behind the admin
 * gate, and is labelled wherever it appears.
 *
 * The day these numbers are shown to a customer as results is the day the
 * product starts doing what its own front page says it does not.
 */
class ShadowPick extends Model
{
    use AppendOnly, HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'line' => 'float',
            'model_prob' => 'float',
            'fair_prob' => 'float',
            'edge_at_price' => 'float',
            'stake_fraction' => 'float',
            'price_american' => 'integer',
            'model_version' => 'integer',
            'kickoff_at' => 'datetime',
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function result(): HasOne
    {
        return $this->hasOne(ShadowResult::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function valueBet(): BelongsTo
    {
        return $this->belongsTo(ValueBet::class);
    }

    /**
     * One row per selection: the first time the engine would have bet it.
     *
     * The board is rescanned hourly and the recorder used to write a row
     * each time, so the table holds 61 duplicates from before that was
     * fixed - 92 rows standing for 31 selections. Counting those as
     * separate bets would scale units and CLV by the rescan rate, which
     * is a fact about the scheduler and not about betting.
     *
     * They are not deleted, because this table refuses deletes and that
     * refusal is the only reason its contents are worth anything. So the
     * duplicates stay and every reader takes the earliest row instead.
     *
     * Earliest by id rather than recorded_at: ids are unique, so the
     * choice is deterministic even for two rows written in the same
     * second, and on an append-only table id order is write order.
     *
     * The line is compared with a null-safe form because a moneyline has
     * none, and `null = null` is unknown - plain equality would treat
     * every h2h row as its own selection and dedupe nothing.
     */
    public function scopeCanonical(Builder $query): Builder
    {
        return $query->whereNotExists(function ($earlier): void {
            $earlier->selectRaw('1')
                ->from('shadow_picks as earlier')
                ->whereColumn('earlier.event_id', 'shadow_picks.event_id')
                ->whereColumn('earlier.market', 'shadow_picks.market')
                ->whereColumn('earlier.side', 'shadow_picks.side')
                // Spelled out rather than `is not distinct from`, which
                // Postgres has and SQLite does not; the tests run on SQLite.
                ->whereRaw('(earlier.line = shadow_picks.line or (earlier.line is null and shadow_picks.line is null))')
                ->whereColumn('earlier.id', '<', 'shadow_picks.id');
        });
    }

    /**
     * Games that have finished and have no result row yet.
     *
     * Canonical only: a duplicate must never be settled, or the same bet
     * is graded several times and the record counts it several times.
     */
    public function scopeAwaitingSettlement(Builder $query): Builder
    {
        return $query
            ->canonical()
            ->whereDoesntHave('result')
            ->where('kickoff_at', '<', now());
    }

    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereHas('result');
    }
}
