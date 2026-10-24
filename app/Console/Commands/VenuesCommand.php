<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Venue;
use App\Services\Ingestion\VenueLocator;
use Illuminate\Console\Command;

/**
 * Put venues on the map.
 *
 * Reference data, run by hand rather than scheduled: a stadium does not
 * move, and a geocoder called on a timer is a geocoder called for nothing.
 */
class VenuesCommand extends Command
{
    protected $signature = 'edge:venues:locate
                            {--force : Re-locate venues that already have coordinates}';

    protected $description = 'Fill in venue coordinates so weather has somewhere to point';

    public function handle(VenueLocator $locator): int
    {
        $before = Venue::query()->whereNotNull('lat')->count();
        $total = Venue::query()->count();

        $this->line(sprintf('%d of %d venues located before this run.', $before, $total));

        // The sports' own feeds first, because they are better: MLB gives
        // the stadium's real coordinates and ESPN gives the roof, which no
        // geocoder can tell you and which decides whether a forecast is
        // worth fetching at all.
        $feeds = $locator->enrichFromFeeds();

        $this->newLine();
        $this->line(sprintf(
            '  from the sports feeds: %d located exactly · %d cities · %d roofs found',
            $feeds['located'],
            $feeds['cities'],
            $feeds['roofed'],
        ));

        // Then the handful no feed reports at all: stadiums under previous
        // sponsor names, and the neutral sites of international games.
        $known = $locator->supplyKnownCities();

        if ($known['filled'] > 0) {
            $this->line(sprintf(
                '  supplied by hand: %d cities for venues no feed reports',
                $known['filled'],
            ));
        }

        // Roofs from the schedules themselves, which beats anyone's memory
        // of which stadiums have lids.
        $domes = $locator->markFixedDomes();

        if ($domes['marked'] > 0) {
            $this->line(sprintf(
                '  from the stored roof words: %d fixed dome%s marked',
                $domes['marked'],
                $domes['marked'] === 1 ? '' : 's',
            ));
        }

        $stats = $locator->locateAll((bool) $this->option('force'));

        $this->newLine();
        $this->line(sprintf(
            '  located %d · no city to go on %d · geocoder found nothing %d',
            $stats['located'],
            $stats['skipped'],
            $stats['unmatched'],
        ));

        if ($stats['skipped'] > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '  %d venue%s carry no city, so there is nothing to geocode. The schedule sources '
                .'publish the city, so the fix is another schedule pull rather than a cleverer '
                .'geocoder - these rows predate the pull that started recording it.',
                $stats['skipped'],
                $stats['skipped'] === 1 ? '' : 's',
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            '%d of %d located now · %d indoor',
            Venue::query()->whereNotNull('lat')->count(),
            $total,
            Venue::query()->where('is_dome', true)->count(),
        ));

        $this->newLine();
        $this->line('<fg=gray>Coordinates are the town, not the stadium. Good to a mile or two,</>');
        $this->line('<fg=gray>which is the same weather; never read them as a precise location.</>');

        return self::SUCCESS;
    }
}
