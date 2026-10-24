<?php

declare(strict_types=1);

namespace App\Services\Odds;

use App\Data\EventOdds;
use App\Data\EventProps;
use App\Data\HistoricalOddsSnapshot;
use App\Data\OddsQuote;
use App\Data\PropQuote;
use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use App\Services\Ops\ApiMeter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for The Odds API v4.
 *
 * One call returns every book, market and side for a sport, which is why a
 * pull costs one request per sport rather than one per game. Quota headers
 * are recorded on every call so the budget is always visible.
 *
 * Three endpoints, three prices, and the difference matters because they
 * share one monthly allowance:
 *
 *   /sports/{sport}/odds              1 credit per market per region
 *   /sports/{sport}/events/{id}/odds  1 credit per market per region
 *                                     returned, free when nothing comes
 *                                     back, but one call per game rather
 *                                     than one per league
 *   /historical/sports/{sport}/odds   10 credits per market per region
 *
 * Every call records what the provider says it cost, so the budget is
 * counted in credits rather than in calls.
 */
class TheOddsApiClient
{
    public const PROVIDER = 'the_odds_api';

    public function __construct(private readonly ApiMeter $meter) {}

    public function isConfigured(): bool
    {
        return filled(config('edge.odds_api.key'));
    }

    public function hasBudget(): bool
    {
        return $this->meter->hasBudget(
            self::PROVIDER,
            (int) config('edge.odds_api.monthly_quota'),
            (int) config('edge.odds_api.quota_reserve_pct'),
        );
    }

