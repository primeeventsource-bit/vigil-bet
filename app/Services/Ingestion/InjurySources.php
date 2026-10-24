<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\Sport;
use RuntimeException;

/**
 * Maps a sport to the availability source configured for it.
 *
 * This exists because the configuration was fiction. config/sports.php
 * declared an injuries source per sport - nflverse for the NFL, null for
 * the NBA - and nothing read any of it: SyncInjuries took EspnInjurySource
 * by constructor injection and used it for every sport. So the NFL's
 * injuries came from ESPN while the config said nflverse, which has no
 * injury endpoint at all, and the NBA's came from ESPN while the config
 * said it had no source. Both statements were wrong in the direction that
 * matters, because the config is where someone looks to find out.
 *
 * Now the config decides, and a sport with nothing configured gets
 * nothing - recorded as a skip, which is visible on the Jobs screen,
 * rather than quietly falling back to whichever source was wired in.
 *
 * @phpstan-type InjurySource EspnInjurySource|BalldontlieInjurySource
 */
class InjurySources
{
    /** @var array<string, class-string> */
    private array $map;

    /** @param  array<string, class-string>  $map */
    public function __construct(array $map = [])
    {
        $this->map = $map === [] ? [
            'espn' => EspnInjurySource::class,
            'balldontlie' => BalldontlieInjurySource::class,
        ] : $map;
    }

    /**
     * The source for a sport, or null when none is configured or the one
     * configured cannot run - a paid source with no key, for instance.
     */
    public function forSport(Sport $sport): ?object
    {
        $key = $sport->config('sources.injuries');

        if (! is_string($key) || $key === '') {
            return null;
        }

        if (! isset($this->map[$key])) {
            throw new RuntimeException("No injury source registered for key [{$key}].");
        }

        $source = app($this->map[$key]);

        return $source->isAvailable($sport) ? $source : null;
    }

    /** Why there is no source, in words a Jobs screen can show. */
    public function reasonFor(Sport $sport): string
    {
        $key = $sport->config('sources.injuries');

        if (! is_string($key) || $key === '') {
            return 'No injury source is configured for '.$sport->value.'.';
        }

        return sprintf(
            'The %s injury source for %s is configured but unavailable, which usually means a missing key.',
            $key,
            $sport->value,
        );
    }
}
