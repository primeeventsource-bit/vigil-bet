<?php

declare(strict_types=1);

namespace App\Services\EdgeLab;

use App\Data\EdgeLab\Page;
use App\Enums\Market;
use App\Enums\Recommendation;
use App\Enums\Side;
use App\Enums\Sport;
use App\Models\Event;
use App\Models\InjuryReport;
use App\Models\ModelVersion;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
use App\Models\ValueBet;
use App\Services\Value\ParlayEvaluator;
use App\Support\Internal\Cursor;
use App\Support\Internal\Present;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The one way the product reads the engine.
 *
 * This used to be an HTTP client. The engine ran as a second deployment
 * with a private read-only API, and every board row crossed a network,
 * a bearer token and a circuit breaker to get here. The two halves now
 * share one database and one process, so the queries run directly and
 * the wire is gone - along with the breaker, the retries, the token and
 * the five failure modes that only existed because there was a network.
 *
 * What has deliberately not changed is the shape of what comes back.
 * Every row is still built by App\Support\Internal\Present, which is the
 * same code that built the HTTP payloads, so the mirror jobs that read
 * these rows needed no edit at all. Keeping that seam means the product's
 * read model is still fed by one narrow, named interface rather than by
 * queries scattered through controllers.
 *
 * The rule that survives intact, and matters most: nothing here
 * interprets a number. The edge, the verdict, the stake and the reason
 * are read off stored rows, never derived. Two places that both know how
 * to work out an edge is how a screen ends up disagreeing with the table
 * behind it, and that was true across a network and is true across a
 * function call.
 *
 * Read-only, still by construction. The one method that runs engine code
 * is the parlay judge, and it uses the evaluator's preview rather than
 * its evaluate, so a slip being rebuilt on every keystroke writes nothing.
 */
class EdgeLabClient
{
    public function __construct(private readonly ParlayEvaluator $evaluator) {}

    /**
     * Whether the engine should be read at all.
     *
     * A flag rather than a token now. It exists so a deployment can run
     * the product against an engine that has not been given its data yet
     * and have the screens say "not connected" instead of rendering a
     * confident, empty board.
     */
    public function isConfigured(): bool
    {
        return (bool) config('edgelab.enabled');
    }

    /**
     * One page of the value board: every evaluated candidate, pick and
     * pass alike. Passes are not filtered out - a board showing only
     * picks would imply everything else went unexamined, when in fact it
     * was examined and declined.
     *
     * @param  array<string, mixed>  $filters  sport, recommendation, scan
     */
    public function board(?int $since = null, ?int $limit = null, array $filters = []): Page
    {
        $sport = Sport::tryFrom(mb_strtolower((string) ($filters['sport'] ?? '')));
        $recommendation = Recommendation::tryFrom(mb_strtolower((string) ($filters['recommendation'] ?? '')));
        $scan = $filters['scan'] ?? null;

        return $this->page(
            ValueBet::query()
                ->with(['bestBook:id,key,title', 'event.homeTeam:id,display_name', 'event.awayTeam:id,display_name'])
                ->when($sport !== null, fn (Builder $q) => $q->where('sport', $sport->value))
                ->when($recommendation !== null, fn (Builder $q) => $q->where('recommendation', $recommendation->value))
                ->when($scan !== null && $scan !== '', fn (Builder $q) => $q->where('value_scan_id', (int) $scan)),
            fn (ValueBet $bet) => Present::boardEntry($bet),
            $since,
            $limit,
        );
    }

    /** @param  array<string, mixed>  $filters  sport, from, to */
    public function events(?int $since = null, ?int $limit = null, array $filters = []): Page
    {
        $sport = Sport::tryFrom(mb_strtolower((string) ($filters['sport'] ?? '')));

        return $this->page(
            Event::query()
                ->with(['homeTeam:id,display_name,abbreviation', 'awayTeam:id,display_name,abbreviation'])
                ->when($sport !== null, fn (Builder $q) => $q->where('sport', $sport->value))
                ->when(
                    filled($filters['from'] ?? null),
                    fn (Builder $q) => $q->where('starts_at', '>=', $filters['from'])
                )
                ->when(
                    filled($filters['to'] ?? null),
                    fn (Builder $q) => $q->where('starts_at', '<=', $filters['to'])
                ),
            fn (Event $event) => Present::event($event),
            $since,
            $limit,
        );
    }

