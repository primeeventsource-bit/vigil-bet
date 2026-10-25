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

        $players = $this->shrinkToGroup($players);

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

        /*
         * Most peer groups are the stat group the feed files. Baseball's
         * are not: pitchers and batters share a group called `all`, and
         * which one a line belongs to is read off the line itself.
         */
        $derivation = RatingSchema::derivation($sport, $group);

        /*
         * Ordered by when the game was played rather than by row id.
         * `as_of` is an ingestion stamp and a backfill carries a later
         * one than the season it is filling, so reading the window off
         * it reported a sample that ran to a date the player had not
         * played on - and "latest team wins" below picked whichever
         * row was written last rather than whichever game was played
         * last, which for a traded player is the wrong team.
         */
        $rows = DB::table('player_game_stats as p')
            ->join('events as e', 'e.id', '=', 'p.event_id')
            ->where('p.sport', $sport)
            ->where('p.stat_group', $derivation['from'] ?? $group)
            ->orderBy('e.starts_at')
            ->orderBy('p.id')
            ->get([
                'p.player_external_id', 'p.player_name', 'p.team_id',
                'p.event_id', 'p.stats', 'e.starts_at as played_at',
            ]);

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

            if ($derivation !== null && ! $this->belongsTo($stats, $derivation)) {
                continue;
            }

            $players[$id] ??= [
                'name' => (string) $row->player_name,
                'team_id' => $row->team_id,
                'games' => 0,
                'events' => [],
                'totals' => [],
                'counts' => [],
                'first' => $row->played_at,
                'last' => $row->played_at,
            ];

            // Latest team wins: a traded player belongs to where he is.
            $players[$id]['team_id'] = $row->team_id ?? $players[$id]['team_id'];
            $players[$id]['last'] = $row->played_at;

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
     * Pull every player's measures toward the group average, in
     * proportion to how little we have seen of them.
     *
     * Without this a percentile over fifteen innings and a percentile
     * over a hundred and thirty are treated as the same claim. They are
     * not: measured here, a reliever with ten appearances and a 1.80
     * earned run average ranked above Mason Miller at a hundred and
     * thirty, because a good fortnight produces a better rate than a
     * good season and nothing was asking how long the fortnight was.
     *
     * The estimate is the standard one - the observation and the group
     * mean, weighted by the sample against a constant:
     *
     *     (games x observed + k x mean) / (games + k)
     *
     * At k = 20 a player with twenty games is trusted half as far as the
     * group, one with two hundred almost entirely, and one with ten
     * barely at all. A rate he cannot support is pulled back toward the
     * crowd rather than rewarded for being extreme.
     *
     * This is regression to the mean applied honestly rather than a cap
     * on outliers: a genuinely great player with a long record keeps his
     * number, because the sample carries it.
     *
     * @param  array<string, array<string, mixed>>  $players
     * @return array<string, array<string, mixed>>
     */
    private function shrinkToGroup(array $players): array
    {
        $k = (float) config('edge.players.shrink_games', 20);

        if ($k <= 0.0) {
            return $players;
        }

        /*
         * The mean is taken over players rather than over games, so one
         * everyday starter cannot drag the average he is being compared
         * against toward himself.
         */
        $means = [];
        $counts = [];

        foreach ($players as $player) {
            foreach ($player['measures'] as $key => $value) {
                $means[$key] = ($means[$key] ?? 0.0) + $value;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        foreach ($means as $key => $sum) {
            $means[$key] = $sum / max($counts[$key], 1);
        }

        foreach ($players as $id => $player) {
            foreach ($player['measures'] as $key => $value) {
                $games = (float) $player['games'];

                $players[$id]['measures'][$key] =
                    (($games * $value) + ($k * $means[$key])) / ($games + $k);
            }
        }

        return $players;
    }

    /**
     * Whether a line belongs to a derived peer group.
     *
     * A pitching line carries ERA; a batting line carries at-bats and no
     * ERA. A line answering to neither is counted in neither rather than
     * forced into one - a pinch runner's line says nothing about hitting
     * or pitching, and rating him on it would invent a player.
     *
     * @param  array<string, mixed>  $stats
     * @param  array{from: string, requires: array<int, string>, excludes: array<int, string>}  $derivation
     */
    private function belongsTo(array $stats, array $derivation): bool
    {
        foreach ($derivation['requires'] as $key) {
            if (! array_key_exists($key, $stats)) {
                return false;
            }
        }

        foreach ($derivation['excludes'] as $key) {
            if (array_key_exists($key, $stats)) {
                return false;
            }
        }

        return true;
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
     * A stat as a number, read the way its kind says to read it.
     *
     * The reading itself lives in StatValue, shared with the prop
     * projections. Both are built from these same rows and a difference
     * of opinion about what "5-12" means would be invisible: the rating
     * and the projection would simply be about different numbers.
     */
    private function value(mixed $value, string $kind): ?float
    {
        return StatValue::read($value, $kind);
    }

    /** Baseball's innings notation as a real number. */
    private function innings(mixed $value): ?float
    {
        return StatValue::innings($value);
    }

    /** The first half of a "made-attempted" compound. */
    private function madePart(mixed $value): ?float
    {
        return StatValue::madePart($value);
    }

    private function numeric(mixed $value): ?float
    {
        return StatValue::numeric($value);
    }
}
