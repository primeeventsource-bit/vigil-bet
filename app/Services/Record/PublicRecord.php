<?php

declare(strict_types=1);

namespace App\Services\Record;

use App\Models\PublishedPick;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The public record.
 *
 * §7: "Settled automatically from Edge Lab results. Shows record, units,
 * ROI and closing line value, always with sample size. With zero settled
 * picks, show no rate at all."
 *
 * That last sentence is the whole design of this class. Every rate is
 * null when there is nothing behind it, not zero - because a 0% ROI and
 * an unmeasured ROI look identical on a page and mean entirely different
 * things, and the second one is the current state of this product.
 *
 * Nothing here touches a backtest. §7 forbids mixing paper results with
 * published picks in one number, and the guarantee is structural: this
 * class only reads `published_picks`, which only contains picks that were
 * published before kickoff.
 */
class PublicRecord
{
    /**
     * @param  array<string, mixed>  $filters  sport, market, month (Y-m)
     * @return array<string, mixed>
     */
    public function summary(array $filters = []): array
    {
        $picks = $this->query($filters)->with('result')->get();

        $settled = $picks->filter(fn (PublishedPick $p) => $p->result !== null
            && in_array($p->result->status, ['won', 'lost', 'push'], true));

        $decided = $settled->filter(fn (PublishedPick $p) => in_array($p->result->status, ['won', 'lost'], true));
        $withClv = $picks->filter(fn (PublishedPick $p) => $p->result?->clv !== null);

        return [
            'published' => $picks->count(),
            'settled' => $settled->count(),
            'pending' => $picks->filter(fn (PublishedPick $p) => $p->result === null)->count(),
            'won' => $settled->filter(fn (PublishedPick $p) => $p->result->status === 'won')->count(),
            'lost' => $settled->filter(fn (PublishedPick $p) => $p->result->status === 'lost')->count(),
            'pushed' => $settled->filter(fn (PublishedPick $p) => $p->result->status === 'push')->count(),

            // Every one of these is null with no sample. Never zero.
            'win_rate' => $decided->isEmpty()
                ? null
                : round($decided->filter(fn (PublishedPick $p) => $p->result->status === 'won')->count() / $decided->count(), 4),

            'units' => $settled->isEmpty()
                ? null
                : round($settled->sum(fn (PublishedPick $p) => (float) $p->result->units), 2),

            /*
             * ROI on one unit a pick, because that is the only staking
             * plan the record can honestly assume. Staking to the
             * suggested fraction would be a different and much better
             * looking number that no reader actually followed.
             */
            'roi' => $settled->isEmpty()
                ? null
                : round($settled->sum(fn (PublishedPick $p) => (float) $p->result->units) / $settled->count(), 4),

            'clv' => $withClv->isEmpty()
                ? null
                : round($withClv->avg(fn (PublishedPick $p) => (float) $p->result->clv), 5),
            'clv_sample' => $withClv->count(),

            // The sample size travels with every figure above, because a
            // rate without one is a number pretending to be evidence.
            'first_published_at' => $picks->min('published_at'),
            'last_published_at' => $picks->max('published_at'),
        ];
    }

    /**
     * The picks themselves, newest first.
     *
     * Every pick, including the losers and the ones still running. A
     * record page that showed only winners would be an advertisement,
     * and §7 asks for the opposite of one.
     *
     * @param  array<string, mixed>  $filters
     */
    public function picks(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->query($filters)
            ->with('result')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * What a reader can filter by: only values that actually exist.
     *
     * §7 asks for sport, market and month. Read from the published rows
     * rather than from a list, so a filter never offers an option with
     * nothing behind it.
     *
     * @return array{sports: Collection<int, string>, markets: Collection<int, string>, months: Collection<int, string>}
     */
    public function filterOptions(): array
    {
        return [
            'sports' => PublishedPick::query()->distinct()->orderBy('sport')->pluck('sport'),
            'markets' => PublishedPick::query()->distinct()->orderBy('market')->pluck('market'),
            'months' => PublishedPick::query()
                ->orderByDesc('published_at')
                ->pluck('published_at')
                ->map(fn ($at) => Carbon::parse($at)->format('Y-m'))
                ->unique()
                ->values(),
        ];
    }

    /**
     * The record month by month, for a chart.
     *
     * Cumulative units, so the line answers "has this made money since it
     * started" rather than "was last month good". A month with no settled
     * picks contributes no point rather than a zero.
     *
     * @param  array<string, mixed>  $filters
     * @return array{labels: array<int, string>, points: array<int, float>}
     */
    public function curve(array $filters = []): array
    {
        $settled = $this->query($filters)
            ->with('result')
            ->get()
            ->filter(fn (PublishedPick $p) => $p->result !== null
                && in_array($p->result->status, ['won', 'lost', 'push'], true))
            ->sortBy(fn (PublishedPick $p) => $p->result->settled_at);

        if ($settled->isEmpty()) {
            // No points, and the page says so. A flat line at zero would
            // read as "tried and broke even".
            return ['labels' => [], 'points' => []];
        }

        $running = 0.0;
        $labels = [];
        $points = [];

        foreach ($settled as $pick) {
            $running += (float) $pick->result->units;
            $labels[] = $pick->result->settled_at->format('j M');
            $points[] = round($running, 2);
        }

        return ['labels' => $labels, 'points' => $points];
    }

    /** @param  array<string, mixed>  $filters */
    private function query(array $filters): Builder
    {
        return PublishedPick::query()
            ->when($filters['sport'] ?? null, fn (Builder $q, string $sport) => $q->where('sport', $sport))
            ->when($filters['market'] ?? null, fn (Builder $q, string $market) => $q->where('market', $market))
            ->when($filters['month'] ?? null, function (Builder $q, string $month) {
                // Y-m, validated by the caller. Compared as a range
                // rather than with a date function, so the index is
                // usable and the behaviour is the same on both engines.
                $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();

                return $q->whereBetween('published_at', [$start, $start->copy()->endOfMonth()]);
            });
    }
}
