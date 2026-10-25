<?php

declare(strict_types=1);

namespace App\Services\Players;

use Illuminate\Support\Facades\DB;

/**
 * Turns box scores into a 0-99 rating.
 *
 * The scale is borrowed from the sports games because it is legible at a
 * glance. The method is not borrowed, and cannot be: 2K and EA do not
 * publish theirs, and claiming to reproduce them would be a claim about
 * someone else's work.
 *
 * What a rating here means is exactly one thing - a percentile. 94 means
 * the player's production sits in the 94th percentile of others in his
 * peer group, over the games on file. That is narrower than "94 overall"
 * implies anywhere else, which is why the sample travels with it
 * everywhere it is shown.
 *
 * Three decisions carry most of the weight:
 *
 * A percentile rather than a scaled average, because production is not
 * normally distributed. One player with 40 points a game would drag a
 * linear scale down around everybody else; a rank is immune to him.
 *
 * Within a peer group, because comparing a centre's rebounds to a
 * guard's would produce a rating that measures position rather than
 * ability. The NFL groups by what a player did rather than a depth
 * chart, since its box scores carry no position at all.
 *
 * And a minimum sample, because a percentile over three games ranks noise.
 */
class PlayerRatingCalculator
{
    /**
     * Games before a player can be rated.
     *
     * Ten is enough for a per-game average to mean something and low
     * enough to rate most of a league: measured across the four sports,
     * 1,734 of 2,906 NFL players clear it, 758 of 1,015 in the NBA.
     */
    public const MINIMUM_GAMES = 10;

    /**
     * Rate every player in one sport.
     *
     * @return array<int, array<string, mixed>> one row per rated player
     */
    public function forSport(string $sport): array
    {
        $ratings = [];

        foreach (RatingSchema::groups($sport) as $group) {
            foreach ($this->rateGroup($sport, $group) as $rating) {
                $ratings[] = $rating;
            }
        }

        /*
         * A player can appear in more than one group - a quarterback who
         * runs is in `passing` and `rushing` - and only one rating per
         * player is stored. The group they played most in is the one
         * that describes them.
         */
        $best = [];

        foreach ($ratings as $rating) {
            $id = $rating['player_external_id'];

            if (! isset($best[$id]) || $rating['games'] > $best[$id]['games']) {
                $best[$id] = $rating;
            }
        }

        return array_values($best);
    }

    /**
     * Rate everyone inside one peer group.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rateGroup(string $sport, string $group): array
    {
        $measures = RatingSchema::forGroup($sport, $group);

        if ($measures === []) {
            return [];
        }

        $players = $this->production($sport, $group);

        if ($players === []) {
            return [];
        }

        /*
         * Percentiles are taken per measure across the whole group, so
         * every component is on the same scale before the weights are
         * applied. Weighting raw yards against a passer rating would mean
         * the yards decided everything.
         */
        $percentiles = [];

        foreach ($measures as $measure) {
            $key = $measure['group'].'|'.$measure['key'];
            $values = [];

            foreach ($players as $id => $player) {
                if (isset($player['measures'][$key])) {
                    $values[$id] = $player['measures'][$key];
                }
            }

            $percentiles[$key] = $this->percentileRanks($values, $measure['better'] === 'high');
        }

        $rated = [];

        foreach ($players as $id => $player) {
            $components = [];
            $weighted = 0.0;
            $weight = 0.0;

            foreach ($measures as $measure) {
                $key = $measure['group'].'|'.$measure['key'];
                $rank = $percentiles[$key][$id] ?? null;

                if ($rank === null) {
                    continue;
                }

                $components[$measure['label']] = (int) round($rank * 99);
                $weighted += $rank * $measure['weight'];
                $weight += $measure['weight'];
            }

            /*
             * A player missing every measure in his group has nothing to
             * rate. He is skipped rather than given a zero, which would
             * read as "measured and terrible".
             */
            if ($weight <= 0.0) {
                continue;
            }

            $rated[] = [
                'sport' => $sport,
                'player_external_id' => (string) $id,
                'player_name' => $player['name'],
                'peer_group' => $group,
                'team_id' => $player['team_id'],
                'overall' => (int) round(($weighted / $weight) * 99),
                'components' => $components,
                'games' => $player['games'],
                'measured_from' => $player['first'],
                'measured_to' => $player['last'],
            ];
        }