    /**
     * Current odds for every upcoming game in a sport.
     *
     * @return array<int, EventOdds>
     */
    public function oddsForSport(Sport $sport, ?int $jobRunId = null): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('ODDS_API_KEY is not set.');
        }

        $markets = implode(',', array_map(static fn (Market $m) => $m->value, $sport->markets()));
        $endpoint = "sports/{$sport->oddsApiKey()}/odds";

        $started = microtime(true) * 1000;
        $response = null;

        try {
            $response = $this->http()->get($this->url($endpoint), [
                'apiKey' => config('edge.odds_api.key'),
                'regions' => implode(',', (array) config('edge.odds_api.regions')),
                'markets' => $markets,
                'oddsFormat' => 'american',
                'dateFormat' => 'iso',
            ])->throw();

            $events = $this->parseOddsPayload($sport, $response->json() ?? []);

            $this->meter->record(
                self::PROVIDER,
                $endpoint,
                $response,
                $started,
                $jobRunId,
                array_sum(array_map(static fn (EventOdds $e) => $e->quoteCount(), $events)),
            );

            return $events;
        } catch (RequestException $e) {
            $this->meter->record(
                self::PROVIDER,
                $endpoint,
                $e->response,
                $started,
                $jobRunId,
                0,
                $e->getMessage(),
            );

            throw $e;
        }
    }

    /**
     * Player props for one game.
     *
     * Props live on the per-event endpoint and nowhere else, so this costs
     * a call per game. The book decides what it has posted: asking for four
     * markets and getting two back costs two, and getting nothing back
     * costs nothing. That is also why the markets wanted are passed in
     * rather than read here - the caller knows which ones are worth it.
     *
     * @param  array<int, string>  $marketKeys
     */
    public function propsForEvent(
        Sport $sport,
        string $oddsApiEventId,
        array $marketKeys,
        ?int $jobRunId = null,
    ): EventProps {
        if (! $this->isConfigured()) {
            throw new RuntimeException('ODDS_API_KEY is not set.');
        }

        if ($marketKeys === []) {
            return new EventProps($sport, $oddsApiEventId, []);
        }

        $endpoint = "sports/{$sport->oddsApiKey()}/events/{$oddsApiEventId}/odds";
        $started = microtime(true) * 1000;

        try {
            $response = $this->http()->get($this->url($endpoint), [
                'apiKey' => config('edge.odds_api.key'),
                'regions' => implode(',', (array) config('edge.odds_api.regions')),
                'markets' => implode(',', $marketKeys),
                'oddsFormat' => 'american',
                'dateFormat' => 'iso',
            ])->throw();

            $props = $this->parsePropsPayload($sport, $oddsApiEventId, (array) ($response->json() ?? []));

            $this->meter->record(self::PROVIDER, $endpoint, $response, $started, $jobRunId, $props->quoteCount());

            return $props;
        } catch (RequestException $e) {
            /*
             * A game whose props the book has not posted yet, or has taken
             * down again, answers 404. That is an ordinary state of the
             * world rather than a fault, it costs no credits, and treating
             * it as a failure would turn a quiet Tuesday into a wall of
             * red on the jobs screen.
             */
            if ($e->response?->status() === 404) {
                $this->meter->record(self::PROVIDER, $endpoint, $e->response, $started, $jobRunId, 0);

                return new EventProps($sport, $oddsApiEventId, []);
            }

            $this->meter->record(self::PROVIDER, $endpoint, $e->response, $started, $jobRunId, 0, $e->getMessage());

            throw $e;
        }
    }

    /**
     * The board for a sport as it stood at one past moment.
     *
     * Ten credits per market per region, so every caller is expected to
     * have counted before it gets here. The endpoint answers with the
     * snapshot at or before the timestamp asked for and names the one it
     * gave, and that is the timestamp the rows are stamped with.
     *
     * @param  array<int, Market>|null  $markets  defaults to the markets of the sport
     */
    public function historicalOddsForSport(
        Sport $sport,
        CarbonImmutable $at,
        ?array $markets = null,
        ?int $jobRunId = null,
    ): HistoricalOddsSnapshot {
        if (! $this->isConfigured()) {
            throw new RuntimeException('ODDS_API_KEY is not set.');
        }

        $markets ??= $sport->markets();
        $endpoint = "historical/sports/{$sport->oddsApiKey()}/odds";
        $started = microtime(true) * 1000;

        try {
            $response = $this->http()->get($this->url($endpoint), [
                'apiKey' => config('edge.odds_api.key'),
                'regions' => implode(',', (array) config('edge.odds_api.regions')),
                'markets' => implode(',', array_map(static fn (Market $m) => $m->value, $markets)),
                'oddsFormat' => 'american',
                'dateFormat' => 'iso',
                'date' => $at->utc()->toIso8601ZuluString(),
            ])->throw();

            $payload = (array) ($response->json() ?? []);

            $snapshot = new HistoricalOddsSnapshot(
                requestedAt: $at,
                timestamp: isset($payload['timestamp'])
                    ? CarbonImmutable::parse((string) $payload['timestamp'])
                    : null,
                events: $this->parseOddsPayload($sport, (array) ($payload['data'] ?? [])),
            );

            $this->meter->record(self::PROVIDER, $endpoint, $response, $started, $jobRunId, $snapshot->quoteCount());

            return $snapshot;
        } catch (RequestException $e) {
            $this->meter->record(self::PROVIDER, $endpoint, $e->response, $started, $jobRunId, 0, $e->getMessage());

            throw $e;
        }
    }

    /**
     * One event worth of player props.
     *
     * @param  array<string, mixed>  $payload  a single event, not a list
     */
    private function parsePropsPayload(Sport $sport, string $oddsApiEventId, array $payload): EventProps
    {
        $quotes = [];

        foreach ($payload['bookmakers'] ?? [] as $book) {
            foreach ($book['markets'] ?? [] as $market) {
                $marketKey = (string) ($market['key'] ?? '');

                /*
                 * Only player props. This endpoint will happily return the
                 * three core markets alongside them, and those already
                 * have a home - letting them through here would write the
                 * same quote twice, once with a market and once with a
                 * market key, and the second copy would be invisible to
                 * every reader that feeds the model.
                 */
                if (! str_starts_with($marketKey, 'player_')) {
                    continue;
                }

                $lastUpdate = isset($market['last_update'])
                    ? CarbonImmutable::parse((string) $market['last_update'])
                    : null;

                foreach ($market['outcomes'] ?? [] as $outcome) {
                    $side = Side::tryFrom(mb_strtolower((string) ($outcome['name'] ?? '')));

                    if ($side === null || ! isset($outcome['price'])) {
                        continue;
                    }

                    $quotes[] = new PropQuote(
                        bookKey: (string) $book['key'],
                        bookTitle: (string) ($book['title'] ?? $book['key']),
                        marketKey: $marketKey,
                        side: $side,
                        line: isset($outcome['point']) ? (float) $outcome['point'] : null,
                        priceAmerican: (int) $outcome['price'],
                        playerName: isset($outcome['description'])
                            ? mb_substr((string) $outcome['description'], 0, 120)
                            : null,
                        lastUpdateAt: $lastUpdate,
                    );
                }
            }
        }

        return new EventProps(
            $sport,
            $oddsApiEventId,
            $quotes,
            isset($payload['id']) ? (string) $payload['id'] : null,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $payload
     * @return array<int, EventOdds>
     */
    private function parseOddsPayload(Sport $sport, array $payload): array
    {
        $events = [];

        foreach ($payload as $row) {
            if (! isset($row['id'], $row['home_team'], $row['away_team'], $row['commence_time'])) {
                continue;
            }

            $quotes = [];

            foreach ($row['bookmakers'] ?? [] as $book) {
                foreach ($book['markets'] ?? [] as $market) {
                    $marketEnum = Market::tryFrom((string) ($market['key'] ?? ''));

                    if ($marketEnum === null) {
                        continue;
                    }

                    $lastUpdate = isset($market['last_update'])
                        ? CarbonImmutable::parse($market['last_update'])
                        : null;

                    foreach ($market['outcomes'] ?? [] as $outcome) {
                        $side = $this->sideFor($marketEnum, (string) ($outcome['name'] ?? ''), $row);

                        if ($side === null || ! isset($outcome['price'])) {
                            continue;
                        }

                        $quotes[] = new OddsQuote(
                            bookKey: (string) $book['key'],
                            bookTitle: (string) ($book['title'] ?? $book['key']),
                            market: $marketEnum,
                            side: $side,
                            line: isset($outcome['point']) ? (float) $outcome['point'] : null,
                            priceAmerican: (int) $outcome['price'],
                            selectionName: (string) ($outcome['name'] ?? ''),
                            lastUpdateAt: $lastUpdate,
                        );
                    }
                }
            }

            $events[] = new EventOdds(
                sport: $sport,
                oddsApiEventId: (string) $row['id'],
                homeTeamName: (string) $row['home_team'],
                awayTeamName: (string) $row['away_team'],
                commenceAt: CarbonImmutable::parse($row['commence_time']),
                quotes: $quotes,
            );
        }

        return $events;
    }

    private function sideFor(Market $market, string $outcomeName, array $row): ?Side
    {
        if ($market === Market::Total) {
            return Side::tryFrom(mb_strtolower($outcomeName));
        }

        if ($outcomeName === ($row['home_team'] ?? null)) {
            return Side::Home;
        }

        if ($outcomeName === ($row['away_team'] ?? null)) {
            return Side::Away;
        }

        if (mb_strtolower($outcomeName) === 'draw') {
            return Side::Draw;
        }

        return null;
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('edge.odds_api.timeout'))
            ->retry(
                (int) config('edge.odds_api.retries'),
                (int) config('edge.odds_api.retry_sleep_ms'),
                throw: false,
            )
            ->withUserAgent((string) config('edge.http.user_agent'))
            ->acceptJson();
    }

    private function url(string $endpoint): string
    {
        return rtrim((string) config('edge.odds_api.base_url'), '/').'/'.ltrim($endpoint, '/');
    }
}
