<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Value\ScanCrossBook;
use App\Modeling\Odds\OddsConverter;
use App\Models\ArbitrageOpportunity;
use App\Models\MiddleOpportunity;
use App\Services\Value\CrossBookScanner;
use Illuminate\Console\Command;

/**
 * Sweeps the board for arbitrages and middles and prints what it found.
 *
 * The printout leads with the two facts that decide whether a row is worth
 * anything - whether both legs are reachable, and how old the older quote
 * was - because a list of profit percentages sorted downwards is a list of
 * the stalest quotes in the sample.
 */
class CrossBookCommand extends Command
{
    protected $signature = 'edge:crossbook
                            {sport=all : Sport key, or "all"}
                            {--hours= : How far ahead to look}
                            {--sync}';

    protected $description = 'Look across books for arbitrages and middles';

    public function handle(CrossBookScanner $scanner): int
    {
        $sport = mb_strtolower((string) $this->argument('sport'));
        $only = $sport === 'all' ? null : Sport::tryFrom($sport);

        if ($only === null && $sport !== 'all') {
            $this->error('Unknown sport. Known: '.implode(', ', Sport::values()).', all');

            return self::FAILURE;
        }

        $hours = $this->option('hours') === null
            ? (int) config('edge.crossbook.horizon_hours')
            : (int) $this->option('hours');

        if (! $this->option('sync')) {
            dispatch(new ScanCrossBook($only, $hours, TriggerSource::Manual));
            $this->line('Queued.');

            return self::SUCCESS;
        }

        $result = $scanner->scan($only, $hours);

        $this->newLine();
        $this->line(sprintf(
            '<comment>Cross-book sweep</comment> · %d arbitrage%s · %d middle%s',
            $result->arbitrages,
            $result->arbitrages === 1 ? '' : 's',
            $result->middles,
            $result->middles === 1 ? '' : 's',
        ));

        if ($result->found() === 0) {
            $this->newLine();
            $this->line('  Nothing across books. This is the normal answer.');

            return self::SUCCESS;
        }

        $this->arbitrages($result->scannedAt->toIso8601String());
        $this->middles($result->scannedAt->toIso8601String());

        return self::SUCCESS;
    }

    private function arbitrages(string $scannedAt): void
    {
        $rows = ArbitrageOpportunity::query()
            ->where('detected_at', $scannedAt)
            ->with(['event.homeTeam:id,display_name', 'event.awayTeam:id,display_name'])
            ->orderByDesc('profit_pct')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('<options=bold>Arbitrage</>');

        foreach ($rows as $row) {
            $this->line(sprintf(
                '  %-34s %-9s %+6.2f%%  %-14s %s',
                $this->gameLabel($row),
                $row->market->label(),
                $row->profit_pct * 100,
                $row->placeable ? 'placeable' : 'NOT PLACEABLE',
                $this->freshness($row->oldest_quote_seconds),
            ));

            foreach ($row->legs as $leg) {
                $this->line(sprintf(
                    '      %-12s %-22s %s',
                    $leg['side'] ?? '',
                    $leg['book'],
                    OddsConverter::formatAmerican((int) $leg['price_american']),
                ));
            }
        }
    }

    private function middles(string $scannedAt): void
    {
        $rows = MiddleOpportunity::query()
            ->where('detected_at', $scannedAt)
            ->with(['event.homeTeam:id,display_name', 'event.awayTeam:id,display_name'])
            ->orderByDesc('outs')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('<options=bold>Middles</>');
        $this->line('  <fg=gray>Needs is the hit rate that breaks even. Board is what the prices imply,</>');
        $this->line('  <fg=gray>and it is below Needs every time - only a model can beat it.</>');

        foreach ($rows as $row) {
            $this->line(sprintf(
                '  %-34s %-7s %5.1f-%-5.1f %d out%s  needs %4.1f%%  board %4.1f%%  model %s  %s',
                $this->gameLabel($row),
                $row->market->label(),
                $row->middle_low,
                $row->middle_high,
                $row->outs,
                $row->outs === 1 ? ' ' : 's',
                $row->break_even_hit_prob * 100,
                ($row->market_hit_prob ?? 0) * 100,
                $row->model_hit_prob === null ? '  -  ' : sprintf('%4.1f%%', $row->model_hit_prob * 100),
                $row->placeable ? '' : 'NOT PLACEABLE',
            ));
        }
    }

    private function gameLabel(ArbitrageOpportunity|MiddleOpportunity $row): string
    {
        $event = $row->event;

        if ($event === null) {
            return 'unknown game';
        }

        return mb_substr(
            ($event->awayTeam?->display_name ?? '?').' @ '.($event->homeTeam?->display_name ?? '?'),
            0,
            34,
        );
    }

    private function freshness(int $seconds): string
    {
        return $seconds < 60
            ? sprintf('%ds old', $seconds)
            : sprintf('%dm old', (int) round($seconds / 60));
    }
}