    public function models(?int $since = null, ?int $limit = null): Page
    {
        return $this->page(
            ModelVersion::query(),
            fn (ModelVersion $version) => Present::model($version),
            $since,
            $limit,
        );
    }

    /**
     * One game, with the availability that was known before kickoff.
     *
     * @return array<string, mixed>
     */
    public function event(int $edgeLabEventId): array
    {
        $event = $this->findEvent($edgeLabEventId);

        /*
         * Only reports both filed and fetched before kickoff. The feed
         * publishes availability as it stands now, so a report dated in
         * February that was pulled in October is October's knowledge -
         * using it for a March game would be reading a list of the
         * injuries that turned out to last.
         */
        $injuries = InjuryReport::query()
            ->where('event_id', $event->id)
            ->when($event->starts_at !== null, fn (Builder $q) => $q->availableBefore($event->starts_at))
            ->orderByDesc('reported_at')
            ->limit(100)
            ->get();

        return Present::event($event) + [
            'injuries' => $injuries->map(fn (InjuryReport $r) => Present::injury($r))->all(),
            // Said once more at the top level, because a caller that reads
            // the list and not the rows still has to know.
            'injuries_modelled' => false,
        ];
    }

    /**
     * The latest quote per book for one game.
     *
     * One event at a time, and still only ever one event at a time. The
     * odds licence allows showing prices inside a paid interface and
     * forbids reselling or repackaging the feed, and the practical
     * difference between those is almost entirely whether a caller can
     * pull the whole slate in one go. There is no bulk odds method here,
     * by design rather than by omission, and a test asserts it.
     *
     * @return array<string, mixed>
     */
    public function odds(int $edgeLabEventId): array
    {
        $event = $this->findEvent($edgeLabEventId);

        $core = $this->latestPerSelection(
            OddsSnapshot::query()->where('event_id', $event->id)->coreMarkets()
        );

        $props = $this->latestPerSelection(
            OddsSnapshot::query()->where('event_id', $event->id)->props()
        );

        return [
            'event_id' => $event->id,
            'quotes' => $core->map(fn (OddsSnapshot $s) => Present::quote($s))->values()->all(),
            /*
             * Props are display and line shopping only. No model has been
             * measured on a prop market, so nothing here may be presented
             * as a pick.
             */
            'props' => $props->map(fn (OddsSnapshot $s) => Present::quote($s))->values()->all(),
            'props_modelled' => false,
            'latest_pulled_at' => $core->merge($props)->max('pulled_at')?->toIso8601String(),
        ];
    }

    /**
     * Every prediction ever made for this game, newest first.
     *
     * Not just the current one. A prediction is append-only and stamped
     * before kickoff precisely so the record of what was believed, and
     * when, survives being wrong - and showing only the last one would
     * quietly rewrite that record every time the model changed its mind.
     *
     * @return array<string, mixed>
     */
    public function predictions(int $edgeLabEventId): array
    {
        $event = $this->findEvent($edgeLabEventId);

        $predictions = Prediction::query()
            ->where('event_id', $event->id)
            ->with('modelVersion')
            ->orderByDesc('predicted_at')
            ->orderByDesc('id')
            ->limit($this->pageSize())
            ->get();

        return [
            'event_id' => $event->id,
            'predictions' => $predictions->map(fn (Prediction $p) => Present::prediction($p))->all(),
            'models' => $predictions
                ->pluck('modelVersion')
                ->filter()
                ->unique('id')
                ->map(fn (ModelVersion $version) => Present::model($version))
                ->values()
                ->all(),
        ];
    }

