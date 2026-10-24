<?php

declare(strict_types=1);

namespace App\Services\Modeling;

use App\Data\BacktestGame;
use App\Enums\EventStatus;
use App\Enums\Market;
use App\Enums\SeasonType;
use App\Enums\Side;
use App\Enums\Sport;
use App\Models\Event;
use App\Models\EventLineMarker;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Streams completed games out of the database in the order they were played,
 * each carrying the closing market it was priced at.
 *
 * Two things matter here beyond the obvious.
 *
 * Memory: a five season backfill is tens of thousands of games, so this is a
 * generator over a lazy chunked query rather than a collection. Nothing ever
 * holds a whole sport in memory.
 *
 * Ordering: strictly by kickoff. The walk-forward runner refuses games that
 * arrive out of order, and ordering by id instead of start time would
 * silently feed it a shuffled season whenever rows were inserted out of
 * sequence - which backfilling by season does constantly.
 */
class TrainingSetBuilder
{
    /**
     * Books to take a closing line from, best first.
     *
     * One book per game, never an average across books. Averaging implied
     * probabilities blends away the overround and can produce a market that
     * sums under 100 percent - a benchmark no book ever offered, and one
     * that makes the market look sharper than any real price. A single
     * book's close is a number that genuinely existed.
     *
     * @var array<int, string>
     */
    private const BENCHMARK_BOOKS = ['hardrockbet', 'draftkings', 'betmgm', 'fanduel', 'historical_close'];

    /**
     * @return Generator<int, BacktestGame>
     */
    public function games(
        Sport $sport,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        Market $market = Market::Moneyline,
    ): Generator {
        $query = $this->competitive(Event::query())
            ->where('sport', $sport->value)
            ->where('status', EventStatus::Final->value)
            ->whereNotNull('final_home_score')
            ->whereNotNull('final_away_score')
            ->when($from, fn ($q) => $q->where('starts_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('starts_at', '<=', $to))
            ->orderBy('starts_at')
            ->orderBy('id');

        foreach ($query->lazy(500) as $event) {
            yield new BacktestGame(
                eventId: $event->id,
                sport: $sport,
                homeTeamId: $event->home_team_id,
                awayTeamId: $event->away_team_id,
                startsAt: CarbonImmutable::parse($event->starts_at),
                homeScore: (int) $event->final_home_score,
                awayScore: (int) $event->final_away_score,
                neutralSite: (bool) $event->neutral_site,
                season: $event->season,
                homeRestDays: $event->home_rest_days !== null ? (int) $event->home_rest_days : null,
                awayRestDays: $event->away_rest_days !== null ? (int) $event->away_rest_days : null,
                roof: $event->roof,
                surface: $event->surface,
                divisional: $event->divisional !== null ? (bool) $event->divisional : null,
                closingMarket: $this->closingMarket($event->id, $market),
                closingLine: $this->closingLine($event->id, $market),
            );
        }
    }

    /**
     * Preseason is excluded from everything this class returns.
     *
     * The backfill asks its sources for the regular season and two of them
     * hand back preseason anyway - 313 NHL games, a quarter of that
     * league's history here, and 211 NBA ones. A preseason result is a
     * statement about who rested their starters, so a rating that learns
     * from it is learning the wrong thing about team strength, and it does
     * so in proportion to how much of the data it is.
     *
     * Postseason stays. Those are the most competitive games played and
     * excluding them would throw away real signal. Rows with no season
     * type recorded also stay: unknown is not the same as preseason, and
     * dropping them would silently lose whole sports whose feed does not
     * state it.
     */
    private function competitive(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('season_type')
            ->orWhere('season_type', '!=', SeasonType::Preseason->value));
    }

    public function count(Sport $sport, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): int
    {
        return $this->competitive(Event::query())
            ->where('sport', $sport->value)
            ->where('status', EventStatus::Final->value)
            ->whereNotNull('final_home_score')
            ->when($from, fn ($q) => $q->where('starts_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('starts_at', '<=', $to))
            ->count();
    }

    /**
     * The line the home side was asked to cover, as that market closed.
     *
     * Taken from the home row, which is the side this app signs its
     * spreads from: negative when the home side is favoured. Books agree
     * on the line far more than they agree on the price, so unlike
     * closingMarket() this does not need a preference order - the first
     * one that stated a line will do.
     */
    private function closingLine(int $eventId, Market $market): ?float
    {
        if (! $market->hasLine()) {
            return null;
        }

        $line = EventLineMarker::query()
            ->where('event_id', $eventId)
            ->where('market', $market->value)
            ->where('side', Side::Home->value)
            ->whereNotNull('line_close')
            ->value('line_close');

        return $line === null ? null : (float) $line;
    }

    /**
     * The closing prices for one event, from the single best available book.
     *
     * @return array<string, int>
     */
    private function closingMarket(int $eventId, Market $market): array
    {
        $markers = EventLineMarker::query()
            ->where('event_id', $eventId)
            ->where('market', $market->value)
            ->whereNotNull('price_close_american')
            ->with('book:id,key')
            ->get();

        if ($markers->isEmpty()) {
            return [];
        }

        $byBook = [];

        foreach ($markers as $marker) {
            $key = $marker->book?->key;

            if ($key === null) {
                continue;
            }

            $byBook[$key][$marker->side->value] = (int) $marker->price_close_american;
        }

        foreach (self::BENCHMARK_BOOKS as $preferred) {
            if (isset($byBook[$preferred]) && count($byBook[$preferred]) >= 2) {
                return $byBook[$preferred];
            }
        }

        // No preferred book had both sides; take any book that did.
        foreach ($byBook as $sides) {
            if (count($sides) >= 2) {
                return $sides;
            }
        }

        return [];
    }
}
