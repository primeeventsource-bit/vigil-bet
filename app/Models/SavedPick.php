<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A published opinion somebody wanted to keep an eye on.
 *
 * Not a bet. My Bets records money a customer says they staked; this
 * records interest, with nothing down. Keeping them apart is what stops
 * a watchlist reporting a profit nobody made.
 *
 * It holds no copy of the pick. Selection, price, confidence and result
 * all belong to the opinion and are read through the relation, so a
 * saved pick can never disagree with the public record it came from.
 */
class SavedPick extends Model
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function opinion(): BelongsTo
    {
        return $this->belongsTo(Opinion::class);
    }
}
