<?php

declare(strict_types=1);

namespace App\Services\Live;

use App\Models\LiveFeedProbe;
use Illuminate\Support\Collection;

/**
 * What a probing session actually measured.
 *
 * Three questions, in the order they decide whether a live scanner is worth
 * building:
 *
 *  1. How reliable is each feed - did it answer, how fast, how often.
 *  2. How far behind is each feed. Where a source stamps its readings this
 *     is read off directly; where it does not, it can only be measured
 *     against another feed watching the same game, and the answer is a
 *     relative lag rather than an absolute one. The report says which it
 *     is rather than presenting the two as the same number.
 *  3. How long after a score changed did the price move, and how often did
 *     the price simply stop being quoted.
 *
 * Every figure comes with the sample behind it. A median over four
 * observations is not a measurement, and the count is there to say so.
 */
class ProbeReport
{
    /** @return array<string, mixed> */
    public function forSession(string $session): array
    {
        $probes = LiveFeedProbe::query()->forSession($session)->orderBy('observed_at')->orderBy('id')->get();

        if ($probes->isEmpty()) {
            return ['session' => $session, 'empty' => true];
        }

        $state = $probes->where('kind', 'game_state');
        $odds = $probes->where('kind', 'odds');

        return [
            'session' => $session,
            'empty' => false,
            'from' => $probes->first()->observed_at,
            'to' => $probes->last()->observed_at,
            'sources' => $this->sources($state),
            'absolute_staleness' => $this->absoluteStaleness($probes),
            'relative_lag' => $this->relativeLag($state),
            'odds' => $this->odds($odds),
            'price_response' => $this->priceResponse($state, $odds),
        ];
    }

