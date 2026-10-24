<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InjuryStatus;
use App\Enums\Sport;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * APPEND ONLY. A point-in-time availability report.
 *
 * Two timestamps, and a feature must respect both. `reported_at` is when
 * the source says the report was made; `created_at` is when we fetched it.
 * Filtering on `reported_at` alone looks right and is not, because the feed
 * only publishes availability as it stands *now*: a report dated February
 * that we pulled in October is still October's knowledge. Using it for a
 * March game would be reading a list of the injuries that turned out to
 * last, which is a fact from after the game.
 *
 * So the leakage rule for this table is the stricter of the two: a report
 * is usable for a game only if it was both reported and fetched before
 * kickoff. `availableBefore()` is the only correct way to read it, and it
 * exists so nobody has to remember that.
 */
class InjuryReport extends Model
{
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * Reports a model is allowed to see for a game starting at $kickoff.
     *
     * Both timestamps, deliberately. See the class note: the survivorship
     * bias in a current-only feed is invisible if you only check the date
     * the source put on the row.
     */
    public function scopeAvailableBefore(Builder $query, Carbon $kickoff): Builder
    {
        return $query
            ->where('reported_at', '<', $kickoff)
            ->whereNotNull('created_at')
            ->where('created_at', '<', $kickoff);
    }

    /** Reports that say a player is definitely not playing. */
    public function scopeUnavailable(Builder $query): Builder
    {
        return $query->where('status', InjuryStatus::Out->value);
    }

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'status' => InjuryStatus::class,
            'reported_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
