<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer's staking rules.
 *
 * The pending-limits pair is the part that matters. §8 asks for loss
 * limits "with no override", so a limit can be tightened immediately and
 * loosened only from tomorrow. A limit a customer can lift in the moment
 * is not a limit; it is a dialog that appears at the worst possible time
 * and asks somebody who is losing whether they would like to continue.
 */
class BankrollSettings extends Model
{
    use HasFactory;

    protected $table = 'bankroll_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kelly_scale' => 'float',
            'max_stake_pct' => 'float',
            'pending_limits' => 'array',
            'pending_limits_effective_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether a loosening is waiting for its date. */
    public function hasPendingChange(): bool
    {
        return $this->pending_limits !== null
            && $this->pending_limits_effective_on !== null
            && $this->pending_limits_effective_on->isFuture();
    }
}
