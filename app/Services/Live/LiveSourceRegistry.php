<?php

declare(strict_types=1);

namespace App\Services\Live;

use App\Enums\Sport;

/**
 * Which live feeds answer for a sport.
 *
 * Two sources on one sport is the point rather than redundancy: with no
 * licensed reference clock, a feed can only be measured against another
 * feed watching the same game.
 */
class LiveSourceRegistry
{
    /** @return array<int, LiveStateSource> */
    public function for(Sport $sport): array
    {
        $candidates = [new EspnLiveSource($sport)];

        $candidates[] = match ($sport) {
            Sport::Nhl => new NhlLiveSource,
            Sport::Mlb => new MlbLiveSource,
            default => null,
        };

        return array_values(array_filter(
            $candidates,
            fn (?LiveStateSource $source) => $source !== null && $source->isAvailable(),
        ));
    }

    /** Sports with at least two independent feeds, which are the measurable ones. */
    public function comparable(): array
    {
        return array_values(array_filter(
            Sport::enabled(),
            fn (Sport $sport) => count($this->for($sport)) > 1,
        ));
    }
}
