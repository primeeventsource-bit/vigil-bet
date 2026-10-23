<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APPEND ONLY. One thing that happened to somebody's billing.
 *
 * The event names are ours, not any processor's, because they outlive the
 * processor. A driver translates its own vocabulary into these, and the
 * projector that derives subscription state only ever reads these.
 */
class BillingEvent extends Model
{
    use AppendOnly, HasFactory;

    public const SUBSCRIBED = 'subscription.started';

    public const RENEWED = 'subscription.renewed';

    public const PLAN_CHANGED = 'subscription.plan_changed';

    public const CANCEL_SCHEDULED = 'subscription.cancel_scheduled';

    public const RESUMED = 'subscription.resumed';

    public const ENDED = 'subscription.ended';

    public const PAYMENT_FAILED = 'payment.failed';

    public const PAYMENT_RECOVERED = 'payment.recovered';

    public const REFUNDED = 'payment.refunded';

    public const DOWNGRADED = 'subscription.downgraded';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'period_end' => 'datetime',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * History for one customer, oldest first.
     *
     * Ordered by `occurred_at` and then by id: two events can share a
     * second, and a projector that replayed them in the wrong order
     * would decide a customer who resubscribed had cancelled.
     */
    public function scopeTimeline(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId)
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    /** Whether this event is the processor's word or ours. */
    public function fromGateway(): bool
    {
        return $this->gateway_event_id !== null;
    }
}
