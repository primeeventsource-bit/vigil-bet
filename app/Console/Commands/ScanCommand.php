<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PassReason;
use App\Enums\Recommendation;
use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Value\ScanValue;
use App\Models\ValueBet;
use App\Services\Value\ValueScanner;
use Illuminate\Console\Command;

/**
 * Sweeps the board and prints what it found.
 *
 * The passes are printed too, grouped by reason. A value finder that only
 * shows what it liked cannot be argued with, and the most useful line on
 * the board is often the bet that nearly qualified.
 */
class ScanCommand extends Command
{
    protected $signature = 'edge:scan
                            {sport=all : Sport key, or "all"}
                            {--hours=72 : How far ahead to scan}
                            {--sync}';

    protected $description = 'Compare the model against the board and flag value';

    public function handle(ValueScanner $scanner): int
    {
        $sport = mb_strtolower((string) $this->argument('sport'));
        $only = $sport === 'all' ? null : Sport::tryFrom($sport);

        if ($only === null && $sport !== 'all') {
            $this->error('Unknown sport. Known: '.implode(', ', Sport::values()).', all');

            return self::FAILURE;
        }

        $hours = (int) $this->option('hours');

        if (! $this->option('sync')) {
            dispatch(new ScanValue($only, $hours, TriggerSource::Manual));
            $this->line('Queued.');

            return self::SUCCESS;
        }

        $scan = $scanner->scan($only, $hours);

        $this->newLine();
        $this->line(sprintf('<comment>Scan %d</comment> · %d candidates · %d picks · bankroll %s',
            $scan->id,
            $scan->candidates,
            $scan->picks,
            '$'.number_format($scan->bankroll_cents / 100, 2),
        ));

        if ($scan->candidates === 0) {
            $this->newLine();
            $this->line('  Nothing to scan. Predictions are written by edge:predict, and need odds to compare against.');

            return self::SUCCESS;
        }

        $this->picks($scan->id);
        $this->passes($scan->id);

        return self::SUCCESS;
    }

    private function picks(int $scanId): void
    {
        $picks = ValueBet::query()
            ->where('value_scan_id', $scanId)
            ->where('recommendation', Recommendation::Bet)
            ->with(['event.homeTeam', 'event.awayTeam'])
            ->orderBy('probability_rank')
            ->get();

        if ($picks->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('<options=bold>Picks</>');

        foreach ($picks as $pick) {
            $this->line(sprintf('  %d. %s — %s', $pick->probability_rank, $pick->selectionLabel(), $pick->reason));
        }
    }

    private function passes(int $scanId): void
    {
        $passes = ValueBet::query()
            ->where('value_scan_id', $scanId)
            ->where('recommendation', Recommendation::Pass)
            ->get()
            ->groupBy(fn (ValueBet $bet) => $bet->pass_reason?->value ?? 'unknown');

        if ($passes->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('<options=bold>Passes</>');

        foreach ($passes as $reason => $group) {
            $label = PassReason::tryFrom((string) $reason)?->label() ?? 'Unknown';

            $this->line(sprintf('  %-34s %d', $label, $group->count()));
        }
    }
}
