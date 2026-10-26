<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\ApiRequest;
use Illuminate\Http\Client\Response;

/**
 * Records every outbound API call and tracks provider quota. The Odds API
 * reports remaining quota in response headers; we store it so the dashboard
 * can show how much budget is left and the scheduler can stop before the
 * month runs dry.
 */
class ApiMeter
{
    public function record(
        string $provider,
        string $endpoint,
        ?Response $response,
        float $startedAtMs,
        ?int $jobRunId = null,
        int $itemsWritten = 0,
        ?string $error = null,
        bool $cacheHit = false,
    ): ApiRequest {
        $quotaUsed = null;
        $quotaRemaining = null;

        $creditsCost = null;

        if ($response !== null) {
            $quotaUsed = $this->intHeader($response, 'x-requests-used');
            $quotaRemaining = $this->intHeader($response, 'x-requests-remaining');
            // What this one call cost. The provider has always reported it
            // and nothing read it, which was harmless while every call
            // cost one credit and is not any more: props cost a credit per
            // market per region, history costs ten.
            $creditsCost = $this->intHeader($response, 'x-requests-last');
        }

        return ApiRequest::create([
            'provider' => $provider,
            'endpoint' => mb_substr($endpoint, 0, 191),
            'method' => 'GET',
            'http_status' => $response?->status(),
            'duration_ms' => (int) round((microtime(true) * 1000) - $startedAtMs),
            'quota_used' => $quotaUsed,
            'quota_remaining' => $quotaRemaining,
            'credits_cost' => $creditsCost,
            'response_bytes' => $response !== null ? mb_strlen($response->body()) : null,
            'items_written' => $itemsWritten,
            'cache_hit' => $cacheHit,
            'job_run_id' => $jobRunId,
            'error_message' => $error !== null ? mb_substr($error, 0, 2000) : null,
            'requested_at' => now(),
        ]);
    }

    /** Most recent quota reading a provider gave us, if any. */
    public function remainingQuota(string $provider): ?int
    {
        return ApiRequest::query()
            ->where('provider', $provider)
            ->whereNotNull('quota_remaining')
            // Insertion order breaks the tie. Several calls land in
            // the same second, and `requested_at` alone leaves which
            // of them is latest up to the database.
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->value('quota_remaining');
    }

    public function requestsThisMonth(string $provider): int
    {
        return ApiRequest::query()
            ->where('provider', $provider)
            ->where('requested_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * Credits spent this month, which is the number the plan is measured
     * in. Calls are not credits: one props call can cost eight and one
     * historical call costs ten.
     *
     * A call the provider never priced counts as one, which is what the
     * old request count assumed about every call. That is a floor rather
     * than a guess - it can understate, never overstate - and it only
     * applies to rows written before the cost was recorded, or to calls
     * that failed before a header came back.
     */
    public function creditsThisMonth(string $provider): int
    {
        $rows = ApiRequest::query()
            ->where('provider', $provider)
            ->where('requested_at', '>=', now()->startOfMonth())
            ->selectRaw('coalesce(sum(credits_cost), 0) as priced, count(*) as calls, count(credits_cost) as with_cost')
            ->first();

        if ($rows === null) {
            return 0;
        }

        return (int) $rows->priced + ((int) $rows->calls - (int) $rows->with_cost);
    }

    /**
     * The plan size the provider itself reports, rather than the one
     * configured.
     *
     * The Odds API returns how many requests are used and how many remain
     * on every response, and those two add up to the plan. Both have been
     * recorded since Phase 1 and neither was ever read, which left the
     * budget guard trusting a number typed into a `.env` file.
     *
     * Null until a real call has been made, which is honest: before then
     * nobody knows the plan but the person holding the key.
     */
    public function observedQuota(string $provider): ?int
    {
        $row = ApiRequest::query()
            ->where('provider', $provider)
            ->whereNotNull('quota_remaining')
            ->whereNotNull('quota_used')
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->first(['quota_used', 'quota_remaining']);

        return $row === null
            ? null
            : (int) $row->quota_used + (int) $row->quota_remaining;
    }

    /**
     * Guard against burning the whole monthly allowance. Leaves a reserve so
     * there is always budget for closing lines, which matter most.
     *
     * The reserve is a share of the *real* plan wherever the provider has
     * told us what that is. Computing it from the configured number instead
     * breaks in both directions and one of them is silent: a free tier of
     * 500 behind a config that still says 20,000 reserves 2,000, so the
     * guard refuses every pull while 500 requests sit unused, and nothing
     * on screen explains why the odds never arrive.
     */
    public function hasBudget(string $provider, int $monthlyQuota, int $reservePct = 10): bool
    {
        $quota = $this->observedQuota($provider) ?? $monthlyQuota;
        $remaining = $this->remainingQuota($provider);

        if ($remaining !== null) {
            return $remaining > (int) floor($quota * ($reservePct / 100));
        }

        $used = $this->creditsThisMonth($provider);

        return $used < (int) floor($quota * (1 - $reservePct / 100));
    }

    /**
     * Credits left to spend before the reserve, which is what a job about
     * to spend a lot of them needs to know. Falls back to the local count
     * until the provider has reported a figure of its own.
     */
    public function spendableCredits(string $provider, int $monthlyQuota, int $reservePct = 10): int
    {
        $quota = $this->observedQuota($provider) ?? $monthlyQuota;
        $reserve = (int) floor($quota * ($reservePct / 100));
        $remaining = $this->remainingQuota($provider) ?? max(0, $quota - $this->creditsThisMonth($provider));

        return max(0, $remaining - $reserve);
    }

    private function intHeader(Response $response, string $header): ?int
    {
        $value = $response->header($header);

        return $value === '' || $value === null ? null : (int) $value;
    }
}
