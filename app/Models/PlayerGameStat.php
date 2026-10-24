<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Sport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One player's line from one game.
 *
 * Not append-only, deliberately, and for the same reason
 * TeamGameStat is not: a box score is corrected after the whistle and
 * the later version is the right one. The guarantee that matters for
 * this table is the unique key, which stops the same line being counted
 * twice.
 *
 * There is no Player model and this does not need one. A player is the
 * source's id plus a name, the way `injury_reports` already treats them.
 * Nothing here looks a player up by identity; what the engine needs is
 * who was on the floor and what they did.
 */
class PlayerGameStat extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'played' => 'boolean',
            'stats' => 'array',
            'as_of' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
