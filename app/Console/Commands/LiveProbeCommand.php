<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Services\Live\FeedProbe;
use App\Services\Live\LiveSourceRegistry;
use App\Services\Live\LiveStateSource;
use App\Services\Odds\TheOddsApiClient;
use App\Services\Ops\JobRunRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Phase 0 of the live engine: watch a real game and write down what the
 * feeds actually said, and when.
 *
 * Run this during a game you can also watch. It builds nothing and
 * recommends nothing - it exists to produce the numbers that decide whether
 * a last-minute scanner is worth building on the feeds available, before
 * any model is written against the assumption that it is.
 *
 * It runs in the foreground on purpose. This is an instrument, pointed at
 * one game for half an hour, not a scheduled job.
 */
class LiveProbeCommand extends Command
{
    protected $signature = 'edge:live:probe
        {sport : nfl, nba, mlb, nhl, ncaaf or ncaab}
        {--minutes=20 : how long to watch}
        {--every=5 : seconds between game-state reads}
        {--odds-every=60 : seconds between odds reads, which cost API quota}
        {--no-odds : watch the score feeds only}';

    protected $description = 'Measure how fast live score and odds feeds actually are';

    public function handle(FeedProbe $probe, LiveSourceRegistry $registry, JobRunRecorder $recorder): int
    {
        $sport = Sport::tryFrom((string) $this->argument('sport'));

        if ($sport === null) {
            $this->error('Unknown sport. One of: '.implode(', ', Sport::values()));

            return self::FAILURE;
        }

        $sources = $registry->for($sport);

        if ($sources === []) {
            $this->error("No live source answers for {$sport->label()}.");

            return self::FAILURE;
        }

        $minutes = max(1, (int) $this->option('minutes'));
        $every = max(1, (int) $this->option('every'));
        $oddsEvery = max(5, (int) $this->option('odds-every'));
        $withOdds = ! $this->option('no-odds');

        $this->intro($sport, $sources, $minutes, $every, $oddsEvery, $withOdds);

        if (! $this->confirmBudget($withOdds, $minutes, $oddsEvery)) {
            return self::FAILURE;
        }

        $session = (string) Str::ulid();
        $run = $recorder->start(
            jobKey: 'live:probe',
            jobClass: self::class,
            sport: $sport,
            triggeredBy: TriggerSource::Manual,
            context: ['session' => $session, 'minutes' => $minutes, 'every' => $every],
        );

        $deadline = now()->addMinutes($minutes);
        $stateDigests = [];
        $oddsDigests = [];
        $nextOddsAt = $withOdds ? now() : null;
        $ticks = 0;
        $probes = 0;
        $changes = 0;

        $this->line('');
        $this->line('  Watching. Ctrl-C stops early and keeps everything written so far.');
        $this->line('');

        while (now()->lessThan($deadline)) {
            $tickStarted = microtime(true);

            $result = $probe->sweepGameState($sport, $session, $stateDigests, $run->id);
            $stateDigests = $result['digests'];
            $probes += $result['probes'];
            $changes += $result['changes'];

            $oddsNote = '';

            if ($nextOddsAt !== null && now()->greaterThanOrEqualTo($nextOddsAt)) {
                $oddsResult = $probe->sweepOdds($sport, $session, $oddsDigests, $run->id);
                $nextOddsAt = now()->addSeconds($oddsEvery);

                if (($oddsResult['available'] ?? false) === false) {
                    $oddsNote = '  odds: '.$oddsResult['reason'];
                    // Saying it once is enough; it will not become true.
                    $nextOddsAt = null;
                } elseif (($oddsResult['ok'] ?? false) === true) {
                    $oddsDigests = $oddsResult['digests'];
                    $probes += $oddsResult['probes'];
                    $changes += $oddsResult['changes'];
                    $oddsNote = sprintf('  odds: %d in play, %d quotes', $oddsResult['in_play'], $oddsResult['probes']);
                } else {
                    $oddsNote = '  odds: failed, '.Str::limit((string) ($oddsResult['error'] ?? ''), 60);
                }
            }

            $ticks++;

            $this->line(sprintf(
                '  %s  %d live  %d changes  %d rows%s',
                now()->format('H:i:s'),
                $result['live'],
                $result['changes'],
                $result['probes'],
                $oddsNote,
            ));

            // Stop when the next tick would land at or past the deadline,
            // rather than sleeping out the last interval for a reading that
            // is never taken.
            if (now()->addSeconds($every)->greaterThanOrEqualTo($deadline)) {
                break;
            }

            $elapsed = microtime(true) - $tickStarted;

            usleep((int) round(max(0, $every - $elapsed) * 1_000_000));
        }

        $recorder->succeed($run, $ticks, $probes, [
            'session' => $session,
            'probes' => $probes,
            'material_changes' => $changes,
        ]);

        $this->line('');
        $this->info("  Session {$session}: {$probes} readings over {$ticks} ticks, {$changes} material changes.");
        $this->line("  Read it with:  php artisan edge:live:report {$session}");

        return self::SUCCESS;
    }

    /** @param  array<int, LiveStateSource>  $sources */
    private function intro(Sport $sport, array $sources, int $minutes, int $every, int $oddsEvery, bool $withOdds): void
    {
        $this->line('');
        $this->line("  <options=bold>Feed audit, {$sport->label()}</>");
        $this->line('');

        foreach ($sources as $source) {
            $this->line(sprintf(
                '  %-14s %-18s %s',
                $source->key(),
                $source->reliability()->value,
                $source->stampsItsOwnReadings()
                    ? 'stamps its readings'
                    : 'no timestamp - lag measurable only against another feed',
            ));
        }

        if (count($sources) < 2) {
            $this->line('');
            $this->warn('  Only one state feed answers for this sport, and none of the others can');
            $this->warn('  check it. Its lag can only be measured where the odds feed stamps a price.');
        }

        $this->line('');
        $this->line("  {$minutes} minutes, a score read every {$every}s"
            .($withOdds ? ", odds every {$oddsEvery}s" : ', no odds'));
    }

    /**
     * An odds read costs quota. Saying what it will cost before spending it
     * is the same courtesy the scheduler already extends.
     */
    private function confirmBudget(bool $withOdds, int $minutes, int $oddsEvery): bool
    {
        if (! $withOdds) {
            return true;
        }

        $client = app(TheOddsApiClient::class);

        if (! $client->isConfigured()) {
            $this->line('');
            $this->warn('  No ODDS_API_KEY, so prices cannot be read. The score feeds will still be');
            $this->warn('  measured, but the number that matters most - how fast a book reprices');
            $this->warn('  after a score - needs a key.');

            return true;
        }

        $calls = (int) ceil(($minutes * 60) / $oddsEvery);

        $this->line('');
        $this->line("  This will spend about {$calls} Odds API requests.");

        return ! $this->input->isInteractive() || $this->confirm('  Go ahead?', true);
    }
}
