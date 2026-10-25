<?php

declare(strict_types=1);

namespace App\Services\Players;

use App\Data\PropProjection;
use App\Modeling\Math\NegativeBinomial;
use App\Modeling\Math\Normal;
use App\Modeling\Math\Poisson;
use Illuminate\Support\Facades\DB;

/**
 * Turns a player's game log into a probability about one line.
 *
 * The ratings answer "how good is he", which is a ranking question. This
 * answers "will he clear 7.5 rebounds tonight", which is a level
 * question, and the difference changes the arithmetic in one important
 * place.
 *
 * The ratings shrink every measure toward its peer group's mean, which is
 * correct for a rank: it stops a good fortnight outranking a good season.
 * Doing the same here would be a bug with a direction. A back averaging a
 * hundred rushing yards, shrunk toward a pool averaging twenty-five,
 * projects seventy-eight - so the model would say "under" on essentially
 * every line it was shown, and the reason would be invisible, because
 * each individual projection would look sober and defensible.
 *
 * So the level is the player's own recency-weighted average and nothing
 * else. It is noisier than a shrunk one and it is not biased, and for a
 * number compared against a line that is the trade to make. The sample
 * behind it travels with it, and a thin sample is refused rather than
 * smoothed into looking adequate.
 *
 * What IS pooled is the spread, because a variance over twelve games is
 * genuinely badly estimated and pooling it moves the level not at all.
 * Pooled as a ratio rather than as a number: spread scales with level, so
 * pooling raw variance would over-smooth a hundred-yard rusher and
 * under-smooth a ten-yard one. The dispersion index for counts and the
 * coefficient of variation for yardage are both scale-free, so they can
 * be shared across a group and multiplied back by each player's own mean.
 *
 * Recency is weighted rather than windowed. A sample has to span seasons
 * to be big enough to say anything in October, and a player's role in
 * October is not his role last March; an exponential half-life in games
 * lets last season inform the estimate without governing it.
 */
