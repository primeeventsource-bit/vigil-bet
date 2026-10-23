<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Derived subscription state. Rebuilt from billing_events at step 6.
 */
class Subscription extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The tier this subscription actually entitles the customer to right
     * now, which is not always the tier it names.
     *
     * Two cases, both of which §4 is explicit about and both of which are
     * easy to get wrong in the customer's disfavour:
     *
     *  - a cancelled subscription keeps its tier until the period it was
     *    paid for ends. Cancelling is not a punishment.
     *  - a lapsed or past-due one falls back to free rather than to
     *    nothing, because Free is a real tier with real features and
     *    locking somebody out of those is a different decision nobody
     *    made.
     */
    public function effectiveTier(): string
    {
        if ($this->status === 'cancelled' && ! $this->stillInPaidPeriod()) {
            return 'free';
        }

        if ($this->status === 'past_due' && ! $this->stillInPaidPeriod()) {
            return 'free';
        }

        return $this->tier;
    }

    public function stillInPaidPeriod(): bool
    {
        return $this->current_period_end !== null && $this->current_period_end->isFuture();
    }

    public function onTrial(): bool
    {
        return $this->status === 'trialing'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    /** What the customer should be told about where they stand. */
    public function describe(): string
    {
        $name = str_replace(':brand', (string) config('brand.name'), (string) config('plans.tiers.'.$this->tier.'.name', $this->tier));

        return match (true) {
            $this->onTrial() => $name.' trial, ends '.$this->trial_ends_at->toFormattedDateString(),
            $this->cancel_at_period_end && $this->stillInPaidPeriod() => $name.' until '.$this->current_period_end->toFormattedDateString(),
            $this->status === 'past_due' => $name.', payment overdue',
            default => $name,
        };
    }
}
