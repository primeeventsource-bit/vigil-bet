<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Sport;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team's id at one source, within one sport.
 *
 * The sport is part of the key, not decoration. Sources number teams per
 * league - ESPN's team 14 exists in the NHL, the NBA and college
 * basketball, and means something different in each - so a row is only
 * unique once the sport is included. Without it the first league to sync
 * claims the number and every later league silently reads its team.
 */
class TeamExternalId extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * Keep `sport` in step with the team it points at.
     *
     * The column is denormalised from the team purely so it can take part
     * in the unique index, which cannot span a join. Deriving it here
     * rather than at each call site means the two can never disagree, and
     * no caller has to remember a field that is never theirs to choose.
     */
    protected static function booted(): void
    {
        static::saving(function (self $link): void {
            if ($link->team_id === null) {
                return;
            }

            $sport = $link->team?->sport;

            if ($sport !== null) {
                $link->sport = $sport instanceof Sport ? $sport->value : (string) $sport;
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
