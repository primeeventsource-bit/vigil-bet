<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Sport;

/**
 * What a rating model says about one game, before any market is priced.
 *
 * Keeping the projection separate from the pricing is deliberate. A rating
 * model should answer one question - how will this game go - and the markets
 * are three different ways of asking it. Mixing the two is how a moneyline
 * and a spread from the same model end up quietly disagreeing.
 */
final readonly class GameProjection
{
    public function __construct(
        public Sport $sport,
        public float $homeScore,
        public float $awayScore,
        /** Standard deviation of the final margin, for margin-model sports. */
        public ?float $marginSigma = null,
        /** Overdispersion for count-model sports; null means Poisson. */
        public ?float $dispersion = null,
        public bool $neutralSite = false,
        /** @var array<string, float> anything the model wants to show its working with */
        public array $components = [],
    ) {}

    public function margin(): float
    {
        return $this->homeScore - $this->awayScore;
    }

    public function total(): float
    {
        return $this->homeScore + $this->awayScore;
    }

    /** @param  array<string, float>  $extra */
    public function withComponents(array $extra): self
    {
        return new self(
            sport: $this->sport,
            homeScore: $this->homeScore,
            awayScore: $this->awayScore,
            marginSigma: $this->marginSigma,
            dispersion: $this->dispersion,
            neutralSite: $this->neutralSite,
            components: array_merge($this->components, $extra),
        );
    }
}
