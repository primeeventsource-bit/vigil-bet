<?php

declare(strict_types=1);

namespace App\Enums;

enum LedgerEntryType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case BetStake = 'bet_stake';
    case BetPayout = 'bet_payout';
    case Adjustment = 'adjustment';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $t) => $t->value, self::cases());
    }
}
