<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Sport;
use App\Services\Ingestion\PlayerGameStatsWriter;
use App\Services\Ingestion\ProvidesGameBoxScore;
use App\Services\Ingestion\ProvidesPlayerGameStats;
use App\Services\Ingestion\ProvidesTeamGameStats;
use App\Services\Ingestion\SourceRegistry;
use App\Services\Ingestion\TeamGameStatsWriter;
use App\Support\BatchMemory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pulls box scores - team lines, and player lines where a source has
 * them - for games that have finished.
 *
 * Replaces `ingest:team-stats`, which was named before player lines
 * existed. Two source shapes are served, because the sources genuinely
 * differ rather than because the abstraction leaked:
 *
 *  - Bulk. CFBD serves a week of college football team box scores in one
 *    request and has no player lines on that endpoint.
 *  - Per game. ESPN serves one game at a time and returns team and
 *    player lines together, which is why it is one request and not two.
 *
 * The per-game path walks our own finished events, because that is the
 * only way to know which game ids to ask for, and it asks only for games
 * whose box score is missing - so a second run costs almost nothing and
 * an interrupted backfill resumes where it stopped.
 */
class BoxScoresCommand extends Command
{
    protected $signature = 'ingest:box-scores
                            {sport=all : Sport key, or "all" for every source that can}
                            {--season= : Season to pull, defaults to the current one}
                            {--week= : One week only, for the bulk sources}
                            {--from-season= : Backfill from this season up to --season}
                            {--limit=400 : Most games to fetch in one run, for the per-game sources}
                            {--refresh : Re-fetch games that already have a box score}';

    protected $description = 'Pull team and player box scores for finished games';

    public function handle(
        SourceRegistry $sources,
        TeamGameStatsWriter $teamWriter,
        PlayerGameStatsWriter $playerWriter,
    ): int {
        /*
         * Raised before anything is fetched. This is the command that met
         * the platform's 256M ceiling: the per-game path holds a batch of
         * lines in memory, and `--limit=1200` died on it while the
         * container had 768M spare.
         */
        BatchMemory::raise();

        $sports = $this->sports();

        if ($sports === []) {
            $this->error('Unknown sport. Pass a sport key or "all".');

            return self::FAILURE;
        }

        $season = (int) ($this->option('season') ?? now()->year);
        $from = $this->option('from-season') === null ? $season : (int) $this->option('from-season');

        if ($from > $season) {
            $this->error('--from-season is later than --season.');

            return self::FAILURE;
        }

        $served = false;

        foreach ($sports as $sport) {
            /*
             * The team_stats role, not results. For the NFL those are two
             * different sources: nflverse has the schedule and the final
             * score, and nothing from inside the game.
             */
            $source = $sources->forRole($sport, 'team_stats');

            if ($source === null || ! $source->isAvailable()) {
                continue;
            }

            if ($source instanceof ProvidesGameBoxScore) {
                $served = true;
                $this->perGame($sport, $source, $teamWriter, $playerWriter);

                continue;
            }

            if ($source instanceof ProvidesTeamGameStats) {
                $served = true;

                for ($year = $from; $year <= $season; $year++) {
                    $this->bulk($sport, $source, $teamWriter, $year);

                    if ($source instanceof ProvidesPlayerGameStats) {
                        $this->bulkPlayers($sport, $source, $playerWriter, $year);
                    }
                }

                continue;
            }

            // Said out loud rather than skipped silently: a sport with no
            // box score source is a gap, and it should stay visible.
            $this->line("  {$sport->value}: {$source->key()} has no box scores");
        }

        return $served ? self::SUCCESS : self::FAILURE;
    }

