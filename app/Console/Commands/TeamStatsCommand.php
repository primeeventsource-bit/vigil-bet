<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Sport;
use App\Services\Ingestion\ProvidesTeamGameStats;
use App\Services\Ingestion\SourceRegistry;
use App\Services\Ingestion\SportDataSource;
use App\Services\Ingestion\TeamGameStatsWriter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pulls box scores for finished games.
 *
 * `team_game_stats` has had a table and a model since the schema was
 * written and has never had a row in it, so every rating in the app is
 * built on results alone: who won and by how much. A team that wins
 * three games on turnovers and a team that wins three by four hundred
 * yards are the same team to it. This is what closes that.
 *
 * Only sources that declare ProvidesTeamGameStats are asked. Today that
 * is CFBD for NCAAF, which is also the sport with the most games on
 * record by a wide margin, so it is the one where per-game detail has
 * the most to work with.
 *
 * Nothing here improves a verdict on its own. The rows have to exist
 * before a feature can read them, and the feature has to beat a closing
 * line before it means anything - which is a separate and much harder
 * question. This is the part that was simply missing.
 */
class TeamStatsCommand extends Command
{
    protected $signature = 'ingest:team-stats
                            {sport=all : Sport key, or "all" for every source that can}
                            {--season= : Season to pull, defaults to the current one}
                            {--week= : One week only, which is what keeping current needs}
                            {--from-season= : Backfill from this season up to --season}';

    protected $description = 'Pull box scores for finished games into team_game_stats';

    public function handle(SourceRegistry $sources, TeamGameStatsWriter $writer): int
    {
        $sports = $this->sports();

        if ($sports === []) {
            $this->error('Unknown sport. Pass a sport key or "all".');

            return self::FAILURE;
        }

        $season = (int) ($this->option('season') ?? now()->year);
        $from = $this->option('from-season') === null ? $season : (int) $this->option('from-season');
        $week = $this->option('week') === null ? null : (int) $this->option('week');

        if ($from > $season) {
            $this->error('--from-season is later than --season.');

            return self::FAILURE;
        }

        $any = false;

        foreach ($sports as $sport) {
            $source = $sources->forRole($sport, 'results');

            if (! $source instanceof ProvidesTeamGameStats) {
                // Not a failure. Most sports have no box score source yet,
                // and saying so for each one is how that stays visible.
                $this->line("  {$sport->value}: no source provides box scores");

                continue;
            }

            if (! $source->isAvailable()) {
                $this->warn("  {$sport->value}: {$source->key()} is not configured");

                continue;
            }

            $any = true;

            for ($year = $from; $year <= $season; $year++) {
                $this->pull($sport, $source, $writer, $year, $week);
            }
        }

        return $any ? self::SUCCESS : self::FAILURE;
    }

    private function pull(
        Sport $sport,
        ProvidesTeamGameStats&SportDataSource $source,
        TeamGameStatsWriter $writer,
        int $season,
        ?int $week,
    ): void {
        $label = $sport->value.' '.$season.($week === null ? '' : " week {$week}");

        try {
            $counts = $writer->write($source->teamGameStats($season, $week));
        } catch (Throwable $e) {
            /*
             * One season failing must not take the rest down: a backfill
             * across several years is the normal use, and the first year
             * a source has no data for should not cost the others.
             */
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
