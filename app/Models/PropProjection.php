<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * APPEND ONLY, and never written after kickoff.
 *
 * What the prop model said about one player and one line before a game,
 * kept so it can be graded afterwards. Not published and not publishable:
 * nothing here reaches a customer, and the `odds_snapshots` schema still
 * forbids a prop ever becoming a prediction or a pick.
 *
 * It exists because the gate is otherwise circular. A market earns the
 * right to claim an edge by beating a closing line, and nothing can beat
 * a closing line it was never measured against - so the measuring has to
 * start while the model is still unpublishable, and the record of it has
 * to be one nobody can tidy up later.
 *
 * Every row carries the parameters that produced it, because there is no
 * `model_versions` row behind a prop projection. Without them, changing
 * a half-life would silently pool two different models into one
 * calibration figure that describes neither.
 *
 * @see \App\Data\PropProjection the computed value this row records
 */
class PropProjection extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'line' => 'float',
            'projected_mean' => 'float',
            'projected_sd' => 'float',
            'prob_over' => 'float',
            'prob_under' => 'float',
            'prob_push' => 'float',
            'empirical_over' => 'float',
            'effective_games' => 'float',
            'fair_prob_over' => 'float',
            'games' => 'integer',
            'book_count' => 'integer',
            'best_over_price_american' => 'integer',
            'best_under_price_american' => 'integer',
            'model_params' => 'array',
            'measured_from' => 'datetime',
            'measured_to' => 'datetime',
            'kickoff_at' => 'datetime',
            'projected_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function result(): HasOne
    {
        return $this->hasOne(PropProjectionResult::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** Games that have kicked off and have no result row yet. */
    public function scopeAwaitingSettlement(Builder $query): Builder
    {
        return $query->whereDoesntHave('result')->where('kickoff_at', '<', now());
    }

    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereHas('result');
    }

    /**
     * Only the rows a calibration figure may be computed from.
     *
     * A void is excluded rather than counted. A player who did not take
     * the field has no box score line and most books void the prop, and
     * scoring that as an under would flatter this model in particular,
     * since it leans under - it would be marked right for a reason it
     * never predicted.
     */
    public function scopeGradable(Builder $query): Builder
    {
        return $query->whereHas('result', fn (Builder $q) => $q->whereIn('outcome', ['over', 'under']));
    }

    /**
     * Which side the model preferred, and how strongly.
     *
     * The push is held out of the comparison rather than assigned to a
     * side: a projection of 48% over, 44% under and 8% push prefers the
     * over, and saying so at 48% understates it. Renormalised over the
     * two sides that can win, which is the number a price is compared
     * against.
     *
     * @return array{side: string, probability: float}
     */
    public function preference(): array
    {
        $decided = $this->prob_over + $this->prob_under;

        if ($decided <= 0.0) {
            return ['side' => 'over', 'probability' => 0.5];
        }

        $over = $this->prob_over / $decided;

        return $over >= 0.5
            ? ['side' => 'over', 'probability' => $over]
            : ['side' => 'under', 'probability' => 1.0 - $over];
    }
}
