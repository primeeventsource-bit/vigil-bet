<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Sport;
use Carbon\CarbonImmutable;

/**
 * One historical game: what was knowable beforehand, what the market closed
 * at, and what actually happened.
 *
 * The result lives here and never travels into a prediction, because the
 * runner only ever passes `context()` to a model.
 */
final readonly class BacktestGame
{
    public function __construct(
        public int $eventId,
        public Sport $sport,
        public int $homeTeamId,
        public int $awayTeamId,
        public CarbonImmutable $startsAt,
        public int $homeScore,
        public int $awayScore,
        public bool $neutralSite = false,
        public ?int $season = null,
        public ?int $homeRestDays = null,
        public ?int $awayRestDays = null,
        public ?string $roof = null,
        public ?string $surface = null,
        public ?bool $divisional = null,
        /** @var array<string, int> closing American prices by side, if any */
        public array $closingMarket = [],
        /**
         * The line the market closed at, for a market that has one.
         *
         * Signed the way this app stores a home spread: negative when the
         * home side is favoured, so the home side covers when the margin
         * plus the line is above zero.
         */
        public ?float $closingLine = null,
        public array $features = [],
    ) {}

    /** The view a model is given. No score fields exist on it. */
    public function context(): PreGameContext
    {
        return new PreGameContext(
            eventId: $this->eventId,
            sport: $this->sport,
            homeTeamId: $this->homeTeamId,
            awayTeamId: $this->awayTeamId,
            startsAt: $this->startsAt,
            neutralSite: $this->neutralSite,
            season: $this->season,
            homeRestDays: $this->homeRestDays,
            awayRestDays: $this->awayRestDays,
            roof: $this->roof,
            surface: $this->surface,
            divisional: $this->divisional,
            features: $this->features,
        );
    }

    public function margin(): int
    {
        return $this->homeScore - $this->awayScore;
    }

    public function total(): int
    {
        return $this->homeScore + $this->awayScore;
    }

    /** 1 when the home side won, 0 otherwise. A tie is not a home win. */
    public function homeWon(): int
    {
        return $this->homeScore > $this->awayScore ? 1 : 0;
    }

    public function wasTie(): bool
    {
        return $this->homeScore === $this->awayScore;
    }

    /**
     * 1 when the home side covered the line, 0 when it did not, null on a
     * push.
     *
     * A push is not a near miss and not a loss: the bet is void and the
     * money comes back, so it is no evidence either way about the model
     * and belongs out of the sample entirely - the same treatment a tie
     * already gets on the moneyline.
     */
    public function homeCovered(float $line): ?int
    {
        $margin = (float) $this->margin() + $line;

        if (abs($margin) < 1e-9) {
            return null;
        }

        return $margin > 0 ? 1 : 0;
    }

    public function hasClosingMarket(): bool
    {
        return count($this->closingMarket) >= 2;
    }
}
