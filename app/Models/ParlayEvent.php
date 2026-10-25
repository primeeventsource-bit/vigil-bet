<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Everything that ever happened to a parlay, in order.
 *
 * Append-only, enforced in the database rather than here, for the reason
 * `user_bet_events` is: a settlement that can be rewritten is not a
 * record of anything. A correction is a new row saying what changed and
 * who changed it, so the original stays readable beside it.
 */
class ParlayEvent extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function parlay(): BelongsTo
    {
        return $this->belongsTo(UserParlay::class, 'user_parlay_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
