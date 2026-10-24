<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modeling\Odds\OddsConverter;
use App\Models\BankrollLedgerEntry;
use App\Models\BankrollSetting;
use App\Services\Engine\Bankroll\StakeSizer;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Shows what the current settings would actually stake, before a single bet
 * is placed.
 *
 * Staking rules are easy to agree to in the abstract and surprising in
 * dollars. This prints the ladder: at each size of edge, what goes on the
 * bet, and which rule decided the number.
 */
class StakePreviewCommand extends Command
{
    protected $signature = 'edge:stake:preview
                            {--bankroll= : Try a different bankroll, in dollars}
                            {--price=-110 : American price to size against}';

    protected $description = 'Preview the stake for each size of edge under the current bankroll settings';

    /** Edges worth showing: 1% is noise, 10% almost never survives contact with a closing line. */
    private const EDGES = [0.01, 0.02, 0.03, 0.04, 0.05, 0.07, 0.10];

    public function handle(StakeSizer $sizer): int
    {
        $settings = BankrollSetting::current();

        if ($settings === null) {
            $this->error('No bankroll settings saved. Run php artisan db:seed first.');

            return self::FAILURE;
        }

        $bankroll = $this->option('bankroll') !== null
            ? Money::fromDollars((float) $this->option('bankroll'))
            : (BankrollLedgerEntry::currentBalanceCents() ?: $settings->starting_bankroll_cents);

        $price = (int) $this->option('price');

        if (! OddsConverter::isValidAmerican($price)) {
            $this->error("Invalid American price: {$price}.");

            return self::FAILURE;
        }

        $marketProb = OddsConverter::americanToProbability($price);

        $this->components->info(sprintf(
            'Stake ladder at %s, bankroll %s',
            OddsConverter::formatAmerican($price),
            Money::format($bankroll),
        ));

        $rows = [];

        foreach (self::EDGES as $edge) {
            $modelProb = $marketProb + $edge;

            if ($modelProb >= 1.0) {
                continue;
            }

            $decision = $sizer->size($modelProb, $price, $bankroll, $settings);

            $rows[] = [
                number_format($edge * 100, 0).'%',
                number_format($modelProb * 100, 1).'%',
                $decision->isActionable ? Money::format($decision->stakeCents) : '-',
                $decision->isActionable ? number_format($decision->units, 2).'u' : '-',
                $decision->isActionable
                    ? ($decision->cappedBy ?? 'Kelly')
                    : ($decision->passReason?->label() ?? 'pass'),
            ];
        }

        $this->table(['edge', 'model says', 'stake', 'units', 'decided by'], $rows);

        $this->line('<comment>What this bankroll supports</comment>');
        $this->line(sprintf('  min bet %s, max bet %s',
            Money::format($settings->min_stake_cents),
            $settings->max_stake_cents ? Money::format($settings->max_stake_cents) : 'no ceiling',
        ));
        // With no dollar ceiling there is no "largest bet" to express as a
        // share of bankroll, and reporting 0% would read as a cap of
        // nothing rather than the absence of one.
        if ($settings->max_stake_cents) {
            $this->line(sprintf('  max bet is %s%% of bankroll - %s',
                $settings->impliedMaxStakePct($bankroll),
                $settings->riskBand($bankroll),
            ));
        } else {
            $this->line(sprintf('  no dollar ceiling, so the largest bet is whatever %s allows',
                $settings->hasPercentageCap()
                    ? number_format((float) $settings->max_stake_pct, 2).'% of bankroll'
                    : 'fractional Kelly and the daily exposure cap',
            ));
        }

        // Only meaningful against a dollar ceiling. With none, every row
        // of this table is $0.00, which reads as an answer rather than as
        // a question that does not apply.
        if ($settings->max_stake_cents) {
            $this->newLine();
            $this->line('<comment>Bankroll that would put your max bet on a given share</comment>');

            foreach ([2.0 => 'conservative', 5.0 => 'aggressive', 10.0 => 'very aggressive'] as $pct => $label) {
                $this->line(sprintf(
                    '  %-5s of bankroll  %12s  (%s)',
                    $pct.'%',
                    Money::format($settings->bankrollNeededForPct($pct)),
                    $label,
                ));
            }
        }

        $this->newLine();
        $this->line('Change the stake bounds on the Bankroll page, or with');
        $this->line('php artisan edge:bankroll:stakes. bankroll_settings is versioned, so a');
        $this->line('change is a new row and the stakes this one sized stay explainable.');

        return self::SUCCESS;
    }
}