        return $rated;
    }

    /**
     * Per-player production for one peer group.
     *
     * Counting stats are divided by the games the player appeared in -
     * not by the length of the window, which would punish anyone who
     * missed time. Rates are averaged, because a batting average summed
     * over a season is a number about nothing.
     *
     * @return array<string, array<string, mixed>>
     */
    private function production(string $sport, string $group): array
    {
        $measures = RatingSchema::forGroup($sport, $group);
        $wanted = array_column($measures, 'key');
        $kinds = array_combine($wanted, array_column($measures, 'kind'));

        $rows = DB::table('player_game_stats')
            ->where('sport', $sport)
            ->where('stat_group', $group)
            ->orderBy('id')
            ->get(['player_external_id', 'player_name', 'team_id', 'event_id', 'stats', 'as_of']);

        $players = [];

        foreach ($rows as $row) {
            $id = (string) $row->player_external_id;

            if ($id === '') {
                continue;
            }

            $stats = json_decode((string) $row->stats, true);

            if (! is_array($stats)) {
                continue;
            }

            $players[$id] ??= [
                'name' => (string) $row->player_name,
                'team_id' => $row->team_id,
                'games' => 0,
                'events' => [],
                'totals' => [],
                'counts' => [],
                'first' => $row->as_of,
                'last' => $row->as_of,
            ];

            // Latest team wins: a traded player belongs to where he is.
            $players[$id]['team_id'] = $row->team_id ?? $players[$id]['team_id'];
            $players[$id]['last'] = $row->as_of;

            if (! isset($players[$id]['events'][$row->event_id])) {
                $players[$id]['events'][$row->event_id] = true;
                $players[$id]['games']++;
            }

            foreach ($wanted as $key) {
                $value = $this->value($stats[$key] ?? null, $kinds[$key]);

                if ($value === null) {
                    continue;
                }

                $players[$id]['totals'][$key] = ($players[$id]['totals'][$key] ?? 0.0) + $value;
                $players[$id]['counts'][$key] = ($players[$id]['counts'][$key] ?? 0) + 1;
            }
        }

        $out = [];

        foreach ($players as $id => $player) {
            if ($player['games'] < self::MINIMUM_GAMES) {
                continue;
            }

            $measured = [];

            foreach ($wanted as $key) {
                if (! isset($player['totals'][$key])) {
                    continue;
                }

                /*
                 * A rate is averaged over the appearances it was quoted
                 * in. Everything else - counting stats and the made half
                 * of a compound - is per game played.
                 */
                $measured[$group.'|'.$key] = $kinds[$key] === 'rate'
                    ? $player['totals'][$key] / max($player['counts'][$key], 1)
                    : $player['totals'][$key] / $player['games'];
            }

            if ($measured === []) {
                continue;
            }

            $out[$id] = [
                'name' => $player['name'],
                'team_id' => $player['team_id'],
                'games' => $player['games'],
                'first' => $player['first'],
                'last' => $player['last'],
                'measures' => $measured,
            ];
        }

        return $out;
    }

    /**
     * Where each value sits in its group, from 0 to 1.
     *
     * Ties share a rank rather than being broken arbitrarily: two players
     * with identical production are identically good, and ordering them
     * by database id would invent a difference.
     *
     * @param  array<string, float>  $values
     * @return array<string, float>
     */
    private function percentileRanks(array $values, bool $higherIsBetter): array
    {
        if ($values === []) {
            return [];
        }

        $sorted = array_values($values);
        sort($sorted);

        $count = count($sorted);
        $ranks = [];

        foreach ($values as $id => $value) {
            /*
             * The share of the group this value beats, plus half the
             * share it ties with - the standard mid-rank, which keeps a
             * group of identical players at the middle of the scale
             * instead of all at the top or all at the bottom.
             */
            $below = 0;
            $equal = 0;

            foreach ($sorted as $other) {
                if ($other < $value) {
                    $below++;
                } elseif ($other === $value) {
                    $equal++;
                }
            }

            $rank = ($below + ($equal / 2)) / $count;

            $ranks[$id] = $higherIsBetter ? $rank : 1.0 - $rank;
        }

        return $ranks;
    }

    /**
     * A stat as a number, or nothing.
     *
     * The feeds print a completion line as "27/44" in the same field
     * shape as a yardage. Casting that gives 27, which is not wrong so
     * much as about a different thing.
     */
    /**
     * A stat as a number, read the way its kind says to read it.
     *
     * A `made` stat is a compound the feed writes as "5-12" or "5/12":
     * five three-pointers from twelve attempts, three field goals from
     * four. The first part is what happened and the second is what was
     * tried, so the first is taken deliberately rather than by letting a
     * cast pick it up by accident.
     *
     * Everything else must be wholly a number. A completion line of
     * "27/44" cast as a yardage gives 27, which is not wrong so much as
     * about a different thing.
     */
    private function value(mixed $value, string $kind): ?float
    {
        if ($kind === 'made') {
            return $this->madePart($value);
        }

        return $this->numeric($value);
    }

    /** The first half of a "made-attempted" compound. */
    private function madePart(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $parts = preg_split('/[-\/]/', trim($value), 2);

        if ($parts === false || $parts === []) {
            return null;
        }

        return is_numeric(trim($parts[0])) ? (float) trim($parts[0]) : null;
    }

    private function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric(trim($value)) ? (float) trim($value) : null;
    }
}
