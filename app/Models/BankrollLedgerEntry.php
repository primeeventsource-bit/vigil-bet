<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerEntryType;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankrollLedgerEntry extends Model
{
    use AppendOnly, HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'bankroll_ledger';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entry_type' => LedgerEntryType::class,
            'amount_cents' => 'integer',
            'balance_after_cents' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public static function currentBalanceCents(): int
    {
        return (int) (static::query()->orderByDesc('occurred_at')->orderByDesc('id')->value('balance_after_cents') ?? 0);
    }
}
