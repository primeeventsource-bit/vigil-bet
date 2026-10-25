<?php

declare(strict_types=1);

namespace App\Services\Players;

/**
 * What each sport is rated on.
 *
 * The keys are the ones actually stored, measured across 754,000 player
 * lines rather than taken from any feed's documentation: the NBA files
 * everything under a group called `all` and calls a basket `points`, the
 * NFL splits into ten groups and calls a passing yard `passingYards`,
 * hockey splits forwards, defences and goalies.
 *
 * Two kinds of stat, and the difference is arithmetic. A `total` is a
 * counting stat and is read per game. A `rate` is already an average -
 * a save percentage, a batting average - so it is averaged, never summed.
 * Adding two batting averages produces a number about nothing.
 *
 * Weights say how much each part carries into the overall. They are a
 * stated editorial judgement about what matters in a sport, not a fitted
 * coefficient, and they are written here in the open so they can be
 * argued with rather than discovered.
 */
final class RatingSchema
{
    /**
     * @var array<string, array<int, array{
     *     group: string, key: string, label: string, kind: string,
     *     weight: float, better: string
     * }>>
     */
    public const SPORTS = [
        'nba' => [
            ['group' => 'all', 'key' => 'points', 'label' => 'scoring', 'kind' => 'total', 'weight' => 0.40, 'better' => 'high'],
            ['group' => 'all', 'key' => 'assists', 'label' => 'playmaking', 'kind' => 'total', 'weight' => 0.20, 'better' => 'high'],
            ['group' => 'all', 'key' => 'rebounds', 'label' => 'rebounding', 'kind' => 'total', 'weight' => 0.20, 'better' => 'high'],
            ['group' => 'all', 'key' => 'steals', 'label' => 'defence', 'kind' => 'total', 'weight' => 0.10, 'better' => 'high'],
            ['group' => 'all', 'key' => 'blocks', 'label' => 'rim protection', 'kind' => 'total', 'weight' => 0.10, 'better' => 'high'],
        ],
        'nhl' => [
            ['group' => 'forwards', 'key' => 'goals', 'label' => 'scoring', 'kind' => 'total', 'weight' => 0.40, 'better' => 'high'],
            ['group' => 'forwards', 'key' => 'assists', 'label' => 'playmaking', 'kind' => 'total', 'weight' => 0.30, 'better' => 'high'],
            ['group' => 'forwards', 'key' => 'hits', 'label' => 'physicality', 'kind' => 'total', 'weight' => 0.15, 'better' => 'high'],
            ['group' => 'forwards', 'key' => 'blockedShots', 'label' => 'defence', 'kind' => 'total', 'weight' => 0.15, 'better' => 'high'],

            ['group' => 'defenses', 'key' => 'blockedShots', 'label' => 'defence', 'kind' => 'total', 'weight' => 0.35, 'better' => 'high'],
            ['group' => 'defenses', 'key' => 'hits', 'label' => 'physicality', 'kind' => 'total', 'weight' => 0.25, 'better' => 'high'],
            ['group' => 'defenses', 'key' => 'assists', 'label' => 'playmaking', 'kind' => 'total', 'weight' => 0.25, 'better' => 'high'],
            ['group' => 'defenses', 'key' => 'goals', 'label' => 'scoring', 'kind' => 'total', 'weight' => 0.15, 'better' => 'high'],

            ['group' => 'goalies', 'key' => 'savePct', 'label' => 'save rate', 'kind' => 'rate', 'weight' => 0.70, 'better' => 'high'],
            ['group' => 'goalies', 'key' => 'saves', 'label' => 'workload', 'kind' => 'total', 'weight' => 0.30, 'better' => 'high'],
        ],
        'mlb' => [
            ['group' => 'all', 'key' => 'avg', 'label' => 'contact', 'kind' => 'rate', 'weight' => 0.30, 'better' => 'high'],
            ['group' => 'all', 'key' => 'homeRuns', 'label' => 'power', 'kind' => 'total', 'weight' => 0.25, 'better' => 'high'],
            ['group' => 'all', 'key' => 'RBIs', 'label' => 'run production', 'kind' => 'total', 'weight' => 0.25, 'better' => 'high'],
            ['group' => 'all', 'key' => 'onBasePct', 'label' => 'on base', 'kind' => 'rate', 'weight' => 0.20, 'better' => 'high'],
        ],
        /*
         * The NFL carries no position on a box score line, so the stat
         * group is the peer group - a player filed under `passing` is a
         * quarterback by what he did, which is a better definition than
         * a depth chart anyway.
         */
        'nfl' => [
            ['group' => 'passing', 'key' => 'passingYards', 'label' => 'passing volume', 'kind' => 'total', 'weight' => 0.45, 'better' => 'high'],
            ['group' => 'passing', 'key' => 'QBRating', 'label' => 'passer rating', 'kind' => 'rate', 'weight' => 0.35, 'better' => 'high'],
            ['group' => 'passing', 'key' => 'passingTouchdowns', 'label' => 'scoring', 'kind' => 'total', 'weight' => 0.20, 'better' => 'high'],

            ['group' => 'rushing', 'key' => 'rushingYards', 'label' => 'rushing volume', 'kind' => 'total', 'weight' => 0.50, 'better' => 'high'],
            ['group' => 'rushing', 'key' => 'yardsPerRushAttempt', 'label' => 'efficiency', 'kind' => 'rate', 'weight' => 0.30, 'better' => 'high'],
            ['group' => 'rushing', 'key' => 'rushingTouchdowns', 'label' => 'scoring', 'kind' => 'total', 'weight' => 0.20, 'better' => 'high'],

            ['group' => 'receiving', 'key' => 'receivingYards', 'label' => 'receiving volume', 'kind' => 'total', 'weight' => 0.45, 'better' => 'high'],
            ['group' => 'receiving', 'key' => 'receptions', 'label' => 'usage', 'kind' => 'total', 'weight' => 0.30, 'better' => 'high'],
            ['group' => 'receiving', 'key' => 'receivingTouchdowns', 'label' => 'scoring', 'kind' => 'total', 'weight' => 0.25, 'better' => 'high'],

            ['group' => 'defensive', 'key' => 'totalTackles', 'label' => 'tackling', 'kind' => 'total', 'weight' => 0.45, 'better' => 'high'],
            ['group' => 'defensive', 'key' => 'sacks', 'label' => 'pass rush', 'kind' => 'total', 'weight' => 0.30, 'better' => 'high'],
            ['group' => 'defensive', 'key' => 'passesDefended', 'label' => 'coverage', 'kind' => 'total', 'weight' => 0.25, 'better' => 'high'],
        ],
    ];

    /** @return array<int, string> */
    public static function sports(): array
    {
        return array_keys(self::SPORTS);
    }

    /**
     * The measures for one peer group within a sport.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forGroup(string $sport, string $group): array
    {
        return array_values(array_filter(
            self::SPORTS[$sport] ?? [],
            static fn (array $measure): bool => $measure['group'] === $group,
        ));
    }

    /**
     * The peer groups a sport rates within.
     *
     * @return array<int, string>
     */
    public static function groups(string $sport): array
    {
        return array_values(array_unique(array_column(self::SPORTS[$sport] ?? [], 'group')));
    }
}
