<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's rating, on the 0-99 scale the sports games made legible.
 *
 * The table has existed since the ratings were first computed; this
 * model did not, because nothing ever read them outside the value
 * engine. Five thousand rows across four leagues, calculated nightly
 * and shown to nobody.
 *
 * The scale is borrowed and the method is not, and the distinction has
 * to survive into every surface that renders this. A rating of 94 means
 * the player's production sits in the 94th percentile of others in his
 * position, measured from box scores on file. It does not mean what 94
 * means in a video game, and `games` travels with it everywhere because
 * a 94 over eleven games and a 94 over four hundred are different
 * statements about the same player.
 */
class PlayerRating extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'overall' => 'integer',
            'games' => 'integer',
            'components' => 'array',
            'measured_from' => 'datetime',
            'measured_to' => 'datetime',
            'as_of' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function scopeForSport(Builder $query, string $sport): Builder
    {
        return $query->where('sport', $sport);
    }

    /**
     * Ratings worth showing.
     *
     * A percentile computed from a handful of games is noise wearing a
     * number, and a number is exactly what somebody will act on. The
     * floor is deliberately visible here rather than buried in a
     * controller, so every surface applies the same one.
     */
    public function scopeRanked(Builder $query, int $minimumGames = 5): Builder
    {
        return $query->where('games', '>=', $minimumGames)
            ->orderByDesc('overall')
            ->orderByDesc('games');
    }

    /**
     * How stale this is, in a form a page can say out loud.
     *
     * Ratings are recomputed from the games on file, so a rating is
     * always a statement about a moment. A page that renders the number
     * without the date implies it is current, and on the day a feed
     * stalls that implication is the whole of the lie.
     */
    public function isStale(int $hours = 48): bool
    {
        return $this->as_of === null || $this->as_of->lt(now()->subHours($hours));
    }

    /** The component scores, largest first, for a readable breakdown. */
    public function sortedComponents(): array
    {
        $components = (array) ($this->components ?? []);
        arsort($components);

        return $components;
    }
}
