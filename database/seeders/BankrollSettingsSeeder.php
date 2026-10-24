<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LedgerEntryType;
use App\Models\BankrollLedgerEntry;
use App\Models\BankrollSetting;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * Writes the opening bankroll settings row and the matching opening ledger
 * entry. Both tables are append-only, so this runs once and later changes
 * are new rows.
 */
class BankrollSettingsSeeder extends Seeder
{
    public function run(): void
    {
        if (BankrollSetting::query()->exists()) {
            $this->command?->line('Bankroll settings already present; leaving them alone.');

            return;
        }

        $config = (array) config('edge.bankroll');

        $bankroll = (int) $config['starting_cents'];
        $maxStake = (int) $config['max_stake_cents'];

        // The stated stake range governs. If the percentage guard would stop
        // a bet I have already decided to make, the guard moves, and the
        // implied risk is reported rather than hidden behind a clamp.
        $pctCap = (float) $config['max_stake_pct'];

        if ($bankroll > 0 && $maxStake > 0) {
            $impliedPct = ($maxStake / $bankroll) * 100;
            $pctCap = max($pctCap, round($impliedPct, 2));
        }

        BankrollSetting::create([
            'starting_bankroll_cents' => (int) $config['starting_cents'],
            'unit_size_cents' => (int) $config['unit_size_cents'],
            'min_stake_cents' => (int) $config['min_stake_cents'],
            'max_stake_cents' => (int) $config['max_stake_cents'],
            'kelly_fraction' => (float) $config['kelly_fraction'],
            'max_stake_pct' => $pctCap,
            'max_daily_exposure_pct' => (float) $config['max_daily_exposure_pct'],
            'daily_loss_limit_cents' => (int) $config['daily_loss_limit_cents'],
            'weekly_loss_limit_cents' => (int) $config['weekly_loss_limit_cents'],
            'edge_threshold' => (float) config('edge.value.edge_threshold'),
            'min_win_probability' => (float) config('edge.value.min_win_probability'),
            'min_book_count' => (int) config('edge.value.min_book_count'),
            'require_proven_market' => (bool) config('edge.value.require_proven_market'),
            'effective_from' => now(),
            'note' => 'Seeded defaults.',
            'created_at' => now(),
        ]);

        if (! BankrollLedgerEntry::query()->exists()) {
            BankrollLedgerEntry::create([
                'entry_type' => LedgerEntryType::Deposit,
                'amount_cents' => (int) $config['starting_cents'],
                'balance_after_cents' => (int) $config['starting_cents'],
                'occurred_at' => now(),
                'note' => 'Opening bankroll.',
                'created_at' => now(),
            ]);
        }

        $settings = BankrollSetting::current();

        $this->command?->info(sprintf(
            'Bankroll settings seeded. Max bet %s is %s%% of a %s bankroll (%s).',
            Money::format($settings->max_stake_cents ?? 0),
            $settings->impliedMaxStakePct($bankroll),
            Money::format($bankroll),
            $settings->riskBand($bankroll),
        ));
    }
}
