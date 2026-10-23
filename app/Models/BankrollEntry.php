<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND ONLY. One signed movement in a customer's bankroll, in cents.
 *
 * A stake is negative and a return is positive, so the balance is the sum
 * of the column. The ledger adds up by construction rather than by a
 * reconciliation routine somebody has to remember to run.
 */
class BankrollEntry extends Model
{
    use AppendOnly, HasFactory;

    public const DEPOSIT = 'deposit';

    public const WITHDRAWAL = 'withdrawal';

    public const STAKE = 'stake';

    public const RETURN_ = 'return';

    public const ADJUSTMENT = 'adjustment';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bet(): BelongsTo
    {
        return $this->belongsTo(UserBet::class, 'user_bet_id');
    }
}
