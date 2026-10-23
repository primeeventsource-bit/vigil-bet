<?php

declare(strict_types=1);

namespace App\Services\EdgeLab;

use App\Data\EdgeLab\Page;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one way Vigil reads Edge Lab.
 *
 * Nothing here interprets a number. It fetches rows Edge Lab wrote and
 * hands them on; the edge, the verdict, the stake and the reason are all
 * read, never derived. Vigil recomputing any of them is how a screen ends
 * up disagreeing with the engine behind it, and the product rests on those
 * two agreeing.
 *
 * Read-only by construction. There is one POST, the parlay judge, and it
 * stores nothing on either side.
 */
class EdgeLabClient
{
    public function __construct(private readonly CircuitBreaker $breaker) {}

    public function isConfigured(): bool
    {
        return (bool) config('edgelab.enabled') && filled(config('edgelab.token'));
    }

    public function breaker(): CircuitBreaker
    {
        return $this->breaker;
    }

    /**
     * One page of the value board: every evaluated candidate, pick and
     * pass alike. Passes are not filtered out here or upstream - a board
     * showing only picks would imply everything else went unexamined.
     *
     * @param  array<string, mixed>  $filters  sport, recommendation, scan
     */
    public function board(?int $since = null, ?int $limit = null, array $filters = []): Page
    {
        return $this->page('board', $since, $limit, $filters);
    }

    /** @param  array<string, mixed>  $filters  sport, from, to */
    public function events(?int $since = null, ?int $limit = null, array $filters = []): Page
    {
        return $this->page('events', $since, $limit, $filters);
    }

    public function models(?int $since = null, ?int $limit = null): Page
    {
        return $this->page('models', $since, $limit);
    }

    /** @return array<string, mixed> one game, with the availability known before kickoff */
    public function event(int $edgeLabEventId): array
    {
        return (array) ($this->get("events/{$edgeLabEventId}")['data'] ?? []);
    }

    /**
     * The latest quote per book for one game.
     *
     * One event at a time, always. Edge Lab has no sport-wide or date-wide
     * odds route and must not grow one: the licence allows showing prices
     * inside a paid interface and forbids repackaging the feed, and the
     * practical difference is almost entirely whether a caller can pull
     * the whole slate in one request.
     *
     * @return array<string, mixed>
     */
    public function odds(int $edgeLabEventId): array
    {
        return (array) ($this->get("odds/{$edgeLabEventId}")['data'] ?? []);
    }

    /** @return array<string, mixed> */
    public function predictions(int $edgeLabEventId): array
    {
        return (array) ($this->get("predictions/{$edgeLabEventId}")['data'] ?? []);
    }

    /**
     * Run Edge Lab's parlay judge over a slip and return its verdict.
     *
     * Stores nothing on either side: Edge Lab uses its evaluator's preview
     * rather than its evaluate, so a slip being rebuilt on every keystroke
     * does not bury the parlays somebody actually weighed.
     *
     * @param  array<int, array{event_id:int, market:string, side:string}>  $legs
     * @return array<string, mixed>
     */
    public function evaluateParlay(array $legs, ?float $shade = null): array
    {
        $payload = ['legs' => array_values($legs)];

        if ($shade !== null) {
            $payload['shade'] = $shade;
        }

        return (array) ($this->send('POST', 'parlay/evaluate', [], $payload)['data'] ?? []);
    }

    /** @param  array<string, mixed>  $filters */
    private function page(string $endpoint, ?int $since, ?int $limit, array $filters = []): Page
    {
        $query = array_filter(
            ['since' => $since, 'limit' => $limit ?? (int) config('edgelab.page_size')] + $filters,
            static fn ($value) => $value !== null && $value !== '',
        );

        return Page::fromResponse($this->get($endpoint, $query), $since);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $endpoint, array $query = []): array
    {
        return $this->send('GET', $endpoint, $query);
    }

    /**
     * Every call out of Vigil goes through here, so the breaker, the
     * retries and the error shape are decided in exactly one place.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $endpoint, array $query = [], ?array $body = null): array
    {
        if (! $this->isConfigured()) {
            throw EdgeLabException::notConfigured();
        }

        if (! $this->breaker->allowsRequest()) {
            throw EdgeLabException::breakerOpen($this->breaker->retryInSeconds());
        }

        $url = config('edgelab.base_url').'/'.ltrim($endpoint, '/');

        try {
            $response = $method === 'POST'
                ? $this->http()->post($url, $body ?? [])
                : $this->http()->get($url, $query);
        } catch (ConnectionException $e) {
            $this->breaker->recordFailure();

            throw new EdgeLabException('Could not reach Edge Lab: '.$e->getMessage(), endpoint: $endpoint);
        } catch (Throwable $e) {
            $this->breaker->recordFailure();

            throw new EdgeLabException('Edge Lab request failed: '.$e->getMessage(), endpoint: $endpoint);
        }

        if ($response->failed()) {
            $this->breaker->recordFailure();

            throw new EdgeLabException(
                $this->describe($response),
                status: $response->status(),
                endpoint: $endpoint,
            );
        }

        $this->breaker->recordSuccess();

        return (array) ($response->json() ?? []);
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) config('edgelab.token'))
            ->acceptJson()
            ->timeout((int) config('edgelab.http.timeout'))
            ->connectTimeout((int) config('edgelab.http.connect_timeout'))
            ->withUserAgent((string) config('edgelab.http.user_agent'))
            ->retry(
                max(1, (int) config('edgelab.http.retries')),
                fn (int $attempt) => (int) config('edgelab.http.retry_base_ms') * (2 ** ($attempt - 1)),
                $this->worthRetrying(...),
                throw: false,
            );
    }

    /**
     * Only failures that might answer differently next time.
     *
     * A 401 or a 404 is a disagreement about the request and will say the
     * same thing on the fourth attempt; retrying spends Edge Lab's
     * per-minute allowance proving a point, and the allowance is what the
     * sync needs once the problem is fixed. A 429 or a 5xx is worth
     * waiting out, and so is a connection that never got an answer at all.
     */
    private function worthRetrying(Throwable $e): bool
    {
        if (! $e instanceof RequestException || $e->response === null) {
            return true;
        }

        $status = $e->response->status();

        return $status === 429 || $status >= 500;
    }

    private function describe(Response $response): string
    {
        return match (true) {
            $response->status() === 401 => 'Edge Lab refused the token (401). Check EDGE_LAB_TOKEN.',
            $response->status() === 404 => 'Edge Lab has no such record (404).',
            $response->status() === 429 => 'Edge Lab rate limit reached (429).',
            default => 'Edge Lab answered '.$response->status().'.',
        };
    }
}
