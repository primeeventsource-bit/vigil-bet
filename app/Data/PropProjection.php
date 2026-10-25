<?php

declare(strict_types=1);

namespace App\Data;

/**
 * What the box scores say about one player, one stat and one line.
 *
 * A projection, not a pick. It carries no recommendation and no stake,
 * and three of its fields exist so it can be argued with rather than
 * believed:
 *
 *   `effectiveGames` - how much data is actually behind it once recency
 *      weighting is applied. Twelve games weighted toward the last
 *      fortnight are not twelve games of evidence, and a projection that
 *      hides that invites a confident number from almost nothing.
 *
 *   `empiricalOver` - the share of his weighted game log that actually
 *      cleared this line, computed without any distribution at all. When
 *      this and `probOver` disagree, the distribution is the wrong shape
 *      for the stat and the disagreement is the evidence.
 *
 *   `family` - which distribution was used and therefore which
 *      assumption was made.
 */
final readonly class PropProjection
{
    public function __construct(
        public string $sport,
        public string $marketKey,
        public string $playerKey,
        public string $playerName,
        public ?string $playerExternalId,
        public float $line,
        /** Projected per-game mean, recency weighted. No shrink toward any pool. */
        public float $projectedMean,
        /** Spread used for the distribution, after pooling. */
        public float $projectedSd,
        public float $probOver,
        public float $probUnder,
        public float $probPush,
        /** Distribution-free: the weighted share of games that cleared the line. */
        public ?float $empiricalOver,
        public int $games,
        public float $effectiveGames,
        /** 'count', 'continuous' or 'threshold'. */
        public string $family,
        public ?string $measuredFrom = null,
        public ?string $measuredTo = null,
    ) {}

    /**
     * How far the distribution's answer is from the distribution-free one.
     *
     * Large means the family is fighting the data. Worth reading before
     * anything downstream treats `probOver` as a probability.
     */
    public function familyDisagreement(): ?float
    {
        return $this->empiricalOver === null
            ? null
            : abs($this->probOver - $this->empiricalOver);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sport' => $this->sport,
            'market_key' => $this->marketKey,
            'player_key' => $this->playerKey,
            'player_name' => $this->playerName,
            'player_external_id' => $this->playerExternalId,
            'line' => $this->line,
            'projected_mean' => $this->projectedMean,
            'projected_sd' => $this->projectedSd,
            'prob_over' => $this->probOver,
            'prob_under' => $this->probUnder,
            'prob_push' => $this->probPush,
            'empirical_over' => $this->empiricalOver,
            'games' => $this->games,
            'effective_games' => $this->effectiveGames,
            'family' => $this->family,
            'measured_from' => $this->measuredFrom,
            'measured_to' => $this->measuredTo,
        ];
    }
}
