<?php

declare(strict_types=1);

namespace App\Services\Odds;

use App\Models\Event;
use App\Services\Parlay\ParlaySlip;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Upcoming games and the markets a customer can actually click.
 *
 * Built from odds snapshots rather than from the engine's board, which is
 * the whole point. The board is a record of what the engine decided and
 * why; it refused all 24,610 candidates it had evaluated, 18,422 of them
 * for having no price it would act on. A customer wanting to record their
 * own bet on tonight's game does not care about any of that.
 *
 * So this asks a narrower question with an answer: which games are still
 * ahead of us, and what are the books offering on them. A market with no
 * price simply does not appear, which turns the engine's largest
 * rejection reason into a non-event here instead of a problem to solve.
 */
class GameMarkets
{
    /** How stale a price may be before it is not worth showing. */
    private const FRESH_HOURS = 24;

    /** The markets this product can price and settle. */
    public const MARKETS = ['h2h', 'spreads', 'totals'];

    /**
     * Games starting inside the window, with their markets.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function upcoming(?string $sport = null, int $hours = 72): Collection
    {
        $events = Event::query()
            ->with(['homeTeam:id,display_name,abbreviation', 'awayTeam:id,display_name,abbreviation'])
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<', now()->addHours($hours))
            ->when($sport !== null, fn ($query) => $query->where('sport', $sport))
            ->orderBy('starts_at')
            ->get();

        if ($events->isEmpty()) {
            return collect();
        }

        $prices = $this->pricesFor($events->pluck('id')->all());

        /*
         * A game with no price is dropped rather than shown as an empty
         * row. A fixture you cannot click is indistinguishable from a
         * fixture that is broken, and the page already has to explain
         * one absence without inventing a second.
         */
        return $events
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'sport' => (string) $event->sport->value,
                'starts_at' => $event->starts_at,
                'home' => $event->homeTeam?->display_name ?? 'Home',
                'away' => $event->awayTeam?->display_name ?? 'Away',
                'markets' => $prices[$event->id] ?? [],
            ])
            ->filter(static fn (array $game): bool => $game['markets'] !== [])
            ->values();
    }

    /** The sports that have anything to show, for the filter row. */
    public function sportsWithGames(int $hours = 72): Collection
    {
        return $this->upcoming(null, $hours)->pluck('sport')->unique()->sort()->values();
    }

    /**
     * One selection, rebuilt from our own data.
     *
     * The page posts an event, a market, a side and a line - an
     * identifier - and never a price or a label. Both are read back here
     * from the snapshot table, because a form field is something the
     * customer controls: a posted price would let anybody record a parlay
     * at +50000 and a posted label would let them write whatever they
     * liked into their own record.
     *
     * Returns null when the market is no longer on offer, which the
     * caller reports rather than swallowing. A line that moved between
     * the page loading and the click is the ordinary case, not an error.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(int $eventId, string $market, ?string $side, float|string|null $line): ?array
    {
        if (! in_array($market, self::MARKETS, true)) {
            return null;
        }

        $event = Event::query()
            ->with(['homeTeam:id,display_name', 'awayTeam:id,display_name'])
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->find($eventId);

        if ($event === null) {
            return null;
        }

        $quotes = DB::table('odds_snapshots as o')
            ->join('books as b', 'b.id', '=', 'o.book_id')
            ->where('o.event_id', $eventId)
            ->where('o.market', $market)
            ->where('o.side', $side)
            ->where('o.pulled_at', '>', now()->subHours(self::FRESH_HOURS))
            ->when(
                $line === null || $line === '',
                fn ($query) => $query->whereNull('o.line'),
                fn ($query) => $query->where('o.line', (float) $line),
            )
            ->orderByDesc('o.price_american')
            ->first(['o.line', 'o.price_american', 'b.key as book', 'b.title as book_title']);

        if ($quotes === null) {
            return null;
        }

        /*
         * The engine's own view of this selection, where it has one.
         * Attached rather than required: it is what lets the saved parlay
         * say whether VIGIL agreed, and its absence is the normal case.
         */
        $entry = DB::table('vl_board_entries')
            ->where('edge_lab_event_id', $eventId)
            ->where('market', $market)
            ->where('side', $side)
            ->orderByDesc('id')
            ->first(['id', 'model_prob', 'edge_at_price', 'edge', 'recommendation']);

        return [
            'key' => ParlaySlip::keyFor($eventId, $market, $side, $quotes->line),
            'event_id' => $eventId,
            'sport' => (string) $event->sport->value,
            'market' => $market,
            'side' => $side,
            'line' => $quotes->line === null ? null : (float) $quotes->line,
            'price_american' => (int) $quotes->price_american,
            'book' => (string) $quotes->book,
            'book_title' => (string) $quotes->book_title,
            'selection' => self::label($event, $market, $side, $quotes->line),
            'starts_at' => $event->starts_at->toIso8601String(),
            'matchup' => ($event->awayTeam?->display_name ?? 'Away').' at '.($event->homeTeam?->display_name ?? 'Home'),
            'board_entry_id' => $entry->id ?? null,
            'model_probability' => $entry->model_prob ?? null,
            'model_edge' => $entry->edge_at_price ?? $entry->edge ?? null,
            'model_agreement' => match ($entry->recommendation ?? null) {
                'bet' => 'agreed',
                'pass' => 'passed',
                default => 'no_prediction',
            },
        ];
    }

    /**
     * What to call a selection, in the words a bettor uses.
     *
     * "Passed" on a spread is the team and the number; on a total it is
     * the direction and the number, with no team in it at all - which is
     * why this cannot be one format string.
     */
    public static function label(Event $event, string $market, ?string $side, float|string|null $line): string
    {
        $home = $event->homeTeam?->display_name ?? 'Home';
        $away = $event->awayTeam?->display_name ?? 'Away';
        $team = $side === 'home' ? $home : $away;
        $number = $line === null || $line === '' ? null : (float) $line;

        return match ($market) {
            'h2h' => $team,
            'spreads' => $number === null
                ? $team
                : $team.' '.($number > 0 ? '+' : '').rtrim(rtrim(number_format($number, 1, '.', ''), '0'), '.'),
            'totals' => ($side === 'over' ? 'Over ' : 'Under ')
                .($number === null ? '' : rtrim(rtrim(number_format($number, 1, '.', ''), '0'), '.'))
                .' · '.$away.' at '.$home,
            default => $team,
        };
    }

    /**
     * Best available price per market and side, by event.
     *
     * Two reductions, in order. First the latest quote from each book,
     * because a snapshot table holds every pull and the one from this
     * morning is not an offer any more. Then, per side, the line the most
     * books agree on and the best price at that line - a bettor would not
     * thank us for the best price in the market attached to a line only
     * one book is offering.
     *
     * @param  array<int, int>  $eventIds
     * @return array<int, array<string, array<int, array<string, mixed>>>>
     */
    private function pricesFor(array $eventIds): array
    {
        $rows = DB::table('odds_snapshots as o')
            ->join('books as b', 'b.id', '=', 'o.book_id')
            ->whereIn('o.event_id', $eventIds)
            ->whereIn('o.market', self::MARKETS)
            ->where('o.pulled_at', '>', now()->subHours(self::FRESH_HOURS))
            ->orderBy('o.pulled_at')
            ->get([
                'o.event_id', 'o.market', 'o.side', 'o.line', 'o.price_american',
                'b.key as book', 'b.title as book_title', 'o.pulled_at',
            ]);

        // Latest per book, per selection. Ordered ascending above, so the
        // last write for a key wins.
        $latest = [];

        foreach ($rows as $row) {
            $latest[$row->event_id.'|'.$row->market.'|'.$row->side.'|'.($row->line ?? '').'|'.$row->book] = $row;
        }

        $byEvent = [];

        foreach ($latest as $row) {
            $byEvent[(int) $row->event_id][(string) $row->market][(string) $row->side][(string) ($row->line ?? '')][] = $row;
        }

        $out = [];

        foreach ($byEvent as $eventId => $markets) {
            foreach ($markets as $market => $sides) {
                foreach ($sides as $side => $lines) {
                    $best = $this->bestAtConsensusLine($lines);

                    if ($best === null) {
                        continue;
                    }

                    $out[$eventId][$market][] = [
                        'key' => ParlaySlip::keyFor((int) $eventId, (string) $market, (string) $side, $best->line),
                        'event_id' => (int) $eventId,
                        'market' => (string) $market,
                        'side' => (string) $side,
                        'line' => $best->line === null ? null : (float) $best->line,
                        'price_american' => (int) $best->price_american,
                        'book' => (string) $best->book,
                        'book_title' => (string) $best->book_title,
                        'books' => count($lines[(string) ($best->line ?? '')]),
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * The best price at the line the most books are offering.
     *
     * @param  array<string, array<int, object>>  $lines
     */
    private function bestAtConsensusLine(array $lines): ?object
    {
        if ($lines === []) {
            return null;
        }

        /*
         * Most books first, and on a tie the line nearest zero - which
         * for a spread is the one closest to a pick'em and for a total is
         * meaningless, so the tie-break only has to be stable rather
         * than clever.
         */
        $consensus = null;
        $consensusCount = -1;

        foreach ($lines as $line => $quotes) {
            if (count($quotes) > $consensusCount) {
                $consensusCount = count($quotes);
                $consensus = $line;
            }
        }

        $quotes = $lines[$consensus] ?? [];

        if ($quotes === []) {
            return null;
        }

        // Best for the bettor is the highest American price, which is
        // true on both sides of zero: +150 beats +120, and -150 beats
        // -200.
        usort($quotes, static fn ($a, $b): int => (int) $b->price_american <=> (int) $a->price_american);

        return $quotes[0];
    }
}
