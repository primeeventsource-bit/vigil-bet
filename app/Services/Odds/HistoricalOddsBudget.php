<?php

declare(strict_types=1);

namespace App\Services\Odds;

use App\Enums\Sport;
use App\Models\Event;
use App\Services\Ops\ApiMeter;
use Illuminate\Support\Carbon;

/**
 * What a historical backfill would cost, and whether it is allowed to run.
 *
 * The historical endpoint is ten credits per market per region. Three
 * markets across two regions is sixty credits a request, so a season of a
 * sport that plays most nights is a five figure number before anybody has
 * looked at it. A command that starts spending that and finds out later is
 * how a month of live pulls gets eaten by a backfill nobody meant to run
 * twice.
 *
 * So the cost is stated up front, counted against what is actually left
 * this month, and compared to a configurable share of it. The share is of
 * the remaining credits rather than of the plan, because the plan is not
 * what can be spent - what is left is.
 */
class HistoricalOddsBudget
{
    public function __construct(private readonly ApiMeter $meter) {}

    /**
     * The timestamps one backfill would ask about.
     *
     * One request returns every game in the sport as at one moment, so
     * games that kick off close together share a request. That is the
     * difference between a Saturday of college football costing one
     * request and costing sixty.
     *
     * @return array<int, Carbon> ordered, unique, one per bucket
     */
    public function timestampsFor(Sport $sport, Carbon $from, Carbon $to): array
    {
        $lead = (int) config('edge.odds_api.historical.lead_minutes');
        $bucket = max(1, (int) config('edge.odds_api.historical.bucket_minutes'));

        $kickoffs = Event::query()
            ->where('sport', $sport->value)
            ->whereBetween('starts_at', [$from, $to])
            ->orderBy('starts_at')
            ->pluck('starts_at');

        $buckets = [];

        foreach ($kickoffs as $kickoff) {
            $at = Carbon::parse($kickoff)->subMinutes($lead);
            // Floor onto the bucket grid so two kickoffs a few minutes
            // apart resolve to the same request rather than to two.
            $at->setTime($at->hour, intdiv($at->minute, $bucket) * $bucket, 0);

            $buckets[$at->toIso8601String()] = $at;
        }

        ksort($buckets);

        return array_values($buckets);
    }

    /** Credits one request costs: ten per market per region. */
    public function creditsPerRequest(Sport $sport, ?int $marketCount = null): int
    {
        $markets = $marketCount ?? count($sport->markets());
        $regions = count((array) config('edge.odds_api.regions'));

        return (int) config('edge.odds_api.historical.credits_per_market_region') * $markets * $regions;
    }

    public function estimate(Sport $sport, int $requests, ?int $marketCount = null): int
    {
        return $requests * $this->creditsPerRequest($sport, $marketCount);
    }

    /** Credits left to spend this month, after the standing reserve. */
    public function spendable(): int
    {
        return $this->meter->spendableCredits(
            TheOddsApiClient::PROVIDER,
            (int) config('edge.odds_api.monthly_quota'),
            (int) config('edge.odds_api.quota_reserve_pct'),
        );
    }

    /** The most one backfill is allowed to spend. */
    public function ceiling(): int
    {
        $share = (float) config('edge.odds_api.historical.max_share_of_remaining_pct');

        return (int) floor($this->spendable() * ($share / 100));
    }

    /**
     * Whether a backfill of this size may start, and why not when it may
     * not. The reason is a whole sentence because it is read by a person
     * deciding what to do about it.
     *
     * @return array{allowed:bool, estimate:int, spendable:int, ceiling:int, reason:?string}
     */
    public function verdict(Sport $sport, int $requests, ?int $marketCount = null): array
    {
        $estimate = $this->estimate($sport, $requests, $marketCount);
        $spendable = $this->spendable();
        $ceiling = $this->ceiling();
        $share = (float) config('edge.odds_api.historical.max_share_of_remaining_pct');

        $reason = null;

        if ($requests === 0) {
            $reason = 'Nothing to backfill: no games on the board inside that window. '
                .'Run edge:backfill first so the schedule exists to hang prices on.';
        } elseif ($spendable <= 0) {
            $reason = 'No credits left to spend this month once the reserve is held back.';
        } elseif ($estimate > $ceiling) {
            $reason = sprintf(
                'This backfill would cost about %s credits, which is more than the %s it is allowed '
                .'(%s%% of the %s still spendable this month). Narrow the window with --from and --to, '
                .'cut the markets with --markets, or raise ODDS_API_BACKFILL_MAX_SHARE_PCT if you mean it.',
                number_format($estimate),
                number_format($ceiling),
                rtrim(rtrim(number_format($share, 2), '0'), '.'),
                number_format($spendable),
            );
        }

        return [
            'allowed' => $reason === null,
            'estimate' => $estimate,
            'spendable' => $spendable,
            'ceiling' => $ceiling,
            'reason' => $reason,
        ];
    }
}