class PropProjector
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $logs = [];

    /** @var array<string, array<string, float>> */
    private array $pooled = [];

    /**
     * Project one line, or decline.
     *
     * Null is returned, never a guess, and for reasons worth telling
     * apart: the market has no distribution declared, the name is not a
     * person, the player has no box scores under a name we can match, or
     * he has too few games. `reasonFor` names which.
     */
    public function project(string $sport, string $marketKey, string $playerName, float $line): ?PropProjection
    {
        $model = RatingSchema::modelForProp($marketKey);

        if ($model === null || ! PlayerNameKey::isPerson($playerName)) {
            return null;
        }

        $logs = $this->logsFor($sport, $marketKey);
        $key = PlayerNameKey::for($playerName);

        if (! isset($logs[$key])) {
            return null;
        }

        $log = $logs[$key];

        if (count($log['values']) < $this->minimumGames()) {
            return null;
        }

        return $this->fromLog($sport, $marketKey, $key, $log, $line, $model);
    }

    /**
     * Why a projection was declined, for a report that can be acted on.
     *
     * A count of nulls says the feature is partly broken without saying
     * which part, and the four reasons need four different fixes: a
     * missing distribution is a line of config, an unmatched name is a
     * normalising rule, and a thin sample is nobody's fault and will
     * resolve itself.
     */
    public function reasonFor(string $sport, string $marketKey, string $playerName): ?string
    {
        if (RatingSchema::modelForProp($marketKey) === null) {
            return 'market not modelled';
        }

        if (! PlayerNameKey::isPerson($playerName)) {
            return 'not a player';
        }

        $logs = $this->logsFor($sport, $marketKey);
        $key = PlayerNameKey::for($playerName);

        if (! isset($logs[$key])) {
            return 'no box scores';
        }

        if (count($logs[$key]['values']) < $this->minimumGames()) {
            return 'too few games';
        }

        return null;
    }

    /**
     * Every player's game log for one market's stat.
     *
     * Summed across stat groups within a game, which matters for anytime
     * touchdown: a player who ran one in and caught one has two
     * touchdowns in one game, filed by the feed as two rows in two
     * groups. Treated as two games he would look like a player who
     * scores every time out.
     *
     * @return array<string, array<string, mixed>>
     */
    public function logsFor(string $sport, string $marketKey): array
    {
        $cacheKey = $sport.'|'.$marketKey;

        if (isset($this->logs[$cacheKey])) {
            return $this->logs[$cacheKey];
        }

        $propStats = RatingSchema::statsForProp($marketKey);

        if ($propStats === []) {
            return $this->logs[$cacheKey] = [];
        }

        $groups = array_values(array_unique(array_column($propStats, 'group')));

        // group => [stat key => kind], so each field is read the way its
        // own schema entry says to read it.
        $wanted = [];

        foreach ($propStats as $stat) {
            $wanted[$stat['group']][$stat['key']] = RatingSchema::kindOf($sport, $stat['group'], $stat['key']);
        }

        /*
         * Ordered by when the game was PLAYED, not by when the row was
         * written, and the difference is not academic - it inverted the
         * recency weighting for exactly the players it mattered most
         * for.
         *
         * `as_of` is an ingestion stamp. A backfill of the 2023-24
         * season ran after this season's games were pulled, so those
         * rows carry the later stamp, so ordering by it put a player's
         * oldest games at the newest end of his log and gave them the
         * heaviest weight. Ty Jerome has gone 5.2, 11.0, 17.8, 30.0
         * points a game across four seasons, and the weighting was
         * leaning on the 5.2.
         */
        $rows = DB::table('player_game_stats as p')
            ->join('events as e', 'e.id', '=', 'p.event_id')
            ->where('p.sport', $sport)
            ->whereIn('p.stat_group', $groups)
            ->orderBy('e.starts_at')
            ->orderBy('p.id')
            ->get([
                'p.player_external_id', 'p.player_name', 'p.team_id', 'p.event_id',
                'p.stat_group', 'p.stats', 'e.starts_at as played_at',
            ]);

        /** @var array<string, array<string, mixed>> $players */
        $players = [];

        foreach ($rows as $row) {
            $name = (string) $row->player_name;

            if ($name === '' || ! PlayerNameKey::isPerson($name)) {
                continue;
            }

            $stats = json_decode((string) $row->stats, true);

            if (! is_array($stats)) {
                continue;
            }

            $value = null;

            foreach ($wanted[$row->stat_group] ?? [] as $statKey => $kind) {
                $read = StatValue::read($stats[$statKey] ?? null, $kind);

                if ($read !== null) {
                    $value = ($value ?? 0.0) + $read;
                }
            }

            // No reading at all is a line that does not mention this
            // stat, which is not the same fact as a zero.
            if ($value === null) {
                continue;
            }

            $key = PlayerNameKey::for($name);

            $players[$key] ??= [
                'name' => $name,
                'external_ids' => [],
                'team_id' => $row->team_id,
                'byEvent' => [],
                'first' => $row->played_at,
                'last' => $row->played_at,
            ];

            $players[$key]['external_ids'][(string) $row->player_external_id] = true;
            $players[$key]['team_id'] = $row->team_id ?? $players[$key]['team_id'];
            $players[$key]['last'] = $row->played_at;

            // Summed per event, so two stat groups in one game are one
            // game with a bigger number.
            $players[$key]['byEvent'][$row->event_id] =
                ($players[$key]['byEvent'][$row->event_id] ?? 0.0) + $value;
        }

        $logs = [];

        foreach ($players as $key => $player) {
            /*
             * A key claimed by two different players is refused rather
             * than resolved. Stripping a generational suffix is the one
             * lossy step in the join and this is the thing it can break:
             * a father and a son normalise to the same key, and picking
             * either would attach one record to the other's price with
             * nothing downstream able to tell.
             */
            if (count($player['external_ids']) > 1) {
                continue;
            }

            $logs[$key] = [
                'name' => $player['name'],
                'external_id' => (string) array_key_first($player['external_ids']),
                'team_id' => $player['team_id'],
                // Oldest first: the recency weights are positional.
                'values' => array_values($player['byEvent']),
                'first' => $player['first'],
                'last' => $player['last'],
            ];
        }

        return $this->logs[$cacheKey] = $logs;
    }

    /**
     * One projection from one log.
     *
     * @param  array<string, mixed>  $log
     * @param  array{family: string, sides: array<int, string>, threshold?: float}  $model
     */
    private function fromLog(
        string $sport,
        string $marketKey,
        string $key,
        array $log,
        float $line,
        array $model,
    ): PropProjection {
        /** @var array<int, float> $values */
        $values = $log['values'];
        $weights = $this->recencyWeights(count($values));

        $sumW = array_sum($weights);
        $sumW2 = array_sum(array_map(static fn (float $w) => $w * $w, $weights));

        $mean = 0.0;

        foreach ($values as $i => $value) {
            $mean += $weights[$i] * $value;
        }

        $mean /= $sumW;

        /*
         * How many games this is really worth. Twelve games weighted
         * toward the last fortnight are not twelve games of evidence,
         * and the Kish effective sample size says how much they are
         * worth instead.
         */
        $effective = ($sumW * $sumW) / $sumW2;

        $spread = 0.0;

        foreach ($values as $i => $value) {
            $spread += $weights[$i] * (($value - $mean) ** 2);
        }

        $spread /= $sumW;

        // Bias correction on the effective sample rather than the raw
        // count, since that is the sample the spread was measured over.
        if ($effective > 1.0) {
            $spread *= $effective / ($effective - 1.0);
        }

        $empirical = $this->weightedShare($values, $weights, $sumW, static fn (float $v) => $v > $line);

        [$over, $under, $push, $sd] = match ($model['family']) {
            'threshold' => $this->threshold($values, $weights, $sumW, $effective, $sport, $marketKey, $model, $spread),
            'continuous' => $this->continuous($mean, $spread, $effective, $sport, $marketKey, $line),
            default => $this->count($mean, $spread, $effective, $sport, $marketKey, $line),
        };

        return new PropProjection(
            sport: $sport,
            marketKey: $marketKey,
            playerKey: $key,
            playerName: (string) $log['name'],
            playerExternalId: $log['external_id'] ?? null,
            line: $line,
            projectedMean: $mean,
            projectedSd: $sd,
            probOver: $over,
            probUnder: $under,
            probPush: $push,
            empiricalOver: $empirical,
            games: count($values),
            effectiveGames: $effective,
            family: $model['family'],
            measuredFrom: $log['first'] === null ? null : (string) $log['first'],
            measuredTo: $log['last'] === null ? null : (string) $log['last'],
        );
    }

    /**
     * A small whole number: rebounds, assists, threes, points.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function count(float $mean, float $spread, float $effective, string $sport, string $marketKey, float $line): array
    {
        /*
         * A player who has never recorded this stat is a real case, and
         * the answer is not a distribution - it is zero. Poisson would
         * refuse a rate of nothing and the refusal would be an exception
         * rather than an answer.
         */
        if ($mean <= 0.0) {
            return [0.0, 1.0, 0.0, 0.0];
        }

        // The dispersion index, pooled as a ratio so the pooling is
        // scale-free, then multiplied back by this player's own mean.
        $dispersion = $this->pooledRatio(
            own: $spread / $mean,
            effective: $effective,
            sport: $sport,
            marketKey: $marketKey,
            statistic: 'dispersion',
        );

        $variance = $dispersion * $mean;

        /*
         * Null means the sample varies no more than its own mean, so
         * there is no dispersion parameter to be had and the negative
         * binomial declines rather than inventing one.
         */
        $fitted = NegativeBinomial::dispersionFrom($mean, $variance);

        if ($fitted !== null) {
            $outcomes = NegativeBinomial::outcomes($mean, $fitted, $line);

            return [$outcomes['over'], $outcomes['under'], $outcomes['push'], sqrt($variance)];
        }

        // Underdispersed, so the negative binomial declines and Poisson
        // is the right family rather than a fallback.
        $push = floor($line) === $line ? Poisson::pmf((int) $line, $mean) : 0.0;
        $under = max(0.0, Poisson::cdf((int) floor($line), $mean) - $push);

        return [max(0.0, 1.0 - $under - $push), $under, $push, sqrt($mean)];
    }

    /**
     * Yardage.
     *
     * A normal cannot produce a push, and books do post whole-number
     * yardage lines. The push probability on one is small but not zero,
     * and this reports zero: an understatement of a rare case, stated
     * here rather than discovered later.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function continuous(float $mean, float $spread, float $effective, string $sport, string $marketKey, float $line): array
    {
        if ($mean <= 0.0) {
            return [0.0, 1.0, 0.0, 0.0];
        }

        $cv = $this->pooledRatio(
            own: sqrt(max($spread, 0.0)) / $mean,
            effective: $effective,
            sport: $sport,
            marketKey: $marketKey,
            statistic: 'cv',
        );

        $sd = max($cv * $mean, 1e-6);

        return [
            Normal::survival($line, $mean, $sd),
            Normal::cdf($line, $mean, $sd),
            0.0,
            $sd,
        ];
    }

    /**
     * Did it happen at all - anytime touchdown.
     *
     * The weighted share of games in which he reached the threshold,
     * shrunk toward the group's rate on the effective sample. No count
     * distribution is involved, deliberately: see PROP_MODELS for the
     * six points a Poisson costs on exactly this market.
     *
     * @param  array<int, float>  $values
     * @param  array<int, float>  $weights
     * @param  array{family: string, sides: array<int, string>, threshold?: float}  $model
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function threshold(
        array $values,
        array $weights,
        float $sumW,
        float $effective,
        string $sport,
        string $marketKey,
        array $model,
        float $spread,
    ): array {
        $threshold = (float) ($model['threshold'] ?? 1.0);

        $rate = $this->weightedShare($values, $weights, $sumW, static fn (float $v) => $v >= $threshold);

        $k = (float) config('edge.props.threshold_prior_games', 8);
        $pool = $this->pool($sport, $marketKey)['rate'] ?? $rate;

        /*
         * Shrunk, where the level estimates above are not, and the
         * difference is not an inconsistency. A rate is bounded at 0 and
         * 1, so a player who has not scored in eleven games has an
         * unshrunk estimate of exactly zero - a claim that he cannot
         * score, which no sample of eleven supports. Shrinkage toward
         * the group rate is what stops a certainty being asserted from
         * an absence.
         */
        $shrunk = $k <= 0.0
            ? $rate
            : (($effective * $rate) + ($k * $pool)) / ($effective + $k);

        /*
         * And a floor, because shrinking toward the group is not on its
         * own enough to stop a certainty.
         *
         * If the group rate is itself zero - every player on file has
         * never done this - the blend above returns zero and asserts
         * impossibility again, by a different route. So the Jeffreys
         * posterior mean for nought from n sets the floor and its mirror
         * the ceiling: half a success over the effective sample plus
         * one. Eleven blank games become four percent rather than
         * nothing, which is what eleven blank games actually support.
         */
        $jeffreys = 0.5 / ($effective + 1.0);

        $shrunk = min(max($shrunk, $jeffreys), 1.0 - $jeffreys);

        return [$shrunk, 1.0 - $shrunk, 0.0, sqrt(max($spread, 0.0))];
    }

    /**
     * Blend a scale-free spread statistic with the group's.
     *
     * The level is never blended; see the class comment. This is the
     * spread, where a twelve-game estimate is genuinely poor and the
     * blend costs no bias in the number being compared to the line.
     */
    private function pooledRatio(float $own, float $effective, string $sport, string $marketKey, string $statistic): float
    {
        $k = (float) config('edge.props.dispersion_pool_games', 8);
        $pool = $this->pool($sport, $marketKey)[$statistic] ?? $own;

        if (! is_finite($own) || $own <= 0.0) {
            return max($pool, 1e-6);
        }

        if ($k <= 0.0) {
            return $own;
        }

        return (($effective * $own) + ($k * $pool)) / ($effective + $k);
    }

    /**
     * The group's dispersion, coefficient of variation and threshold
     * rate, over every player with enough games to contribute one.
     *
     * Averaged over players rather than over games, so one player with a
     * long record cannot drag the group he is being pooled against
     * toward himself.
     *
     * @return array<string, float>
     */
    private function pool(string $sport, string $marketKey): array
    {
        $cacheKey = $sport.'|'.$marketKey;

        if (isset($this->pooled[$cacheKey])) {
            return $this->pooled[$cacheKey];
        }

        $model = RatingSchema::modelForProp($marketKey);
        $threshold = (float) ($model['threshold'] ?? 1.0);

        $dispersions = [];
        $cvs = [];
        $rates = [];

        foreach ($this->logsFor($sport, $marketKey) as $log) {
            $values = $log['values'];

            if (count($values) < $this->minimumGames()) {
                continue;
            }

            $n = count($values);
            $mean = array_sum($values) / $n;

            $rates[] = count(array_filter($values, static fn (float $v) => $v >= $threshold)) / $n;

            if ($mean <= 0.0) {
                continue;
            }

            $variance = 0.0;

            foreach ($values as $value) {
                $variance += ($value - $mean) ** 2;
            }

            $variance /= max($n - 1, 1);

            if ($variance <= 0.0) {
                continue;
            }

            $dispersions[] = $variance / $mean;
            $cvs[] = sqrt($variance) / $mean;
        }

        return $this->pooled[$cacheKey] = [
            // A dispersion of 1 is Poisson, which is the right thing to
            // assume in the absence of any group to measure.
            'dispersion' => $dispersions === [] ? 1.0 : array_sum($dispersions) / count($dispersions),
            'cv' => $cvs === [] ? 0.5 : array_sum($cvs) / count($cvs),
            'rate' => $rates === [] ? 0.0 : array_sum($rates) / count($rates),
        ];
    }

    /**
     * Exponential weights, oldest to newest.
     *
     * @return array<int, float>
     */
    private function recencyWeights(int $count): array
    {
        $halfLife = (float) config('edge.props.recency_half_life_games', 20);

        if ($halfLife <= 0.0) {
            return array_fill(0, $count, 1.0);
        }

        $weights = [];

        for ($i = 0; $i < $count; $i++) {
            // The newest game weighs 1 and one half-life back weighs a half.
            $weights[$i] = 2 ** (-(($count - 1 - $i) / $halfLife));
        }

        return $weights;
    }

    /**
     * The weighted share of games satisfying a test.
     *
     * @param  array<int, float>  $values
     * @param  array<int, float>  $weights
     */
    private function weightedShare(array $values, array $weights, float $sumW, callable $test): float
    {
        if ($sumW <= 0.0) {
            return 0.0;
        }

        $hit = 0.0;

        foreach ($values as $i => $value) {
            if ($test($value)) {
                $hit += $weights[$i];
            }
        }

        return $hit / $sumW;
    }

    private function minimumGames(): int
    {
        return (int) config('edge.props.min_games', 10);
    }
}
