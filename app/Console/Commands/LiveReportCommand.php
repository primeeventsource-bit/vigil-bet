<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Live\ProbeReport;
use Illuminate\Console\Command;

/**
 * What a probing session measured, and what it could not.
 *
 * The verdict at the end is deliberately blunt. A live scanner that acts on
 * a feed slower than the book is not a scanner with a small edge, it is one
 * with a negative edge dressed up as a signal, and the only moment to find
 * that out cheaply is before the model is written.
 */
class LiveReportCommand extends Command
{
    protected $signature = 'edge:live:report {session? : the session id, or the newest if omitted}';

    protected $description = 'Report what a live feed audit measured';

    public function handle(ProbeReport $reports): int
    {
        $sessions = $reports->sessions();

        if ($sessions === []) {
            $this->warn('No probing session has run. Start one with edge:live:probe.');

            return self::FAILURE;
        }

        $session = (string) ($this->argument('session') ?? $sessions[0]);
        $report = $reports->forSession($session);

        if ($report['empty'] ?? true) {
            $this->warn("Session {$session} has no readings.");

            return self::FAILURE;
        }

        $this->line('');
        $this->line("  <options=bold>Feed audit {$session}</>");
        $this->line(sprintf(
            '  %s to %s',
            $report['from']->format('j M H:i:s'),
            $report['to']->format('H:i:s'),
        ));

        $this->heading('Feeds');
        $this->table(
            ['Source', 'Polls', 'Readings', 'Failed', 'Games', 'Changes', 'HTTP median', 'Stamped'],
            array_map(fn (array $row) => [
                $row['source'],
                $row['polls'],
                $row['readings'],
                $row['failures'],
                $row['games_seen'],
                $row['material_changes'],
                $this->ms($row['http_ms_median']),
                $row['stamps_readings'] ? 'yes' : 'no',
            ], $report['sources']),
        );

        $this->heading('Staleness, where a source stamps its own readings');

        if ($report['absolute_staleness'] === []) {
            $this->line('  None of these feeds stamped a reading, so no absolute staleness exists');
            $this->line('  to report. Everything below is relative to whichever feed was fastest,');
            $this->line('  which is not the same thing and must not be read as if it were.');
        } else {
            $this->table(
                ['Source', 'Readings', 'Median', 'p90', 'Worst'],
                array_map(fn (array $row) => [
                    $row['source'],
                    $row['n'],
                    $this->ms($row['median_ms']),
                    $this->ms($row['p90_ms']),
                    $this->ms($row['worst_ms']),
                ], $report['absolute_staleness']),
            );
        }

        $lag = $report['relative_lag'];

        $this->heading('Lag between feeds on the same game');

        if ($lag['rows'] === []) {
            $this->line('  Nothing comparable: no game was seen changing by two feeds at once.');
            $this->line('  Either only one feed covers this sport, or no score changed while watching.');
        } else {
            $this->line("  Over {$lag['comparable_games']} games seen by more than one feed.");
            $this->line('');
            $this->table(
                ['Source', 'States', 'Median behind', 'p90 behind', 'First %'],
                array_map(fn (array $row) => [
                    $row['source'],
                    $row['n'],
                    $this->ms($row['median_ms']),
                    $this->ms($row['p90_ms']),
                    $row['first_pct'] === null ? '--' : $row['first_pct'].'%',
                ], $lag['rows']),
            );
        }

        $odds = $report['odds'];

        $this->heading('Prices');

        if (! ($odds['measured'] ?? false)) {
            $this->line('  No price was read, so the most important half of this audit did not run.');
            $this->line('  Without a live price there is nothing to compare a model against and no');
            $this->line('  way to know how fast a book reprices. Set ODDS_API_KEY and run again');
            $this->line('  during a game.');
        } else {
            $this->line(sprintf(
                '  %s quotes across %d games and %d books, %d price changes.',
                number_format($odds['quotes']),
                $odds['games'],
                $odds['books'],
                $odds['price_changes'],
            ));

            if ($odds['staleness_n'] > 0) {
                $this->line(sprintf(
                    '  The book stated its price was already %s old on arrival (p90 %s), over %s quotes.',
                    $this->ms($odds['staleness_median_ms']),
                    $this->ms($odds['staleness_p90_ms']),
                    number_format($odds['staleness_n']),
                ));
            }
        }

        $response = $report['price_response'];

        $this->heading('How long after a score did a price move');

        if (! ($response['measured'] ?? false)) {
            $this->line(sprintf(
                '  Not measurable from this session: %d score changes and %d price changes seen.',
                $response['score_changes'],
                $response['price_changes'],
            ));
            $this->line('  Both have to happen, on the same game, while watching.');
        } else {
            $this->line(sprintf(
                '  Median %s, p90 %s, over %d score changes.',
                $this->ms($response['median_ms']),
                $this->ms($response['p90_ms']),
                $response['n'],
            ));
            $this->line('');
            $this->line('  Read this against your own lag above. The window to act in is the gap');
            $this->line('  between seeing the score and the book moving, minus how long a bet');
            $this->line('  takes to place. If that is negative, the price you would be taking has');
            $this->line('  already priced the play you are reacting to.');
        }

        $this->heading('What this does not tell you');
        $this->line('  Nothing here measures the book\'s own feed. A price that has not moved may');
        $this->line('  be a book that is slower than you, or a book that saw the play, priced it,');
        $this->line('  and left the number where it was. Only a tracked bet at a taken price,');
        $this->line('  graded against the close, separates those two.');
        $this->line('');

        return self::SUCCESS;
    }

    private function heading(string $text): void
    {
        $this->line('');
        $this->line("  <options=bold>{$text}</>");
        $this->line('');
    }

    private function ms(?int $value): string
    {
        if ($value === null) {
            return '--';
        }

        return $value < 1000
            ? $value.' ms'
            : number_format($value / 1000, 1).' s';
    }
}
