<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VigilRead;
use App\Models\VlBoardEntry;
use App\Models\VlEvent;
use App\Models\VlModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The full value board.
 *
 * §8: "full value board with filters: sport, market, book, proven only,
 * picks/passes". Plus a date, because a board that only shows today is
 * useless the morning after - a customer wants to know what the engine
 * said about Sunday's games on Saturday.
 *
 * The important thing about this page is that it shows the **passes**.
 * Most of the time - all of the time, so far - every candidate is a pass,
 * and a board that hid those would be a blank screen pretending to be an
 * empty one. Five thousand judged candidates with a reason attached to
 * each is a product; "no picks today" on its own is not.
 */
class BoardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $filters = $this->filters($request);

        $query = $this->query($filters);

        /*
         * Counted before paginating, so the summary describes the whole
         * filtered set rather than the page being looked at.
         */
        $counts = [
            'total' => (clone $query)->count(),
            'picks' => (clone $query)->sellablePicks()->count(),
        ];

        $entries = $query
            ->orderByDesc('edge_at_price')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('app.board', [
            'entries' => $entries,
            'counts' => $counts,
            'filters' => $filters,
            'options' => $this->options(),
            'events' => $this->eventsFor($entries->getCollection()),
            'reads' => $this->readsFor($entries->getCollection()),
            'verdicts' => $this->verdictsBySport(),
            'passReasons' => $this->passReasons($filters),
        ]);
    }

    /**
     * What the customer asked for, validated against what exists.
     *
     * A filter value nobody has data for would produce an empty board
     * that looked like a board with nothing on it.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $options = $this->options();

        return [
            'sport' => $this->oneOf($request->query('sport'), $options['sports']->all()),
            'market' => $this->oneOf($request->query('market'), $options['markets']->all()),
            'book' => $this->oneOf($request->query('book'), $options['books']->all()),
            'date' => $this->oneOf($request->query('date'), $options['dates']->all()),
            // Three states, not a checkbox: picks only, passes only, or
            // everything. "Proven only" is the first of those.
            'show' => $this->oneOf($request->query('show'), ['picks', 'passes']) ?? 'all',
        ];
    }

    /** @param  array<string, mixed>  $filters */
    private function query(array $filters): Builder
    {
        return VlBoardEntry::query()
            ->current()
            ->when($filters['sport'], fn (Builder $q, string $s) => $q->where('sport', $s))
            ->when($filters['market'], fn (Builder $q, string $m) => $q->where('market', $m))
            ->when($filters['book'], fn (Builder $q, string $b) => $q->where('best_book', $b))
            ->when($filters['date'], function (Builder $q, string $date) {
                /*
                 * The *game* date, not the date the engine looked. A
                 * customer choosing Sunday means Sunday's fixtures, and
                 * the engine may well have judged them on Friday.
                 */
                $day = Carbon::createFromFormat('Y-m-d', $date);

                return $q->whereIn('edge_lab_event_id', VlEvent::query()->current()
                    ->whereBetween('starts_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
                    ->select('edge_lab_id'));
            })
            ->when($filters['show'] === 'picks', fn (Builder $q) => $q->sellablePicks())
            ->when($filters['show'] === 'passes', fn (Builder $q) => $q->where('recommendation', 'pass'));
    }

    /**
     * Filter values that actually have rows behind them.
     *
     * @return array{sports: Collection<int, string>, markets: Collection<int, string>, books: Collection<int, string>, dates: Collection<int, string>}
     */
    private function options(): array
    {
        return [
            'sports' => VlBoardEntry::query()->current()
                ->distinct()->orderBy('sport')->pluck('sport')->filter()->values(),

            'markets' => VlBoardEntry::query()->current()
                ->distinct()->orderBy('market')->pluck('market')->filter()->values(),

            'books' => VlBoardEntry::query()->current()
                ->whereNotNull('best_book')
                ->distinct()->orderBy('best_book')->pluck('best_book')->values(),

            /*
             * Dates with fixtures, from the mirrored schedule. Only days
             * the engine has something for, so the picker never offers a
             * date that produces an empty board.
             */
            'dates' => VlEvent::query()->current()
                ->whereNotNull('starts_at')
                ->orderBy('starts_at')
                ->pluck('starts_at')
                ->map(fn ($at) => Carbon::parse($at)->toDateString())
                ->unique()
                ->values(),
        ];
    }

    /**
     * The games behind these rows, for their names and kickoff times.
     *
     * One query for the page rather than one per row. The load test in
     * step 12 exists because of exactly this.
     *
     * @param  Collection<int, VlBoardEntry>  $entries
     * @return Collection<int, VlEvent>
     */
    private function eventsFor(Collection $entries): Collection
    {
        $ids = $entries->pluck('edge_lab_event_id')->filter()->unique();

        if ($ids->isEmpty()) {
            return collect();
        }

        return VlEvent::query()->current()
            ->whereIn('edge_lab_id', $ids)
            ->get()
            ->keyBy('edge_lab_id');
    }

    /**
     * Reads for the rows on this page, where they exist.
     *
     * Not claimed against a quota here: the board is a Pro page and Pro
     * has no view limit, so there is nothing to spend. A free customer
     * cannot reach this route at all.
     *
     * @param  Collection<int, VlBoardEntry>  $entries
     * @return Collection<int, VigilRead>
     */
    private function readsFor(Collection $entries): Collection
    {
        if ($entries->isEmpty()) {
            return collect();
        }

        return VigilRead::query()
            ->where('subject_type', 'board_entry')
            ->whereIn('subject_id', $entries->pluck('id'))
            ->currentVersion()
            ->get()
            ->keyBy('subject_id');
    }

    /**
     * What the gate says about each sport, so the board can explain
     * itself rather than just being empty.
     *
     * @return Collection<string, VlModel>
     */
    private function verdictsBySport(): Collection
    {
        return VlModel::query()->current()
            ->orderByDesc('trained_at')
            ->get()
            ->groupBy('sport')
            ->map(fn (Collection $models) => $models->first());
    }

    /**
     * Why candidates in this filtered set were declined, counted.
     *
     * The concrete answer to "so why is there nothing here", shown
     * beside the board rather than on a separate page.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<string, int>
     */
    private function passReasons(array $filters): Collection
    {
        return $this->query($filters)
            ->whereNotNull('pass_reason')
            ->get()
            ->groupBy('pass_reason')
            ->map->count()
            ->sortDesc();
    }

    /** @param  array<int, string>  $allowed */
    private function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}
