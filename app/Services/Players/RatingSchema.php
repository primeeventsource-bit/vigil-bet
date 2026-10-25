<?php

declare(strict_types=1);

namespace App\Services\Players;

/**
 * What each sport is rated on.
 *
 * Every peer group a box score files a player under, not just the
 * glamour ones. A first version rated quarterbacks, backs, receivers and
 * the defence, which left out 63 kickers, 56 punters, 307 kick returners,
 * 164 punt returners and the 489 players who recorded an interception -
 * all of whom appear on fantasy teams, and some of whom carry the largest
 * prop market there is.
 *
 * The keys are the ones actually stored, read off 754,000 player lines
 * rather than taken from any feed's documentation. They are not
 * consistent between sports and there is no use pretending otherwise: the
 * NBA files everything under `all` and calls a basket `points`, the NFL
 * splits into ten groups and calls a passing yard `passingYards`, hockey
 * splits forwards, defences and goalies.
 *
 * Three kinds of stat, and the difference is arithmetic rather than
 * taste:
 *
 *   total - a counting stat, read per game.
 *   rate  - already an average, so averaged. Summing a batting average
 *           produces a number about nothing.
 *   made  - a compound the feed writes as "5-12". Casting that gives 5
 *           by accident and 12 never; the first part is the one that
 *           happened, so it is taken deliberately.
 *
 * `better` exists because earned run average and goals against are better
 * when lower, and a leaderboard taking the highest would put a team's
 * worst pitcher at the top.
 *
 * The weights are a stated editorial judgement about what matters in a
 * position, written in the open so they can be argued with. They are not
 * fitted coefficients and nothing here pretends they are.
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
            ['group' => 'all', 'key' => 'points', 'label' => 'scoring', 'kind' => 'total', 'weight' => 0.30, 'better' => 'high'],
            ['group' => 'all', 'key' => 'rebounds', 'label' => 'rebounding', 'kind' => 'total', 'weight' => 0.15, 'better' => 'high'],
            ['group' => 'all', 'key' => 'assists', 'label' => 'playmaking', 'kind' => 'total', 'weight' => 0.15, 'better' => 'high'],
            // A live prop market with 157 players priced, and the first
            // version of this rated none of them.
            ['group' => 'all', 'key' => 'threePointFieldGoalsMade-threePointFieldGoalsAttempted', 'label' => 'three point shooting', 'kind' => 'made', 'weight' => 0.12, 'better' => 'high'],
            ['group' => 'all', 'key' => 'steals', 'label' => 'steals', 'kind' => 'total', 'weight' => 0.08, 'better' => 'high'],
            ['group' => 'all', 'key' => 'blocks', 'label' => 'rim protection', 'kind' => 'total', 'weight' => 0.08, 'better' => 'high'],
            ['group' => 'all', 'key' => 'minutes', 'label' => 'workload', 'kind' => 'total', 'weight' => 0.07, 'better' => 'high'],
            // Turnovers are a cost, so the scale runs the other way.
            ['group' => 'all', 'key' => 'turnovers', 'label' => 'ball security', 'kind' => 'total', 'weight' => 0.05, 'better' => 'low'],
        ],

        'nhl' => [
            ['group' => 'forwards', 'key' => 'goals', 'label' => 'scoring', 'kind' => 'total', 'weight' => 0.30, 'better' => 'high'],
            ['group' => 'forwards', 'key' => 'assists', 'label' => 'playmaking', 'kind' => 'total', 'weight' => 0.25, 'better' => 'high'],
            ['group' => 'forwards', 'key' => 'shotsTotal', 'label' => 'shot volume', 'kind' => 'total', 'weight' => 0.15, 'better' => 'high'],
            ['group' => 'forwards', 'key' => 'timeOnIce', 'label' => 'workload', 'kind' => 'rate', 'weight' => 0.10, 'better' => 'high'],
            ['group' => 'forwards', 'key' => 'hits', 'label' => 'physicality', 'kind' => 'total', 'weight' => 0.10, 'better' => 'high'],
            ['group' => 'forwards', 'key' => 'blockedShots', 'label' => 'defence', 'kind' => 'total', 'weight' => 0.10, 'better' => 'high'],

            ['group' => 'defenses', 'key' => 'blockedShots', 'label' => 'defence', 'kind' => 'total', 'weight' => 0.25, 'better' => 'high'],
            ['group' => 'defenses', 'key' => 'assists', 'label' => 'playmaking', 'kind' => 'total', 'weight' => 0.20, 'better' => 'high'],
            ['group' => 'defenses', 'key' => 'hits', 'label' => 'physicality', 'kind' => 'total', 'weight' => 0.20, 'better' => 'high'],
            ['group' => 'defenses', 'key' => 'timeOnIce', 'label' => 'workload', 'kind' => 'rate', 'weight' => 0.15, 'better' => 'high'],
            ['group' => 'defenses', 'key' => 'goals', 'label' => 'scoring', 'kind' => 'total', 'weight' => 0.10, 'better' => 'high'],
            ['group' => 'defenses', 'key' => 'takeaways', 'label' => 'puck recovery', 'kind' => 'total', 'weight' => 0.10, 'better' => 'high'],

            ['group' => 'goalies', 'key' => 'savePct', 'label' => 'save rate', 'kind' => 'rate', 'weight' => 0.45, 'better' => 'high'],
            ['group' => 'goalies', 'key' => 'saves', 'label' => 'saves', 'kind' => 'total', 'weight' => 0.30, 'better' => 'high'],
            ['group' => 'goalies', 'key' => 'goalsAgainst', 'label' => 'goals conceded', 'kind' => 'total', 'weight' => 0.25, 'better' => 'low'],
        ],

        /*
         * Baseball files pitchers and batters under one group called
         * `all`, and the same words mean opposite things in each. A
         * pitcher's `hits` are hits he allowed; a batter's are hits he
         * got. Rating them together produced a leaderboard with Aaron
         * Nola at 98 for "hits 98, power 98" - he had been hit for five
         * and taken deep twice.
         *
         * So the role is read off the line itself: a pitching line
         * carries ERA and earned runs, a batting line carries at-bats
         * and a slugging average. That is a fact in the data rather than
         * a roster lookup, and it is the same distinction a box score
         * makes on the page.
         */
        'mlb' => [
            ['group' => 'batting', 'key' => 'hits', 'label' => 'hits', 'kind' => 'total', 'weight' => 0.20, 'better' => 'high'],
            ['group' => 'batting', 'key' => 'avg', 'label' => 'contact', 'kind' => 'rate', 'weight' => 0.15, 'better' => 'high'],
            ['group' => 'batting', 'key' => 'homeRuns', 'label' => 'power', 'kind' => 'total', 'weight' => 0.15, 'better' => 'high'],
            ['group' => 'batting', 'key' => 'RBIs', 'label' => 'run production', 'kind' => 'total', 'weight' => 0.15, 'better' => 'high'],
            ['group' => 'batting', 'key' => 'slugAvg', 'label' => 'slugging', 'kind' => 'rate', 'weight' => 0.12, 'better' => 'high'],
            ['group' => 'batting', 'key' => 'onBasePct', 'label' => 'on base', 'kind' => 'rate', 'weight' => 0.12, 'better' => 'high'],
            ['group' => 'batting', 'key' => 'runs', 'label' => 'runs scored', 'kind' => 'total', 'weight' => 0.06, 'better' => 'high'],
            // A batter striking out is a cost. The first version of this
            // rewarded it.
            ['group' => 'batting', 'key' => 'strikeouts', 'label' => 'strikeouts', 'kind' => 'total', 'weight' => 0.05, 'better' => 'low'],

            ['group' => 'pitching', 'key' => 'ERA', 'label' => 'earned run average', 'kind' => 'rate', 'weight' => 0.30, 'better' => 'low'],
            ['group' => 'pitching', 'key' => 'strikeouts', 'label' => 'strikeouts', 'kind' => 'total', 'weight' => 0.25, 'better' => 'high'],
            // Allowed, every one of them.
            ['group' => 'pitching', 'key' => 'hits', 'label' => 'hits allowed', 'kind' => 'total', 'weight' => 0.15, 'better' => 'low'],
            ['group' => 'pitching', 'key' => 'earnedRuns', 'label' => 'runs allowed', 'kind' => 'total', 'weight' => 0.12, 'better' => 'low'],
            ['group' => 'pitching', 'key' => 'homeRuns', 'label' => 'home runs allowed', 'kind' => 'total', 'weight' => 0.10, 'better' => 'low'],
            ['group' => 'pitching', 'key' => 'walks', 'label' => 'walks allowed', 'kind' => 'total', 'weight' => 0.08, 'better' => 'low'],
        ],

        /*
         * The NFL carries no position on a box score line - none of
         * 72,382 rows - so the stat group is the peer group. A player
         * filed under `kicking` is a kicker by what he did, which is a
         * better definition than a depth chart.
         *
         * Every group the feed writes is here. Touchdowns appear in six
         * of them, because `player_anytime_td` is the largest prop market
         * on the board at 467 players priced and a returner scores them
         * too.
         */
        'nfl' => [
            ['group' => 'passing', 'key' => 'passingYards', 'label' => 'passing volume', 'kind' => 'total', 'weight' => 0.35, 'better' => 'high'],
            ['group' => 'passing', 'key' => 'QBRating', 'label' => 'passer rating', 'kind' => 'rate', 'weight' => 0.25, 'better' => 'high'],
            ['group' => 'passing', 'key' => 'passingTouchdowns', 'label' => 'passing scores', 'kind' => 'total', 'weight' => 0.20, 'better' => 'high'],
            ['group' => 'passing', 'key' => 'yardsPerPassAttempt', 'label' => 'efficiency', 'kind' => 'rate', 'weight' => 0.12, 'better' => 'high'],
            ['group' => 'passing', 'key' => 'interceptions', 'label' => 'ball security', 'kind' => 'total', 'weight' => 0.08, 'better' => 'low'],

            ['group' => 'rushing', 'key' => 'rushingYards', 'label' => 'rushing volume', 'kind' => 'total', 'weight' => 0.35, 'better' => 'high'],
            ['group' => 'rushing', 'key' => 'rushingTouchdowns', 'label' => 'rushing scores', 'kind' => 'total', 'weight' => 0.25, 'better' => 'high'],
            ['group' => 'rushing', 'key' => 'rushingAttempts', 'label' => 'usage', 'kind' => 'total', 'weight' => 0.20, 'better' => 'high'],
            ['group' => 'rushing', 'key' => 'yardsPerRushAttempt', 'label' => 'efficiency', 'kind' => 'rate', 'weight' => 0.20, 'better' => 'high'],

            ['group' => 'receiving', 'key' => 'receivingYards', 'label' => 'receiving volume', 'kind' => 'total', 'weight' => 0.30, 'better' => 'high'],
            ['group' => 'receiving', 'key' => 'receptions', 'label' => 'receptions', 'kind' => 'total', 'weight' => 0.25, 'better' => 'high'],
            ['group' => 'receiving', 'key' => 'receivingTouchdowns', 'label' => 'receiving scores', 'kind' => 'total', 'weight' => 0.25, 'better' => 'high'],
            ['group' => 'receiving', 'key' => 'receivingTargets', 'label' => 'target share', 'kind' => 'total', 'weight' => 0.20, 'better' => 'high'],

            ['group' => 'defensive', 'key' => 'totalTackles', 'label' => 'tackling', 'kind' => 'total', 'weight' => 0.30, 'better' => 'high'],
            ['group' => 'defensive', 'key' => 'sacks', 'label' => 'pass rush', 'kind' => 'total', 'weight' => 0.25, 'better' => 'high'],
            ['group' => 'defensive', 'key' => 'passesDefended', 'label' => 'coverage', 'kind' => 'total', 'weight' => 0.20, 'better' => 'high'],
            ['group' => 'defensive', 'key' => 'tacklesForLoss', 'label' => 'disruption', 'kind' => 'total', 'weight' => 0.15, 'better' => 'high'],
            ['group' => 'defensive', 'key' => 'QBHits', 'label' => 'pressure', 'kind' => 'total', 'weight' => 0.10, 'better' => 'high'],

            ['group' => 'interceptions', 'key' => 'interceptions', 'label' => 'interceptions', 'kind' => 'total', 'weight' => 0.55, 'better' => 'high'],
            ['group' => 'interceptions', 'key' => 'interceptionYards', 'label' => 'return yards', 'kind' => 'total', 'weight' => 0.25, 'better' => 'high'],
            ['group' => 'interceptions', 'key' => 'interceptionTouchdowns', 'label' => 'scores', 'kind' => 'total', 'weight' => 0.20, 'better' => 'high'],

            ['group' => 'kicking', 'key' => 'totalKickingPoints', 'label' => 'points', 'kind' => 'total', 'weight' => 0.40, 'better' => 'high'],
            ['group' => 'kicking', 'key' => 'fieldGoalPct', 'label' => 'accuracy', 'kind' => 'rate', 'weight' => 0.30, 'better' => 'high'],
            ['group' => 'kicking', 'key' => 'fieldGoalsMade/fieldGoalAttempts', 'label' => 'field goals', 'kind' => 'made', 'weight' => 0.20, 'better' => 'high'],
            ['group' => 'kicking', 'key' => 'longFieldGoalMade', 'label' => 'range', 'kind' => 'total', 'weight' => 0.10, 'better' => 'high'],

            ['group' => 'punting', 'key' => 'grossAvgPuntYards', 'label' => 'distance', 'kind' => 'rate', 'weight' => 0.40, 'better' => 'high'],
            ['group' => 'punting', 'key' => 'puntsInside20', 'label' => 'placement', 'kind' => 'total', 'weight' => 0.30, 'better' => 'high'],
            ['group' => 'punting', 'key' => 'longPunt', 'label' => 'range', 'kind' => 'total', 'weight' => 0.20, 'better' => 'high'],
            ['group' => 'punting', 'key' => 'touchbacks', 'label' => 'touchbacks', 'kind' => 'total', 'weight' => 0.10, 'better' => 'low'],

            ['group' => 'kickReturns', 'key' => 'kickReturnYards', 'label' => 'return volume', 'kind' => 'total', 'weight' => 0.40, 'better' => 'high'],
            ['group' => 'kickReturns', 'key' => 'yardsPerKickReturn', 'label' => 'efficiency', 'kind' => 'rate', 'weight' => 0.30, 'better' => 'high'],
            ['group' => 'kickReturns', 'key' => 'kickReturnTouchdowns', 'label' => 'scores', 'kind' => 'total', 'weight' => 0.20, 'better' => 'high'],
            ['group' => 'kickReturns', 'key' => 'longKickReturn', 'label' => 'longest', 'kind' => 'total', 'weight' => 0.10, 'better' => 'high'],

            ['group' => 'puntReturns', 'key' => 'puntReturnYards', 'label' => 'return volume', 'kind' => 'total', 'weight' => 0.40, 'better' => 'high'],
            ['group' => 'puntReturns', 'key' => 'yardsPerPuntReturn', 'label' => 'efficiency', 'kind' => 'rate', 'weight' => 0.30, 'better' => 'high'],
            ['group' => 'puntReturns', 'key' => 'puntReturnTouchdowns', 'label' => 'scores', 'kind' => 'total', 'weight' => 0.20, 'better' => 'high'],
            ['group' => 'puntReturns', 'key' => 'longPuntReturn', 'label' => 'longest', 'kind' => 'total', 'weight' => 0.10, 'better' => 'high'],

            ['group' => 'fumbles', 'key' => 'fumblesRecovered', 'label' => 'recoveries', 'kind' => 'total', 'weight' => 0.50, 'better' => 'high'],
            ['group' => 'fumbles', 'key' => 'fumblesLost', 'label' => 'fumbles lost', 'kind' => 'total', 'weight' => 0.30, 'better' => 'low'],
            ['group' => 'fumbles', 'key' => 'fumbles', 'label' => 'fumbles', 'kind' => 'total', 'weight' => 0.20, 'better' => 'low'],
        ],
    ];

    /**
     * Peer groups that are not the stat group the feed files.
     *
     * Baseball writes pitchers and batters into one group called `all`,
     * and the same words mean opposite things in each: a pitcher's
     * `hits` are hits he allowed. So the role is read off the line - a
     * pitching line carries ERA, a batting line carries at-bats - which
     * is a fact in the data rather than a roster lookup, and the same
     * distinction a box score makes on the page.
     *
     * `requires` must all be present and `excludes` must all be absent.
     * A line satisfying neither is rated in neither, which is right: a
     * pinch runner's line says nothing about hitting or pitching.
     *
     * @var array<string, array<string, array{from: string, requires: array<int, string>, excludes: array<int, string>}>>
     */
    public const DERIVED_GROUPS = [
        'mlb' => [
            'batting' => ['from' => 'all', 'requires' => ['atBats'], 'excludes' => ['ERA']],
            'pitching' => ['from' => 'all', 'requires' => ['ERA'], 'excludes' => []],
        ],
    ];

    /**
     * How a peer group is found in the data.
     *
     * @return array{from: string, requires: array<int, string>, excludes: array<int, string>}|null
     */
    public static function derivation(string $sport, string $group): ?array
    {
        return self::DERIVED_GROUPS[$sport][$group] ?? null;
    }

    /**
     * The stats behind each prop market we see priced.
     *
     * Kept here rather than in the projection code because a market with
     * no stat behind it cannot be projected, and the mapping should fail
     * loudly in one place rather than quietly in several.
     *
     * Anytime touchdown is scored from six groups, which is why it is a
     * list: a returner scores them too, and a rating built only from
     * rushing would have nothing to say about the largest market on the
     * board.
     *
     * @var array<string, array<int, array{group: string, key: string}>>
     */
    public const PROP_STATS = [
        'player_points' => [['group' => 'all', 'key' => 'points']],
        'player_rebounds' => [['group' => 'all', 'key' => 'rebounds']],
        'player_assists' => [['group' => 'all', 'key' => 'assists']],
        'player_threes' => [['group' => 'all', 'key' => 'threePointFieldGoalsMade-threePointFieldGoalsAttempted']],

        'player_pass_yds' => [['group' => 'passing', 'key' => 'passingYards']],
        'player_rush_yds' => [['group' => 'rushing', 'key' => 'rushingYards']],
        'player_reception_yds' => [['group' => 'receiving', 'key' => 'receivingYards']],
        'player_anytime_td' => [
            ['group' => 'rushing', 'key' => 'rushingTouchdowns'],
            ['group' => 'receiving', 'key' => 'receivingTouchdowns'],
            ['group' => 'kickReturns', 'key' => 'kickReturnTouchdowns'],
            ['group' => 'puntReturns', 'key' => 'puntReturnTouchdowns'],
            ['group' => 'interceptions', 'key' => 'interceptionTouchdowns'],
            ['group' => 'defensive', 'key' => 'defensiveTouchdowns'],
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

    /**
     * The stats behind a prop market, or an empty list when we have none.
     *
     * @return array<int, array{group: string, key: string}>
     */
    public static function statsForProp(string $marketKey): array
    {
        return self::PROP_STATS[$marketKey] ?? [];
    }
}
