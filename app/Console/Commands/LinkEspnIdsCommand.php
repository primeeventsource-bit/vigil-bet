<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SeasonType;
use App\Enums\Sport;
use App\Services\Ingestion\EspnSource;
use App\Services\Ingestion\ExternalIdLinker;
use Illuminate\Console\Command;
use Throwable;

/**
 * Puts ESPN event ids on events that came from somewhere else.
 *
 * NFL events came from nflverse and are keyed `2026_07_NE_CHI`. ESPN is
 * the only source with NFL box scores and answers by its own numeric id,
 * so until those ids are on file a box score has nothing to attach to.
 * That was the whole of the NFL blocker: 830 finished games, zero ESPN
 * ids.
 *
 * Safe to re-run. A game already linked is counted and skipped, and
 * nothing here creates an event - a second source naming a fixture
 * differently would otherwise duplicate it, and a duplicate event is
 * worse than a missing box score because every rating sees both.
 *
 * A season here means ESPN's season, which is not every league's.
 * Measured on 25 October 2026, ESPN's MLB `season=2026` returned 2,739
 * games running 1 August 2026 to 13 July 2027, while MLB's own API calls
 * March to November 2026 the 2026 season. Asked for one ESPN season, the
 * linker therefore matched only the overlap - 786 of 2,439 games - and
 * reported the rest as having no event, which reads like a matching bug
 * and is a calendar disagreement.
 *
 * So covering one league season can take two ESPN seasons, and the fix
 * for a large `no matching event` count is usually to run the
 * neighbouring season rather than to go looking at the matcher. Both
 * runs are cheap because the second one skips what the first linked.
 */
class LinkEspnIdsCommand extends Command
{
    protected $signature = 'ingest:link-espn
                            {sport : Sport key whose events need ESPN ids}
                            {--season= : Season to walk, defaults to the current one}
                            {--from-season= : Walk from this season up to --season}
                            {--postseason : Also walk the postseason}';

    protected $description = 'Attach ESPN event ids to events ingested from another source';

    public function handle(ExternalIdLinker $linker): int
    {
        $sport = Sport::tryFrom(mb_strtolower((string) $this->argument('sport')));

        if ($sport === null) {
            $this->error('Unknown sport.');

            return self::FAILURE;
        }

        $source = app()->makeWith(EspnSource::class, ['sport' => $sport]);

        if (! $source->isAvailable()) {
            $this->error("No ESPN path is configured for {$sport->value}.");

            return self::FAILURE;
        }

        $season = (int) ($this->option('season') ?? now()->year);
        $from = $this->option('from-season') === null ? $season : (int) $this->option('from-season');

        if ($from > $season) {
            $this->error('--from-season is later than --season.');

            return self::FAILURE;
        }

        $types = [SeasonType::Regular];

        if ($this->option('postseason')) {
            $types[] = SeasonType::Postseason;
        }

        for ($year = $from; $year <= $season; $year++) {
            foreach ($types as $type) {
                $label = "{$sport->value} {$year} {$type->value}";

                try {
                    $counts = $linker->link($source->schedule($year, $type), $source->key());
                } catch (Throwable $e) {
                    // One season must not cost the rest of a backfill.
                    $this->error("  {$label}: ".$e->getMessage());

                    continue;
                }

                $this->line(sprintf(
                    '  %s: linked %d, already %d, no matching event %d, unresolved team %d',
                    $label,
                    $counts['linked'],
                    $counts['already'],
                    $counts['no_event'],
                    $counts['no_team'],
                ));
            }
        }

        return self::SUCCESS;
    }
}
