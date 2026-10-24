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

    /** Games that have finished and have no result row yet. */
    public function scopeAwaitingSettlement(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('result')
            ->where('kickoff_at', '<', now());
    }

    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereHas('result');
    }
}
