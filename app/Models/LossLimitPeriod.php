<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LimitPeriod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A self-imposed limit window. Once locked_at is set it stays set for the
 * rest of the period. Nothing in the app may clear it early.
 */
class LossLimitPeriod extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_type' => LimitPeriod::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'limit_cents' => 'integer',
            'realized_pnl_cents' => 'integer',
            'staked_cents' => 'integer',
            'exposure_cents' => 'integer',
            'locked_at' => 'datetime',
        ];
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function remainingCents(): int
    {
        return max(0, $this->limit_cents + min(0, $this->realized_pnl_cents));
    }
}
