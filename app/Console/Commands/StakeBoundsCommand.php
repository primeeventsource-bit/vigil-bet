<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BankrollLedgerEntry;
use App\Models\BankrollSetting;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Changes the stake bounds from a terminal, the way the Bankroll page does
 * from a browser.
 *
 * The page is the normal route. This exists because production is reached
 * by running a command, not by clicking, and a settings change there
 * should go through something reviewable and tested rather than a one-off
 * expression typed into a remote shell.
 *
 * Appends, never edits: bankroll_settings is append-only and versioned, so
 * a stake sized last month stays explainable by the numbers that sized it.
 * Anything not passed is carried forward, so changing a ceiling cannot
 * silently reset the loss limits.
 */
class StakeBoundsCommand extends Command
{
    protected $signature = 'edge:bankroll:stakes
                            {--min= : Smallest bet, in dollars}
                            {--max= : Largest bet, in dollars, or "off" for no ceiling}
                            {--pct= : Cap as a percentage of bankroll, or "off" for none}
                            {--force : Skip the confirmation}';

    protected $description = 'Change the per-bet stake bounds, appending a new settings version';

    public function handle(): int
    {
        $settings = BankrollSetting::current();

        if ($settings === null) {
            $this->error('No bankroll settings saved. Run php artisan db:seed first.');

            return self::FAILURE;
        }

        $changes = [];

        if ($this->option('min') !== null) {
            $changes['min_stake_cents'] = Money::fromDollars((float) $this->option('min'));
        }

        if ($this->option('max') !== null) {
            $changes['max_stake_cents'] = $this->isOff($this->option('max'))
                ? null
                : Money::fromDollars((float) $this->option('max'));
        }

        if ($this->option('pct') !== null) {
            // Off, not zero. A cap of zero would mean no bet may ever be
            // placed, which is the opposite of what is being asked for.
            $changes['max_stake_pct'] = $this->isOff($this->option('pct'))
                ? null
                : (float) $this->option('pct');
        }

        if ($changes === []) {
            $this->error('Nothing to change. Pass at least one of --min, --max or --pct.');

            return self::FAILURE;
        }

        $min = $changes['min_stake_cents'] ?? (int) $settings->min_stake_cents;
        $max = array_key_exists('max_stake_cents', $changes)
            ? $changes['max_stake_cents']
            : $settings->max_stake_cents;
        $pct = array_key_exists('max_stake_pct', $changes)
            ? $changes['max_stake_pct']
            : ($settings->hasPercentageCap() ? (float) $settings->max_stake_pct : null);

        // A floor above the ceiling is a range nothing can fall inside:
        // every stake gets rejected for being under the minimum and the
        // board empties with no explanation anywhere.
        if ($max !== null && $max < $min) {
            $this->error(sprintf(
                'The largest bet (%s) is below the smallest (%s), so no stake could fit between them.',
                Money::format((int) $max),
                Money::format($min),
            ));

            return self::FAILURE;
        }

        $bankroll = BankrollLedgerEntry::currentBalanceCents() ?: (int) $settings->starting_bankroll_cents;

        $this->line('<comment>Now</comment>');
        $this->describe($settings->min_stake_cents, $settings->max_stake_cents, $settings->hasPercentageCap() ? (float) $settings->max_stake_pct : null, $bankroll);
        $this->newLine();
        $this->line('<comment>After</comment>');
        $this->describe($min, $max, $pct, $bankroll);

        // Said plainly, because it is the part that is easy to miss: taking
        // the per-bet ceilings off does not take every limit off.
        if ($max === null && $pct === null) {
            $this->newLine();
            $this->line(sprintf(
                '<fg=yellow>No per-bet ceiling at all. A stake will be fractional Kelly, or whatever the %s%% daily exposure cap has left - %s on this bankroll.</>',
                rtrim(rtrim(number_format((float) $settings->max_daily_exposure_pct, 2), '0'), '.'),
                Money::format(Money::pctOf($bankroll, (float) $settings->max_daily_exposure_pct)),
            ));
        }

        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Save this as a new settings version?', false)) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        $new = $settings->revise($changes + [
            'note' => sprintf(
                'Stake bounds changed from the console: %s to %s, percentage cap %s.',
                Money::format($min),
                $max === null ? 'no ceiling' : Money::format((int) $max),
                $pct === null ? 'off' : number_format($pct, 2).'% of bankroll',
            ),
        ]);

        $this->info(sprintf('Saved as settings version %d, effective %s.', $new->id, $new->effective_from->toDateTimeString()));
        $this->line(sprintf('Version %d stays on the record, so stakes it sized can still be explained.', $settings->id));

        return self::SUCCESS;
    }

    private function describe(int $min, ?int $max, ?float $pct, int $bankroll): void
    {
        $this->line('  smallest bet   '.Money::format($min));
        $this->line('  largest bet    '.($max === null ? 'no ceiling' : Money::format($max)));
        $this->line('  percentage cap '.($pct === null
            ? 'off'
            : sprintf('%s%% of bankroll - %s a bet', number_format($pct, 2), Money::format(Money::pctOf($bankroll, $pct)))));
    }

    /** "off", "none" and "" all mean the same thing to a person typing it. */
    private function isOff(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['off', 'none', 'null', ''], true);
    }
}
