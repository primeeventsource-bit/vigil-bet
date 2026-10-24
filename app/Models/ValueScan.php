<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sweep of the board. Picks and passes are grouped under a scan so the
 * Today screen renders one consistent snapshot rather than a moving target.
 */
class ValueScan extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'pricing_book_id' => 'integer',
            'bankroll_cents' => 'integer',
            'candidates' => 'integer',
            'picks' => 'integer',
            'scanned_at' => 'datetime',
        ];
    }

    public function valueBets(): HasMany
    {
        return $this->hasMany(ValueBet::class);
    }
}