    /**
     * One request per game, for our own finished events.
     *
     * Only games with no box score yet, unless --refresh. Without that
     * a second run would re-fetch thousands of games to write what is
     * already there, and an interrupted backfill would start over.
     */
    private function perGame(
        Sport $sport,
        ProvidesGameBoxScore $source,
        TeamGameStatsWriter $teamWriter,
        PlayerGameStatsWriter $playerWriter,
    ): void {
        $ids = $this->pendingGameIds($sport, $source->key());

        if ($ids === []) {
            $this->line("  {$sport->value}: nothing pending");

            return;
        }

        $totals = ['teams' => 0, 'players' => 0, 'games' => 0, 'failed' => 0, 'empty' => 0];

        foreach ($ids as $externalId) {
            try {
                $box = $source->gameBoxScore((string) $externalId);
            } catch (Throwable $e) {
                /*
                 * One game must not end the run. At a request per game a
                 * backfill meets abandoned fixtures, rate limits and the
                 * odd malformed payload, and losing the remaining
                 * thousands over any of those would be absurd.
                 */
                $totals['failed']++;

                continue;
            }

            if ($box->isEmpty()) {
                $totals['empty']++;

                continue;
            }

            $totals['teams'] += $teamWriter->write($box->teams)['written'];
            $totals['players'] += $playerWriter->write($box->players)['written'];
            $totals['games']++;
        }

        $this->line(sprintf(
            '  %s: %d games, %s team lines, %s player lines, %d empty, %d failed',
            $sport->value,
            $totals['games'],
            number_format($totals['teams']),
            number_format($totals['players']),
            $totals['empty'],
            $totals['failed'],
        ));
    }

    /**
     * The source's ids for finished games we have no box score for.
     *
     * @return array<int, string>
     */
    private function pendingGameIds(Sport $sport, string $source): array
    {
        $query = DB::table('event_external_ids as x')
            ->join('events as e', 'e.id', '=', 'x.event_id')
            ->where('x.source', $source)
            ->where('e.sport', $sport->value)
            ->where('e.status', 'final')
            ->orderByDesc('e.starts_at')
            ->limit((int) $this->option('limit'));

        if (! $this->option('refresh')) {
            $query->whereNotExists(
                fn ($sub) => $sub->selectRaw('1')
                    ->from('team_game_stats as t')
                    ->whereColumn('t.event_id', 'e.id')
            );
        }

        return $query->pluck('x.external_id')->all();
    }

    private function bulk(
        Sport $sport,
        ProvidesTeamGameStats $source,
        TeamGameStatsWriter $writer,
        int $season,
    ): void {
        $week = $this->option('week') === null ? null : (int) $this->option('week');
        $label = $sport->value.' '.$season.($week === null ? '' : " week {$week}");

        try {
            $counts = $writer->write($source->teamGameStats($season, $week));
        } catch (Throwable $e) {
            // One season failing must not cost the others in a backfill.
            $this->error("  {$label}: ".$e->getMessage());

            return;
        }

        $this->line(sprintf(
            '  %s: wrote %s, no event %d, unresolved team %d, empty %d',
            $label,
            number_format($counts['written']),
            $counts['no_event'],
            $counts['no_team'],
            $counts['empty'],
        ));
    }

    /**
     * Player lines for a season, from a source that serves them a week
     * at a time.
     *
     * Separate from bulk() rather than folded into it: the two read
     * different endpoints, and a season whose team lines arrive while
     * its player lines fail is a useful outcome rather than a reason to
     * discard both.
     */
    private function bulkPlayers(
        Sport $sport,
        ProvidesPlayerGameStats $source,
        PlayerGameStatsWriter $writer,
        int $season,
    ): void {
        $week = $this->option('week') === null ? null : (int) $this->option('week');
        $label = $sport->value.' '.$season.($week === null ? '' : " week {$week}");

        try {
            $counts = $writer->write($source->playerGameStats($season, $week));
        } catch (Throwable $e) {
            $this->error("  {$label} players: ".$e->getMessage());

            return;
        }

        $this->line(sprintf(
            '  %s players: wrote %s, no event %d, unresolved team %d, empty %d',
            $label,
            number_format($counts['written']),
            $counts['no_event'],
            $counts['no_team'],
            $counts['empty'],
        ));
    }

    /** @return array<int, Sport> */
    private function sports(): array
    {
        $argument = mb_strtolower((string) $this->argument('sport'));

        if ($argument === 'all') {
            return Sport::cases();
        }

        $sport = Sport::tryFrom($argument);

        return $sport === null ? [] : [$sport];
    }
}
