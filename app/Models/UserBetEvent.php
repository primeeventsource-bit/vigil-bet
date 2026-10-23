<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** APPEND ONLY. One thing that happened to one bet. */
class UserBetEvent extends Model
{
    use AppendOnly, HasFactory;

    public const PLACED = 'bet.placed';

    public const SETTLED = 'bet.settled';

    public const VOIDED = 'bet.voided';

    public const CLV_RECORDED = 'bet.clv_recorded';

    public const CORRECTED = 'bet.corrected';

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

    public function bet(): BelongsTo
    {
        return $this->belongsTo(UserBet::class, 'user_bet_id');
    }

    /** Whether a person did this or the engine did. */
    public function byHand(): bool
    {
        return $this->actor_id !== null;
    }
}
