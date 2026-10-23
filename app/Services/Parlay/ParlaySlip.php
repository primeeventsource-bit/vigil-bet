<?php

declare(strict_types=1);

namespace App\Services\Parlay;

use App\Models\VlBoardEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

/**
 * The slip a customer is building.
 *
 * Held in the session rather than the database on purpose: a slip being
 * assembled is not a decision, and writing every half-built combination
 * to a table would bury the parlays somebody actually weighed under the
 * ones they were idly poking at. Only a *grade* is stored.
 *
 * Built from board entries, which is what §8 asks for. A leg has to be
 * something the engine has a model for, otherwise the judge has nothing
 * to judge.
 */
class ParlaySlip
{
    private const KEY = 'parlay.slip';

    public const MAX_LEGS = 8;

    /** @return array<int, int> board entry ids, in the order added */
    public function ids(): array
    {
        return array_values(array_unique(array_map('intval', (array) Session::get(self::KEY, []))));
    }

    /** @return Collection<int, VlBoardEntry> */
    public function entries(): Collection
    {
        $ids = $this->ids();

        if ($ids === []) {
            return collect();
        }

        $entries = VlBoardEntry::query()->whereIn('id', $ids)->get()->keyBy('id');

        // Returned in the order the customer added them, not the order
        // the database felt like.
        return collect($ids)->map(fn (int $id) => $entries->get($id))->filter()->values();
    }

    public function count(): int
    {
        return count($this->ids());
    }

    /**
     * Add a leg.
     *
     * Returns a refusal sentence, or null on success. Three refusals,
     * all of them things a customer would otherwise discover from a
     * grade that made no sense:
     *
     *  - the same selection twice, which is not a parlay
     *  - two legs on the same game and market, which is a contradiction
     *    rather than a correlation
     *  - more legs than the judge is useful for
     */
    public function add(VlBoardEntry $entry): ?string
    {
        $ids = $this->ids();

        if (in_array($entry->id, $ids, true)) {
            return 'That selection is already on the slip.';
        }

        if (count($ids) >= self::MAX_LEGS) {
            return 'A slip holds '.self::MAX_LEGS.' legs. Beyond that the hold swamps any edge.';
        }

        $clash = $this->entries()->first(
            fn (VlBoardEntry $leg) => $leg->edge_lab_event_id === $entry->edge_lab_event_id
                && $leg->market === $entry->market,
        );

        if ($clash !== null) {
            return 'You already have '.$clash->selection.' in that market. Two sides of one market cannot both win.';
        }

        Session::put(self::KEY, array_merge($ids, [$entry->id]));

        return null;
    }

    public function remove(int $id): void
    {
        Session::put(self::KEY, array_values(array_diff($this->ids(), [$id])));
    }

    public function clear(): void
    {
        Session::forget(self::KEY);
    }

    /**
     * The legs in the shape Edge Lab's judge expects.
     *
     * An entry with no event id is dropped rather than sent with a null:
     * the judge would reject the whole slip and the customer would be
     * told their parlay was unavailable rather than which leg was the
     * problem.
     *
     * @return array<int, array{event_id:int, market:string, side:string}>
     */
    public function legsForJudge(): array
    {
        return $this->entries()
            ->filter(fn (VlBoardEntry $e) => $e->edge_lab_event_id !== null && $e->market !== null && $e->side !== null)
            ->map(fn (VlBoardEntry $e) => [
                'event_id' => (int) $e->edge_lab_event_id,
                'market' => (string) $e->market,
                'side' => (string) $e->side,
            ])
            ->values()
            ->all();
    }
}