    /**
     * Run the engine's parlay judge over a slip and return its verdict.
     *
     * Stores nothing. It uses the evaluator's `preview` rather than its
     * `evaluate`, and the difference is deliberate: `evaluate` appends a
     * row to parlay_evaluations, which is right when the operator judges
     * a slip they actually considered and wrong for a customer's slip
     * being rebuilt on every keystroke. Writing those would bury the
     * parlays genuinely weighed under every intermediate state of
     * building one.
     *
     * Every number in the answer still comes from the judge. The two
     * absolute refusals - legs from one game, and an edge that does not
     * survive the shade - apply here exactly as they do on the operator's
     * own board.
     *
     * @param  array<int, array{event_id:int, market:string, side:string}>  $legs
     * @return array<string, mixed>
     */
    public function evaluateParlay(array $legs, ?float $shade = null): array
    {
        if (! $this->isConfigured()) {
            throw EdgeLabException::notConfigured();
        }

        $maxLegs = max(1, (int) config('edge.parlay.max_legs'));

        if (count($legs) > $maxLegs) {
            throw new EdgeLabException("A parlay may have at most {$maxLegs} legs.");
        }

        $clean = [];

        foreach (array_values($legs) as $i => $leg) {
            $market = Market::tryFrom(mb_strtolower((string) ($leg['market'] ?? '')));
            $side = Side::tryFrom(mb_strtolower((string) ($leg['side'] ?? '')));
            $eventId = (int) ($leg['event_id'] ?? 0);

            if ($market === null || $side === null || $eventId <= 0) {
                throw new EdgeLabException('Leg '.($i + 1).' is not a market and side the engine prices.');
            }

            if (! Event::query()->whereKey($eventId)->exists()) {
                throw new EdgeLabException('Leg '.($i + 1).' names a game the engine does not have.');
            }

            $clean[] = ['event_id' => $eventId, 'market' => $market->value, 'side' => $side->value];
        }

        try {
            $parlay = $this->evaluator->preview($clean, $shade);
        } catch (Throwable $e) {
            throw new EdgeLabException('The parlay judge could not price this slip: '.$e->getMessage());
        }

        return Present::parlay($parlay) + ['stored' => false];
    }

    /**
     * Keyset pagination over an append-only table, built into the same
     * envelope the HTTP API used so Page reads it unchanged.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): array<string, mixed>  $present
     */
    private function page(Builder $query, callable $present, ?int $since, ?int $limit): Page
    {
        $cursor = new Cursor(
            since: $since === null ? null : max(0, $since),
            limit: max(1, $limit ?? $this->pageSize()),
        );

        /** @var EloquentCollection<int, Model> $rows */
        $rows = $cursor->apply($query)->get();

        return Page::fromResponse([
            'data' => $rows->map($present)->all(),
            'meta' => $cursor->meta($rows, $rows->last()?->id),
        ], $since);
    }

    /**
     * Newest row per book and selection.
     *
     * Ordered oldest first and keyed as it goes, so the last write for a
     * key wins. Grouped on pulled_at rather than on the highest id,
     * because ids are not a proxy for recency here: a historical backfill
     * writes prices from last season long after the live pulls they
     * predate.
     *
     * @param  Builder<OddsSnapshot>  $query
     * @return Collection<string, OddsSnapshot>
     */
    private function latestPerSelection(Builder $query): Collection
    {
        return $query
            ->with('book:id,key,title')
            ->whereHas('book', fn ($q) => $q->where('is_tracked', true))
            ->orderBy('pulled_at')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (OddsSnapshot $s) => implode('|', [
                $s->book_id,
                $s->marketKey(),
                $s->side?->value,
                $s->player_name,
                $s->line,
            ]));
    }

    /**
     * A missing game is still an EdgeLabException, not a 404 turned into
     * one. Callers already handle it, and the thing they have to handle
     * has not changed: the engine does not have this game.
     */
    private function findEvent(int $id): Event
    {
        if (! $this->isConfigured()) {
            throw EdgeLabException::notConfigured();
        }

        $event = Event::query()
            ->with(['homeTeam:id,display_name,abbreviation', 'awayTeam:id,display_name,abbreviation'])
            ->find($id);

        if ($event === null) {
            throw new EdgeLabException("The engine has no game {$id}.", status: 404);
        }

        return $event;
    }

    private function pageSize(): int
    {
        return max(1, (int) config('edgelab.page_size'));
    }
}
