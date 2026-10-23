<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\EventWatch;
use App\Models\PublishedPick;
use App\Models\VlEvent;
use App\Models\VlOddsDisplay;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Finds the things worth telling somebody about and records them once.
 *
 * Raising and delivering are separate on purpose. An alert is a fact -
 * this pick was published, this line moved - and it is the same fact for
 * everybody. Who hears about it is a different question, answered later
 * against each customer's preferences. Mixing the two would mean
 * re-deciding what happened once per customer, and getting a different
 * answer for the second one.
 *
 * Every alert is unique on (type, subject), enforced by the database. The
 * raiser runs hourly and a pick that is still on the board must not
 * produce a fresh alert every time it is seen - the failure mode here is
 * not a missing notification, it is the same push eleven times.
 */
class AlertRaiser
{
    /** @return array{picks:int, moves:int} */
    public function run(): array
    {
        return [
            'picks' => $this->newPicks(),
            'moves' => $this->lineMoves(),
        ];
    }

    /**
     * A pick has been published.
     *
     * Read from `published_picks` rather than from the board, because
     * the public record is the thing the product stands behind. A pick
     * that was never published is not news.
     */
    public function newPicks(): int
    {
        $raised = 0;

        $picks = PublishedPick::query()
            // Nothing about a game that has already started: an alert a
            // customer cannot act on is a notification for nothing.
            ->where('kickoff_at', '>', now())
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('alerts')
                    ->whereColumn('alerts.subject_id', 'published_picks.id')
                    ->where('alerts.subject_type', 'published_pick')
                    ->where('alerts.type', Alert::PICK_POSTED);
            })
            ->get();

        foreach ($picks as $pick) {
            $raised += $this->raise(Alert::PICK_POSTED, 'published_pick', $pick->id, [
                'sport' => $pick->sport,
                'market' => $pick->market,
                'selection' => $pick->selection,
                'matchup' => $pick->matchup,
                'price' => $pick->priceWritten(),
                'book' => $pick->book_title ?? $pick->book,
                'model_probability' => $pick->model_prob === null ? null : round($pick->model_prob * 100).'%',
                'kickoff' => $pick->kickoff_at->toIso8601String(),
                'url' => route('record.pick', $pick),
            ], $pick->published_at, $pick->sport, $pick->market) ? 1 : 0;
        }

        return $raised;
    }

    /**
     * A line moved on a game somebody is watching.
     *
     * Only watched games. Not because of the cost, but because a product
     * that notifies about every price wobble in every league is a product
     * people turn off - and because the display mirror only holds games
     * somebody has opened anyway.
     */
    public function lineMoves(): int
    {
        $watched = EventWatch::query()->distinct()->pluck('edge_lab_event_id');

        if ($watched->isEmpty()) {
            return 0;
        }

        $raised = 0;

        $quotes = VlOddsDisplay::query()
            ->whereIn('edge_lab_event_id', $watched)
            ->coreMarkets()
            ->whereNotNull('price_american')
            ->where('quoted_at', '>=', now()->subDay())
            ->orderBy('quoted_at')
            ->get()
            ->groupBy(fn (VlOddsDisplay $q) => implode('|', [
                $q->edge_lab_event_id, $q->market, $q->side, $q->book,
            ]));

        $events = VlEvent::query()->current()
            ->whereIn('edge_lab_id', $watched)
            ->get()
            ->keyBy('edge_lab_id');

        foreach ($quotes as $group) {
            $move = $this->describeMove($group, $events);

            if ($move === null) {
                continue;
            }

            /*
             * Keyed on the newest quote rather than on the game, so a
             * second move later in the day is a second alert - and the
             * same move seen twice is not.
             */
            $raised += $this->raise(
                Alert::LINE_MOVED,
                'odds_quote',
                $move['quote_id'],
                $move['payload'],
                $move['occurred_at'],
                $move['sport'],
                $move['market'],
            ) ? 1 : 0;
        }

        return $raised;
    }

    /**
     * Whether this book's prices moved enough to be worth saying, and
     * what to say.
     *
     * The threshold is the largest any customer asked for, so one scan
     * serves everybody; the dispatcher applies each customer's own
     * threshold afterwards. Scanning per customer would re-ask the same
     * question once per subscriber.
     *
     * @param  Collection<int, VlOddsDisplay>  $group
     * @param  Collection<int, VlEvent>  $events
     * @return array<string, mixed>|null
     */
    private function describeMove(Collection $group, Collection $events): ?array
    {
        if ($group->count() < 2) {
            return null;
        }

        $first = $group->first();
        $last = $group->last();

        $moved = (int) $last->price_american - (int) $first->price_american;

        // The floor below which nothing is ever raised. Anything smaller
        // is noise even for a customer who asked for everything.
        if (abs($moved) < 5) {
            return null;
        }

        $event = $events->get($last->edge_lab_event_id);

        if ($event === null || $event->starts_at === null || $event->starts_at->isPast()) {
            // A move on a game that has started is not actionable.
            return null;
        }

        $write = static fn (int $price): string => ($price > 0 ? '+' : '').$price;

        return [
            'quote_id' => $last->id,
            'sport' => $event->sport,
            'market' => $last->market,
            'occurred_at' => $last->quoted_at,
            'payload' => [
                'sport' => $event->sport,
                'market' => $last->market,
                'side' => $last->side,
                'matchup' => $event->matchup(),
                'book' => $last->book_title ?? $last->book,
                'from' => $write((int) $first->price_american),
                'to' => $write((int) $last->price_american),
                // The size of the move, so the dispatcher can apply each
                // customer's own threshold without re-reading quotes.
                'moved_by' => abs($moved),
                'direction' => $moved > 0 ? 'drifted out' : 'shortened',
                'kickoff' => $event->starts_at->toIso8601String(),
                'url' => route('live-odds'),
            ],
        ];
    }

    /**
     * Record the alert, or do nothing if it already exists.
     *
     * The unique key is the deduplication. Catching its violation rather
     * than checking first means two raisers running at once cannot both
     * decide the alert is new.
     *
     * @param  array<string, mixed>  $payload
     */
    private function raise(
        string $type,
        string $subjectType,
        int $subjectId,
        array $payload,
        mixed $occurredAt,
        ?string $sport,
        ?string $market,
    ): bool {
        try {
            Alert::create([
                'type' => $type,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'sport' => $sport,
                'market' => $market,
                'payload' => $payload,
                'occurred_at' => $occurredAt ?? now(),
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'unique')) {
                throw $e;
            }

            return false;
        }

        return true;
    }
}
