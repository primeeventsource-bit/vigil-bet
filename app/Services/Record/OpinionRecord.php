<?php

declare(strict_types=1);

namespace App\Services\Record;

use App\Models\Opinion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How the published opinions have actually done.
 *
 * Every figure here comes from `opinions` joined to `opinion_results`,
 * which hold what was published before kickoff and how it turned out.
 * Nothing is modelled, estimated, or carried over from a backtest - the
 * only reason an accuracy figure on a betting site is worth reading is
 * that it could have come out badly.
 *
 * Two rules shape the whole class, and both are §0.7:
 *
 *  - No rate without its sample. Every rate is returned beside the n it
 *    was computed from, and null when there is nothing to compute from.
 *    A win rate of 0% and a win rate that does not exist look identical
 *    on a page and are not the same thing.
 *  - Nothing here is the public record. That is `published_picks`, a
 *    stronger claim from a proven market, and the two are never summed.
 */
class OpinionRecord
{
    /**
     * The headline figures.
     *
     * @return array<string, mixed>
     */
    public function summary(array $filters = []): array
    {
        $settled = $this->query($filters)->settled();

        $graded = (clone $settled)->whereHas(
            'result',
            fn ($q) => $q->whereIn('status', ['won', 'lost'])
        );

        $gradedCount = (clone $graded)->count();

        $units = (float) DB::table('opinion_results as r')
            ->join('opinions as o', 'o.id', '=', 'r.opinion_id')
            ->whereIn('o.id', (clone $settled)->select('opinions.id'))
            ->sum('r.units');

        $clvCount = (clone $settled)->whereHas('result', fn ($q) => $q->whereNotNull('clv'))->count();

        $meanClv = $clvCount === 0 ? null : (float) DB::table('opinion_results as r')
            ->join('opinions as o', 'o.id', '=', 'r.opinion_id')
            ->whereIn('o.id', (clone $settled)->select('opinions.id'))
            ->whereNotNull('r.clv')
            ->avg('r.clv');

        return [
            'published' => $this->query($filters)->count(),
            'awaiting' => $this->query($filters)->whereDoesntHave('result')->count(),
            'graded' => $gradedCount,

            /*
             * Null, not zero, until something has been graded. The
             * distinction is the whole of §0.7: an unmeasured figure and
             * a measured zero are different claims.
             */
            /*
             * Cast, because PHP's division returns an int when it
             * divides exactly - so one win from one graded opinion came
             * back as int(1) and a rate is always a fraction.
             */
            'win_rate' => $gradedCount === 0
                ? null
                : (float) ((clone $graded)->whereHas('result', fn ($q) => $q->where('status', 'won'))->count() / $gradedCount),

            'units' => $gradedCount === 0 ? null : round($units, 2),

            // Return on what was staked, at one unit a selection.
            'roi' => $gradedCount === 0 ? null : (float) round($units / $gradedCount, 4),

            'clv_sample' => $clvCount,
            'mean_clv' => $meanClv === null ? null : round($meanClv, 5),
        ];
    }

    /**
     * Accuracy against the confidence that was stated.
     *
     * The figure this product is actually accountable for. An opinion
     * published at 70% is a claim that seven of ten such opinions win,
     * and this is where that is checked - not whether the picks won, but
     * whether the number meant what it said.
     *
     * Buckets rather than a single error, because "well calibrated on
     * average" can hide being confident in the wrong direction.
     *
     * @return array<int, array<string, mixed>>
     */
    public function calibration(array $filters = []): array
    {
        $rows = DB::table('opinions as o')
            ->join('opinion_results as r', 'r.opinion_id', '=', 'o.id')
            ->whereIn('r.status', ['won', 'lost'])
            ->when($filters['sport'] ?? null, fn ($q, $sport) => $q->where('o.sport', $sport))
            ->selectRaw('o.model_prob, r.status')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            // Ten-point bands from 50%, which is where a moneyline
            // opinion starts being a claim at all.
            $band = max(50, min(90, (int) (floor((float) $row->model_prob * 10) * 10)));

            $buckets[$band] ??= ['stated' => $band, 'n' => 0, 'won' => 0];
            $buckets[$band]['n']++;
            $buckets[$band]['won'] += $row->status === 'won' ? 1 : 0;
        }

        ksort($buckets);

        return array_values(array_map(static function (array $bucket): array {
            // Again: a rate only exists beside its sample.
            $bucket['actual'] = $bucket['n'] === 0 ? null : (float) ($bucket['won'] / $bucket['n']);

            return $bucket;
        }, $buckets));
    }

    /** @return LengthAwarePaginator<int, Opinion> */
    public function opinions(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->query($filters)
            ->with(['result', 'event:id,starts_at'])
            ->orderByDesc('kickoff_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @return array<string, mixed> */
    public function bySport(array $filters = []): array
    {
        return DB::table('opinions as o')
            ->join('opinion_results as r', 'r.opinion_id', '=', 'o.id')
            ->whereIn('r.status', ['won', 'lost'])
            ->when($filters['sport'] ?? null, fn ($q, $sport) => $q->where('o.sport', $sport))
            ->groupBy('o.sport')
            ->orderByDesc(DB::raw('count(*)'))
            ->get([
                'o.sport',
                DB::raw('count(*) as n'),
                DB::raw("count(*) filter (where r.status = 'won') as won"),
                DB::raw('sum(r.units) as units'),
                DB::raw('avg(r.clv) as clv'),
            ])
            ->all();
    }

    /** @return array<int, string> */
    public function sports(): array
    {
        return Opinion::query()->distinct()->orderBy('sport')->pluck('sport')->all();
    }

    /** @return Builder<Opinion> */
    private function query(array $filters): Builder
    {
        return Opinion::query()
            ->when($filters['sport'] ?? null, fn ($q, $sport) => $q->where('sport', $sport));
    }
}
