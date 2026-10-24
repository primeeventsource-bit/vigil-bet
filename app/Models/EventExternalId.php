<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Sport;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A game's id at one source, within one sport.
 *
 * The sport is part of the key, not decoration. Sources number games per
 * league, so a row is only unique once the sport is included; without it
 * the first league to sync claims the number and every later league
 * silently reads its event.
 */
class EventExternalId extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * Keep `sport` in step with the event it points at.
     *
     * The column is denormalised from the event purely so it can take part
     * in the unique index, which cannot span a join. Deriving it here
     * rather than at each call site means the two can never disagree, and
     * no caller has to remember a field that was never theirs to choose.
     */
    protected static function booted(): void
    {
        static::saving(function (self $link): void {
            if ($link->event_id === null) {
                return;
            }

            $sport = $link->event?->sport;

            if ($sport !== null) {
                $link->sport = $sport instanceof Sport ? $sport->value : (string) $sport;
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
