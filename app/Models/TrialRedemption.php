<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * That an identity has had its turn.
 *
 * Append-only, and that is the entire point: a row removed is a second
 * trial granted silently, and the person most likely to remove one is an
 * administrator with a support ticket open and good intentions. There is
 * a proper way to make that exception - the admin trials page grants an
 * extra trial explicitly, writes a new row with `granted_by_operator`,
 * and leaves a reason in the audit log.
 *
 * Holds no address and no IP in the clear. Both are HMACs keyed on the
 * application key, so this table can answer "has this person had a
 * trial" and "how many came from one network this morning" without
 * being a list of who signed up or where from.
 */
class TrialRedemption extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
            'created_at' => 'datetime',
            'granted_by_operator' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Other trials from the same network, recently.
     *
     * The review signal, and nothing more. It never refuses anything -
     * a shared office, a university and a mobile carrier all look like
     * this, and so does one person with a VPN.
     */
    public function scopeFromSameNetwork(Builder $query, ?string $ipHash, int $hours = 24): Builder
    {
        return $query
            ->when($ipHash === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->where('ip_hash', $ipHash)
            ->where('redeemed_at', '>=', now()->subHours($hours));
    }
}
