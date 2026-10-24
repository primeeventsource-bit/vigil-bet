<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Services\Ops\ApiMeter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP behaviour for adapters: timeouts, retries, a identifying user
 * agent, and a metered record of every call.
 */
trait MakesHttpRequests
{
    protected ?int $jobRunId = null;

    public function withJobRun(?int $jobRunId): static
    {
        $this->jobRunId = $jobRunId;

        return $this;
    }

    protected function client(array $headers = []): PendingRequest
    {
        return Http::timeout((int) config('edge.http.timeout'))
            ->connectTimeout((int) config('edge.http.connect_timeout'))
            ->retry(
                (int) config('edge.http.retries'),
                (int) config('edge.http.retry_sleep_ms'),
                throw: false,
            )
            ->withUserAgent((string) config('edge.http.user_agent'))
            ->withHeaders($headers);
    }

    protected function getJson(string $url, array $query = [], array $headers = []): array
    {
        return (array) ($this->fetch($url, $query, $headers)->json() ?? []);
    }

    protected function getBody(string $url, array $query = [], array $headers = []): string
    {
        return $this->fetch($url, $query, $headers)->body();
    }

    protected function fetch(string $url, array $query = [], array $headers = []): Response
    {
        $started = microtime(true) * 1000;

        try {
            $response = $this->client($headers)->get($url, $query)->throw();

            $this->meter()->record($this->key(), $this->endpointLabel($url), $response, $started, $this->jobRunId);

            return $response;
        } catch (RequestException $e) {
            $this->meter()->record(
                $this->key(),
                $this->endpointLabel($url),
                $e->response,
                $started,
                $this->jobRunId,
                0,
                $e->getMessage(),
            );

            throw $e;
        }
    }

    protected function meter(): ApiMeter
    {
        return app(ApiMeter::class);
    }

    private function endpointLabel(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_PATH) ?: $url);
    }
}
