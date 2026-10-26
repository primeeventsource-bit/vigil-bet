<?php

declare(strict_types=1);

namespace App\Services\Record;

use App\Models\Opinion;
use App\Models\VlBoardEntry;
use Illuminate\Support\Collection;

/**
 * What the engine thinks now about something it published earlier.
 *
 * An opinion is written once and never rewritten, which is what makes
 * the record worth reading: one that could be corrected afterwards would
 * be corrected in the direction of looking right. But the row stays on
 * the card until kickoff, and the model behind it keeps scanning - so a
 * selection published on Saturday at 67% can be one the engine declines
 * at 34% by Monday, with the card still showing Saturday.
 *
 * That gap is not a bug in the record. It is a bug in showing the record
 * as though it were current. So the published number stays exactly as
 * published and the present view is carried alongside it, including the
 * word "pass" when that is what the engine now says.
 *
 * Nothing here recomputes anything. Both numbers are read from rows the
 * engine wrote; this only puts them next to each other.
 */
class OpinionStanding
{
    /**
     * The current view on each live opinion, keyed by opinion id.
     *
     * @param  Collection<int, Opinion>  $opinions
     * @return Collection<int, array{current: ?float, held: bool, pass_reason: ?string, drifted: bool, likely: ?array{selection: string, prob: float, price: ?int, backable: bool}}>
     */
    public function for(Collection $opinions): Collection
    {
        if ($opinions->isEmpty()) {
            return collect();
        }

        $entries = $this->entriesFor($opinions);
        $standing = collect();

        foreach ($opinions as $opinion) {
            $forEvent = $entries->get($opinion->event_id.'|'.$opinion->market, collect());

            if ($forEvent->isEmpty()) {
                continue;
            }

            $mine = $forEvent->first(fn (VlBoardEntry $e) => $this->isSameSelection($e, $opinion));
            $best = $forEvent->sortByDesc('model_prob')->first();

            $current = $mine?->model_prob === null ? null : (float) $mine->model_prob;

            $standing->put($opinion->id, [
                'current' => $current,
                // Held means the engine would still take it today.
                'held' => $mine?->recommendation === 'bet',
                'pass_reason' => $mine?->pass_reason,
                'drifted' => $current !== null && abs($current - (float) $opinion->model_prob) >= $this->driftThreshold(),
                'likely' => $this->likely($best),
            ]);
        }

        return $standing;
    }

    /**
     * The side the model makes favourite, and whether it is backable.
     *
     * Shown because "most likely to win" and "worth betting" are
     * different sentences and the card only ever said the second. A
     * favourite the engine passes on is still the honest answer to what
     * it expects to happen, and saying nothing reads as having no view.
     *
     * @return array{selection: string, prob: float, price: ?int, backable: bool}|null
     */
    private function likely(?VlBoardEntry $best): ?array
    {
        if ($best === null || $best->model_prob === null) {
            return null;
        }

        return [
            'selection' => (string) $best->selection,
            'prob' => (float) $best->model_prob,
            'price' => $best->best_price_american === null ? null : (int) $best->best_price_american,
            'backable' => $best->recommendation === 'bet',
        ];
    }

    /**
     * Current board entries for every game an opinion is live on.
     *
     * One query for the card rather than one per row, and grouped on the
     * same key the opinion carries so the match is an array lookup
     * rather than a second pass over the table.
     *
     * @param  Collection<int, Opinion>  $opinions
     * @return Collection<string, Collection<int, VlBoardEntry>>
     */
    private function entriesFor(Collection $opinions): Collection
    {
        return VlBoardEntry::query()
            ->current()
            ->latestPerSelection()
            ->whereIn('edge_lab_event_id', $opinions->pluck('event_id')->filter()->unique()->all())
            ->whereIn('market', $opinions->pluck('market')->filter()->unique()->all())
            ->get()
            ->groupBy(fn (VlBoardEntry $entry) => $entry->edge_lab_event_id.'|'.$entry->market);
    }

    /** The same bet: side and line as well as the game and the market. */
    private function isSameSelection(VlBoardEntry $entry, Opinion $opinion): bool
    {
        if ((string) $entry->side !== (string) $opinion->side) {
            return false;
        }

        if ($entry->line === null || $opinion->line === null) {
            return $entry->line === null && $opinion->line === null;
        }

        return abs((float) $entry->line - (float) $opinion->line) < 0.001;
    }

    /** How far the number must move before the card says so. */
    private function driftThreshold(): float
    {
        return (float) config('vigil.opinion_drift_threshold', 0.02);
    }
}
