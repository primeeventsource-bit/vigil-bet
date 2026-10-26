<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND ONLY. Where a player sat on his team's chart, and when.
 *
 * The rank is the point. An absence counts for what the team loses by
 * it, and a side losing its first choice at a position loses something
 * a fourth choice was never providing.
 *
 * Read through `asKnownBefore()` rather than by hand. A chart fetched
 * after kickoff describes a team sheet partly chosen because of the
 * result, and a backtest reading one would look prescient about exactly
 * the games where it mattered most.
 */
class DepthChartEntry extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'as_of' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Entries that were on file before a moment.
     *
     * Both timestamps, for the reason `InjuryReport` gives at length: a
     * chart dated last week but fetched tonight is still tonight's
     * knowledge, because the provider only ever publishes the chart as
     * it stands now.
     */
    public function scopeAsKnownBefore(Builder $query, mixed $moment): Builder
    {
        return $query->where('as_of', '<', $moment)
            ->where(function (Builder $inner) use ($moment): void {
                $inner->whereNull('created_at')->orWhere('created_at', '<', $moment);
            });
    }
}
