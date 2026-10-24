<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Odds\BackfillHistoricalOdds;
use App\Services\Odds\HistoricalOddsBudget;
use App\Services\Odds\TheOddsApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Buys closing lines for seasons that happened before this app did.
 *
 * NBA, MLB, NHL, NCAAF and NCAAB have no free line history, which is why
 * their markets honestly report "not enough data yet" and would keep doing
 * so for months on forward-only collection. NFL is covered free by the
 * nflverse import and is not in the default set here.
 *
 * This is the one command in the app that can spend a serious share of a
 * monthly quota in a single run, so it says what it will cost before it
 * does anything, and refuses outright when that is more than the
 * configured share of what is left.
 */
class BackfillHistoricalOddsCommand extends Command
{
    /** Sports with no free line history. NFL has nflverse. */
    private const DEFAULT_SPORTS = ['nba', 'mlb', 'nhl', 'ncaaf', 'ncaab'];

    protected $signature = 'edge:odds:backfill
                            {sport : Sport key, or "all" for the sports with no free line history}
                            {--from= : Start of the window, any parseable date. Defaults to one year ago}
                            {--to= : End of the window. Defaults to yesterday}
                            {--markets= : Comma separated markets. Defaults to the markets of the sport}
                            {--estimate : Price the backfill and stop, without queueing anything}
                            {--sync : Run inline instead of queueing, for local debugging}';

    protected $description = 'Backfill historical closing lines from The Odds API, with a budget guard';

    public function handle(HistoricalOddsBudget $budget, TheOddsApiClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('ODDS_API_KEY is not set. Add it to .env first.');

            return self::FAILURE;
        }

        $sports = $this->resolveSports();

        if ($sports === []) {
            $this->error('Unknown sport. Known: '.implode(', ', Sport::values()).', all');

            return self::FAILURE;
        }

        $markets = $this->resolveMarkets();

        if ($markets === null) {
            $this->error('Unknown market. Known: '.implode(', ', Market::values()));

            return self::FAILURE;
        }

        [$from, $to] = $this->resolveWindow();

        if ($from->greaterThanOrEqualTo($to)) {
            $this->error('The window ends before it starts.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Window %s to %s, %s, %d region(s).',
            $from->toDateString(),
            $to->toDateString(),
            $markets === []
                ? 'the markets of each sport'
                : implode(', ', array_map(fn (Market $m) => $m->value, $markets)),
            count((array) config('edge.odds_api.regions')),
        ));
        $this->newLine();

        return $this->queueAll($sports, $markets, $from, $to, $budget);
    }

    /**
     * @param  array<int, Sport>  $sports
     * @param  array<int, Market>  $markets
     */
    private function queueAll(array $sports, array $markets, Carbon $from, Carbon $to, HistoricalOddsBudget $budget): int
    {
        $queued = 0;
        $spent = 0;
        $refused = 0;

        foreach ($sports as $sport) {
            $timestamps = $budget->timestampsFor($sport, $from, $to);
            $marketCount = $markets === [] ? count($sport->markets()) : count($markets);
            $verdict = $budget->verdict($sport, count($timestamps), $marketCount);

            $this->line(sprintf(
                '<comment>%s</comment> %d request(s) at %s credits each, about %s credits in all.',
                $sport->label(),
                count($timestamps),
                number_format($budget->creditsPerRequest($sport, $marketCount)),
                number_format($verdict['estimate']),
            ));

            if (! $verdict['allowed']) {
                $this->line('  <fg=red>Refused.</> '.$verdict['reason']);
                $refused++;

                continue;
            }

            $this->line(sprintf(
                '  <fg=gray>Allowed: the ceiling is %s credits, and %s are spendable this month.</>',
                number_format($verdict['ceiling']),
                number_format($verdict['spendable']),
            ));

            if ($this->option('estimate')) {
                continue;
            }

            foreach ($timestamps as $at) {
                $job = new BackfillHistoricalOdds(
                    $sport,
                    $at->toIso8601String(),
                    array_map(fn (Market $m) => $m->value, $markets),
                    TriggerSource::Manual,
                );

                $this->option('sync') ? dispatch_sync($job) : dispatch($job);
                $queued++;
            }

            $spent += $verdict['estimate'];
        }

        $this->newLine();

        if ($this->option('estimate')) {
            $this->info('Estimate only. Nothing was queued and nothing was spent.');

            return $refused > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($queued === 0) {
            $this->warn('Nothing queued.');

            return $refused > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d request(s), about %s credits.',
            $this->option('sync') ? 'Ran' : 'Queued',
            $queued,
            number_format($spent),
        ));

        if (! $this->option('sync')) {
            $this->line('Run a worker: php artisan queue:work');
        }

        $this->line('Then mark the closing lines these feed: php artisan edge:odds:close');

        return self::SUCCESS;
    }

    /** @return array<int, Sport> */
    private function resolveSports(): array
    {
        $argument = mb_strtolower((string) $this->argument('sport'));

        if ($argument === 'all') {
            return array_values(array_filter(
                Sport::enabled(),
                static fn (Sport $s) => in_array($s->value, self::DEFAULT_SPORTS, true),
            ));
        }

        $sport = Sport::tryFrom($argument);

        return $sport ? [$sport] : [];
    }

    /**
     * @return array<int, Market>|null null when a name is unknown, an empty
     *                                 array to mean "whatever the sport uses"
     */
    private function resolveMarkets(): ?array
    {
        $option = trim((string) ($this->option('markets') ?? ''));

        if ($option === '') {
            return [];
        }

        $markets = [];

        foreach (array_filter(array_map('trim', explode(',', $option))) as $name) {
            $market = Market::tryFrom(mb_strtolower($name));

            if ($market === null) {
                return null;
            }

            $markets[] = $market;
        }

        return $markets;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveWindow(): array
    {
        $from = $this->option('from')
            ? Carbon::parse((string) $this->option('from'))
            : now()->subYear();

        $to = $this->option('to')
            ? Carbon::parse((string) $this->option('to'))
            : now()->subDay();

        // Never the future. There is no history of a game that has not been
        // played, and asking costs the same ten credits as asking for
        // something real.
        return [$from->startOfDay(), $to->min(now()->subMinutes(5))];
    }
}
