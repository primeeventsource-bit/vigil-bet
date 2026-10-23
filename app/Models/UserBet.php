<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bet a customer says they placed. Derived from user_bet_events.
 *
 * Vigil holds no money and settles nothing. This row is a record of a
 * claim, kept so the customer's own ROI and closing line value have
 * something to measure.
 */
class UserBet extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'line' => 'float',
            'clv' => 'float',
            'placed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function boardEntry(): BelongsTo
    {
        return $this->belongsTo(VlBoardEntry::class, 'board_entry_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(UserBetEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function scopeSettled(Builder $query): Builder
    {
        // Void is excluded on purpose: a bet that never ran is not a
        // result, and counting it would dilute the record with games
        // nobody played.
        return $query->whereIn('status', ['won', 'lost', 'push']);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /** Profit or loss in cents. Null while the bet is unsettled. */
    public function profitCents(): ?int
    {
        if ($this->return_cents === null) {
            return null;
        }

        return $this->return_cents - (int) $this->stake_cents;
    }

    /**
     * What this bet would return if it won, stake included.
     *
     * American odds, which are two different formulas either side of
     * zero and are got wrong roughly half the time by code that forgets
     * that.
     */
    public function potentialReturnCents(): int
    {
        $stake = (int) $this->stake_cents;
        $price = (int) $this->price_american;

        return $price > 0
            ? $stake + (int) round($stake * $price / 100)
            : $stake + (int) round($stake * 100 / abs($price));
    }

    /** The implied probability of the price taken, with the vig in it. */
    public function impliedProbability(): float
    {
        $price = (int) $this->price_american;

        return $price > 0
            ? 100 / ($price + 100)
            : abs($price) / (abs($price) + 100);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, ['won', 'lost', 'push', 'void'], true);
    }
}
