<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BetEventType;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND ONLY. The ledger of truth for a bet. A mistake is corrected by
 * appending a correction event, never by editing history.
 */
class BetEvent extends Model
{
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => BetEventType::class,
            'payload' => 'array',
            'sequence' => 'integer',
            'stake_delta_cents' => 'integer',
            'pnl_delta_cents' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function bet(): BelongsTo
    {
        return $this->belongsTo(Bet::class);
    }
}
