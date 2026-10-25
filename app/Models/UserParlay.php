<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A parlay the customer says they placed, and its legs.
 *
 * Vigil holds no money and settles nothing with a sportsbook. This row is
 * a record of a claim, kept so the customer's own return has something to
 * measure - the same standing as UserBet, which it deliberately mirrors.
 *
 * A draft is not a wager. The spec is explicit that a saved parlay must
 * not be represented as real money until the user records it as placed,
 * and `status` is where that holds: a draft has no `placed_at` and never
 * reaches the bankroll.
 */
class UserParlay extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_paper' => 'boolean',
            'placed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(ParlayGrade::class, 'parlay_grade_id');
    }

    public function legs(): HasMany
    {
        return $this->hasMany(ParlayLeg::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ParlayEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function scopeSettled(Builder $query): Builder
    {
        // Void excluded for the reason UserBet excludes it: a parlay that
        // never ran is not a result, and counting it would dilute the
        // record with games nobody played.
        return $query->whereIn('status', ['won', 'lost', 'push']);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    /**
     * Real money only.
     *
     * A paper parlay belongs in the record of what the customer would
     * have done and nowhere near a bankroll figure, so every money
     * question asks for this first.
     */
    public function scopeRealMoney(Builder $query): Builder
    {
        return $query->where('is_paper', false);
    }

    /**
     * The price a payout may be worked out from.
     *
     * The accepted price, always, when the user gave us one - it is what
     * the sportsbook actually offered, and settling on our own computed
     * product instead would invent money. The computed price is a
     * fallback for a paper parlay nobody priced at a book.
     */
    public function payoutPriceAmerican(): ?int
    {
        return $this->accepted_price_american ?? $this->computed_price_american;
    }

    /** Profit or loss in cents. Null while unsettled. */
    public function profitCents(): ?int
    {
        return $this->return_cents === null
            ? null
            : $this->return_cents - (int) $this->stake_cents;
    }
}