    /** @return array<int, string> */
    public function sessions(int $limit = 10): array
    {
        return LiveFeedProbe::query()
            ->select('session')
            ->selectRaw('max(observed_at) as last_seen')
            ->selectRaw('count(*) as probes')
            ->groupBy('session')
            ->orderByDesc('last_seen')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (string) $row->session)
            ->all();
    }

    /**
     * Per feed: did it answer, how fast, and how much did it see.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sources(Collection $state): array
    {
        $rows = [];

        foreach ($state->groupBy('source') as $source => $probes) {
            $ok = $probes->where('ok', true);
            $polls = $probes->pluck('observed_at')->map(fn ($at) => $at->getTimestamp())->unique()->count();

            $rows[] = [
                'source' => (string) $source,
                'polls' => $polls,
                'readings' => $ok->count(),
                'failures' => $probes->where('ok', false)->count(),
                'games_seen' => $ok->pluck('external_ref')->filter()->unique()->count(),
                'material_changes' => $ok->where('changed', true)->count(),
                'http_ms_median' => $this->median($ok->pluck('http_ms')->filter()->all()),
                'stamps_readings' => $ok->whereNotNull('source_reported_at')->isNotEmpty(),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['readings'] <=> $a['readings']);

        return $rows;
    }

    /**
     * Staleness where a source states when its reading was true.
     *
     * @return array<int, array<string, mixed>>
     */
    private function absoluteStaleness(Collection $probes): array
    {
        $rows = [];

        foreach ($probes->whereNotNull('staleness_ms')->groupBy('source') as $source => $group) {
            $values = $group->pluck('staleness_ms')->map(fn ($v) => (int) $v)->all();

            $rows[] = [
                'source' => (string) $source,
                'n' => count($values),
                'median_ms' => $this->median($values),
                'p90_ms' => $this->percentile($values, 0.9),
                'worst_ms' => $values === [] ? null : max($values),
            ];
        }

        return $rows;
    }

    /**
     * How far one feed trails another on the same game.
     *
     * For each game and each distinct score state, the first moment every
     * source reported it. The spread between those moments is the lag, and
     * it is signed against whichever feed got there first - which is all
     * that can be said without a licensed reference clock.
     *
     * @return array<string, mixed>
     */
    private function relativeLag(Collection $state): array
    {
        $pairs = [];
        $byGame = $state->where('ok', true)->whereNotNull('game_key')->groupBy('game_key');

        foreach ($byGame as $gameKey => $probes) {
            $sources = $probes->pluck('source')->unique();

            if ($sources->count() < 2) {
                continue;
            }

            // First sighting of each distinct state, per source.
            $firstSeen = [];

            foreach ($probes as $probe) {
                $key = $probe->digest;

                if ($key === null) {
                    continue;
                }

                $slot = $probe->source.'|'.$key;
                $firstSeen[$slot] ??= $probe->observed_at;
            }

            foreach ($probes->pluck('digest')->filter()->unique() as $digest) {
                $times = [];

                foreach ($sources as $source) {
                    $at = $firstSeen[$source.'|'.$digest] ?? null;

                    if ($at !== null) {
                        $times[$source] = $at;
                    }
                }

                // A state only one feed ever reported says nothing about lag.
                if (count($times) < 2) {
                    continue;
                }

                $earliest = min(array_map(fn ($at) => $at->getTimestamp(), $times));

                foreach ($times as $source => $at) {
                    $pairs[$source][] = ($at->getTimestamp() - $earliest) * 1000;
                }
            }

            unset($gameKey);
        }

        $rows = [];

        foreach ($pairs as $source => $lags) {
            $rows[] = [
                'source' => (string) $source,
                'n' => count($lags),
                'median_ms' => $this->median($lags),
                'p90_ms' => $this->percentile($lags, 0.9),
                'first_pct' => count($lags) === 0
                    ? null
                    : round(count(array_filter($lags, fn (int $l) => $l === 0)) / count($lags) * 100, 1),
            ];
        }

        usort($rows, fn (array $a, array $b) => ($a['median_ms'] ?? PHP_INT_MAX) <=> ($b['median_ms'] ?? PHP_INT_MAX));

        return [
            'rows' => $rows,
            'comparable_games' => $byGame->filter(fn (Collection $p) => $p->pluck('source')->unique()->count() > 1)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function odds(Collection $odds): array
    {
        $ok = $odds->where('ok', true);

        if ($ok->isEmpty()) {
            return [
                'measured' => false,
                'failures' => $odds->where('ok', false)->count(),
            ];
        }

        $staleness = $ok->whereNotNull('staleness_ms')->pluck('staleness_ms')->map(fn ($v) => (int) $v)->all();

        return [
            'measured' => true,
            'quotes' => $ok->count(),
            'games' => $ok->pluck('external_ref')->filter()->unique()->count(),
            'books' => $ok->pluck('book_key')->filter()->unique()->count(),
            'price_changes' => $ok->where('changed', true)->count(),
            'staleness_n' => count($staleness),
            'staleness_median_ms' => $this->median($staleness),
            'staleness_p90_ms' => $this->percentile($staleness, 0.9),
            'failures' => $odds->where('ok', false)->count(),
        ];
    }

    /**
     * How long after a score changed did any book reprice.
     *
     * This is the number the whole engine lives or dies on. If the book
     * reprices before our own feed has even reported the score, there is no
     * window to act in and no model can create one.
     *
     * @return array<string, mixed>
     */
    private function priceResponse(Collection $state, Collection $odds): array
    {
        $scoreChanges = $state->where('ok', true)->where('changed', true)->whereNotNull('game_key');
        $priceChanges = $odds->where('ok', true)->where('changed', true)->whereNotNull('game_key');

        if ($scoreChanges->isEmpty() || $priceChanges->isEmpty()) {
            return [
                'measured' => false,
                'score_changes' => $scoreChanges->count(),
                'price_changes' => $priceChanges->count(),
            ];
        }

        $gaps = [];

        foreach ($scoreChanges->groupBy('game_key') as $gameKey => $changes) {
            $prices = $priceChanges->where('game_key', $gameKey)->sortBy('observed_at')->values();

            if ($prices->isEmpty()) {
                continue;
            }

            foreach ($changes as $change) {
                $after = $prices->first(fn ($price) => $price->observed_at->greaterThan($change->observed_at));

                if ($after !== null) {
                    $gaps[] = ($after->observed_at->getTimestamp() - $change->observed_at->getTimestamp()) * 1000;
                }
            }
        }

        return [
            'measured' => $gaps !== [],
            'n' => count($gaps),
            'median_ms' => $this->median($gaps),
            'p90_ms' => $this->percentile($gaps, 0.9),
            'score_changes' => $scoreChanges->count(),
            'price_changes' => $priceChanges->count(),
        ];
    }

    /** @param  array<int, int|float>  $values */
    private function median(array $values): ?int
    {
        return $this->percentile($values, 0.5);
    }

    /** @param  array<int, int|float>  $values */
    private function percentile(array $values, float $at): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        $index = (int) floor($at * (count($values) - 1));

        return (int) round($values[$index]);
    }
}
