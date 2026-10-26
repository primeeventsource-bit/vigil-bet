<?php

declare(strict_types=1);

namespace App\Services\Record;

use App\Models\PublishedPick;
use App\Models\VlBoardEntry;
use App\Models\VlEvent;
use App\Models\VlModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Publishes today's picks to the public record, before kickoff.
 *
 * This is the one write in the product that cannot be taken back. §7
 * calls the record the most important trust asset, and what makes it one
 * is that a pick goes in before the game and never changes afterwards -
 * so the publisher has to be careful about three things:
 *
 *  - **The gate.** Only a pick from a proven market is published. The
 *    board is checked again here rather than trusted from upstream.
 *  - **The clock.** A pick published at or after kickoff is rejected by
 *    the database, and this refuses before it gets there so the refusal
 *    is loggable rather than an exception.
 *  - **Duplication.** One pick per selection, for ever. Keyed on the
 *    game and the market rather than on the board row that proposed it:
 *    the board is rescanned hourly and mints a fresh row for the same
 *    game each time, so a board-row key let the same pick back onto the
 *    record once an hour. Re-running must not pad the record, which is
 *    exactly how a track record stops being one.
 */
class PickPublisher
{
    /**
     * How close to kickoff a pick may still be published.
     *
     * Not zero. A pick published eight seconds before kickoff is
     * technically pre-kickoff and practically unplayable, and a record
     * built from those would be true and misleading at once.
     */
    public const MINIMUM_LEAD_MINUTES = 15;

    /** @return array{published:int, skipped:array<string, int>} */
    public function run(): array
    {
        $candidates = VlBoardEntry::query()->current()->sellablePicks()->get();

        $published = 0;
        $skipped = ['already_published' => 0, 'no_event' => 0, 'too_late' => 0, 'no_price' => 0];

        foreach ($candidates as $entry) {
            $outcome = $this->publish($entry);

            if ($outcome === null) {
                $published++;

                continue;
            }

            $skipped[$outcome] = ($skipped[$outcome] ?? 0) + 1;
        }

        return ['published' => $published, 'skipped' => $skipped];
    }

    /** Returns null on success, or the reason it was skipped. */
    public function publish(VlBoardEntry $entry): ?string
    {
        if ($this->alreadyPublished($entry)) {
            return 'already_published';
        }

        if ($entry->best_price_american === null) {
            // A record entry with no price is not a claim anybody can
            // check, which makes it worse than no entry.
            return 'no_price';
        }

        $event = $entry->edge_lab_event_id === null
            ? null
            : VlEvent::query()->current()->forEdgeLabId((int) $entry->edge_lab_event_id)->first();

        if ($event?->starts_at === null) {
            return 'no_event';
        }

        if ($event->starts_at->isBefore(now()->addMinutes(self::MINIMUM_LEAD_MINUTES))) {
            /*
             * Either the game has started or it is about to. Logged
             * rather than thrown, because this is a normal outcome - the
             * board is scanned hourly and some games are always close.
             */
            Log::info('Pick not published: too close to kickoff', [
                'board_entry_id' => $entry->id,
                'kickoff' => $event->starts_at->toIso8601String(),
            ]);

            return 'too_late';
        }

        $model = VlModel::query()->current()
            ->where('sport', $entry->sport)
            ->where('market', $entry->market)
            ->orderByDesc('trained_at')
            ->first();

        try {
            // Nested, so a duplicate cannot abort a surrounding
            // transaction and take the rest of the batch with it.
            DB::transaction(fn () => PublishedPick::create([
                'board_entry_id' => $entry->id,
                'edge_lab_event_id' => $entry->edge_lab_event_id,
                'sport' => $entry->sport,
                'market' => $entry->market,
                'side' => $entry->side,
                'line' => $entry->line,
                'selection' => $entry->selection,
                'matchup' => $event->matchup(),
                // The price as it was. Looking it up later would turn the
                // record into a claim about a price nobody was offered.
                'price_american' => (int) $entry->best_price_american,
                'book' => $entry->best_book,
                'book_title' => $entry->best_book_title,
                'model_prob' => $entry->model_prob,
                'fair_prob' => $entry->fair_prob,
                'edge_at_price' => $entry->edge_at_price,
                'stake_fraction' => $entry->kelly_fraction,
                'model_version' => $model?->version,
                'kickoff_at' => $event->starts_at,
                'published_at' => now(),
                'payload' => $entry->payload,
                'created_at' => now(),
            ]));
        } catch (QueryException $e) {
            /*
             * The database refusing a selection it already holds. The
             * check at the top of this method catches the ordinary case;
             * this catches the one it cannot - two publishers passing
             * that check in the same instant, both believing they are
             * first. Losing that race is a normal outcome, not a failure.
             */
            if (! $this->refusedAsDuplicate($e)) {
                throw $e;
            }

            return 'already_published';
        }

        return null;
    }

    private function refusedAsDuplicate(QueryException $e): bool
    {
        $message = mb_strtolower($e->getMessage());

        return str_contains($message, 'already published')
            || str_contains($message, 'unique');
    }

    /**
     * Whether this selection is already on the record.
     *
     * The game, the market and the exact line - not the board entry id.
     * A board entry is one observation of a price; the claim being
     * published is "we like this side of this game", and that can only
     * honestly appear once however many times the board is rescanned.
     */
    private function alreadyPublished(VlBoardEntry $entry): bool
    {
        $query = PublishedPick::query()
            ->where('edge_lab_event_id', $entry->edge_lab_event_id)
            ->where('sport', $entry->sport)
            ->where('market', $entry->market);

        // Null is a real value here - a moneyline has no line - and
        // `where($column, null)` matches nothing at all in SQL.
        foreach (['side' => $entry->side, 'line' => $entry->line] as $column => $value) {
            $query = $value === null
                ? $query->whereNull($column)
                : $query->where($column, $value);
        }

        return $query->exists();
    }
}
