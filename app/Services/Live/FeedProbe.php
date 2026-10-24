<?php

declare(strict_types=1);

namespace App\Services\Live;

use App\Data\EventOdds;
use App\Data\LiveGameState;
use App\Enums\Sport;
use App\Models\LiveFeedProbe;
use App\Services\Odds\TheOddsApiClient;
use App\Services\Teams\NameNormalizer;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * One sweep of every live feed, written down exactly as it arrived.
 *
 * This is the whole of Phase 0. It builds no model and makes no
 * recommendation; it answers the question that has to be settled before
 * either is worth writing - whether the data a last-minute scanner would
 * stand on is fast enough to stand on.
 *
 * Nothing is inferred. A source that does not publish a clock records a
 * null clock, and a source that does not stamp its readings records a null
 * timestamp, because an invented value would quietly turn an unmeasurable
 * feed into one that looks fine.
 */
class FeedProbe
{
    public function __construct(
        private readonly LiveSourceRegistry $registry,
        private readonly TheOddsApiClient $odds,
    ) {}

    /**
     * Read every game-state feed for a sport once.
     *
     * Previous digests come in from the caller rather than from a query, so
     * a long session does not re-read its own growing history on every
     * tick.
     *
     * @param  array<string, string>  $previous  source|ref => digest
     * @return array<string, mixed>
     */
    public function sweepGameState(Sport $sport, string $session, array $previous = [], ?int $jobRunId = null): array
    {
        $written = 0;
        $changes = 0;
        $live = 0;
        $digests = $previous;
        $perSource = [];

        foreach ($this->registry->for($sport) as $source) {
            $startedMs = microtime(true) * 1000;
            $observedAt = CarbonImmutable::now();

            try {
                $states = $source->withJobRun($jobRunId)->inPlay();
            } catch (Throwable $e) {
                // A feed that falls over is a measurement, not an
                // interruption: the point of the audit is to find out how
                // often it happens.
                LiveFeedProbe::create([
                    'session' => $session,
                    'sport' => $sport->value,
                    'source' => $source->key(),
                    'kind' => 'game_state',
                    'observed_at' => $observedAt,
                    'http_ms' => (int) round((microtime(true) * 1000) - $startedMs),
                    'ok' => false,
                    'error' => mb_substr($e->getMessage(), 0, 500),
                    'created_at' => now(),
                ]);

                $perSource[$source->key()] = ['ok' => false, 'games' => 0, 'error' => $e->getMessage()];
                $written++;

                continue;
            }

            $httpMs = (int) round((microtime(true) * 1000) - $startedMs);

            foreach ($states as $state) {
                $slot = $source->key().'|'.$state->externalRef;
                $digest = $state->materialDigest();
                $changed = isset($digests[$slot]) && $digests[$slot] !== $digest;

                LiveFeedProbe::create([
                    'session' => $session,
                    'sport' => $sport->value,
                    'source' => $source->key(),
                    'kind' => 'game_state',
                    'external_ref' => $state->externalRef,
                    'game_key' => $state->gameKey(),
                    'status' => $state->status->value,
                    'home_score' => $state->homeScore,
                    'away_score' => $state->awayScore,
                    'period' => $state->period,
                    'clock_seconds' => $state->clockSeconds,
                    'possession' => $state->possession,
                    'observed_at' => $observedAt,
                    'source_reported_at' => $state->sourceReportedAt,
                    'staleness_ms' => $this->staleness($observedAt, $state),
                    'http_ms' => $httpMs,
                    'ok' => true,
                    'changed' => $changed,
                    'digest' => $digest,
                    'payload' => $state->toArray(),
                    'created_at' => now(),
                ]);

                $digests[$slot] = $digest;
                $written++;
                $live++;
                $changes += $changed ? 1 : 0;
            }

            $perSource[$source->key()] = [
                'ok' => true,
                'games' => count($states),
                'http_ms' => $httpMs,
                'stamps' => $source->stampsItsOwnReadings(),
            ];
        }

        return [
            'probes' => $written,
            'live' => $live,
            'changes' => $changes,
            'digests' => $digests,
            'sources' => $perSource,
        ];
    }

    /**
     * Read the odds feed once, keeping only games already under way.
     *
     * The book's own last_update is the single most valuable number in this
     * whole audit: it is the one place a source states when its price was
     * true, so it is the only staleness that can be measured outright
     * rather than inferred from a second feed.
     *
     * @param  array<string, string>  $previous
     * @return array<string, mixed>
     */
    public function sweepOdds(Sport $sport, string $session, array $previous = [], ?int $jobRunId = null): array
    {
        if (! $this->odds->isConfigured()) {
            return ['available' => false, 'reason' => 'ODDS_API_KEY is not set, so no price could be read at all.'];
        }

        if (! $this->odds->hasBudget()) {
            return ['available' => false, 'reason' => 'The monthly API reserve is reached; the probe will not spend into it.'];
        }

        $startedMs = microtime(true) * 1000;
        $observedAt = CarbonImmutable::now();

        try {
            $events = $this->odds->oddsForSport($sport, $jobRunId);
        } catch (Throwable $e) {
            LiveFeedProbe::create([
                'session' => $session,
                'sport' => $sport->value,
                'source' => TheOddsApiClient::PROVIDER,
                'kind' => 'odds',
                'observed_at' => $observedAt,
                'http_ms' => (int) round((microtime(true) * 1000) - $startedMs),
                'ok' => false,
                'error' => mb_substr($e->getMessage(), 0, 500),
                'created_at' => now(),
            ]);

            return ['available' => true, 'ok' => false, 'error' => $e->getMessage()];
        }

        $httpMs = (int) round((microtime(true) * 1000) - $startedMs);
        $digests = $previous;
        $written = 0;
        $changes = 0;
        $inPlay = 0;

        foreach ($events as $event) {
            // Only games already under way. An upcoming price says nothing
            // about how fast a book reprices a game in progress.
            if ($event->commenceAt->isFuture()) {
                continue;
            }

            $inPlay++;
            $gameKey = $this->gameKey($event);

            foreach ($event->quotes as $quote) {
                $slot = $event->oddsApiEventId.'|'.$quote->bookKey.'|'.$quote->market->value.'|'.$quote->side->value;
                $digest = substr(hash('sha256', $quote->priceAmerican.'|'.($quote->line ?? '-')), 0, 16);

                LiveFeedProbe::create([
                    'session' => $session,
                    'sport' => $sport->value,
                    'source' => TheOddsApiClient::PROVIDER,
                    'kind' => 'odds',
                    'external_ref' => $event->oddsApiEventId,
                    'game_key' => $gameKey,
                    'book_key' => $quote->bookKey,
                    'market' => $quote->market->value,
                    'side' => $quote->side->value,
                    'price_american' => $quote->priceAmerican,
                    'line' => $quote->line,
                    'observed_at' => $observedAt,
                    'source_reported_at' => $quote->lastUpdateAt,
                    'staleness_ms' => $quote->lastUpdateAt === null
                        ? null
                        : max(0, (int) round($quote->lastUpdateAt->diffInMilliseconds($observedAt, true))),
                    'http_ms' => $httpMs,
                    'ok' => true,
                    'changed' => isset($digests[$slot]) && $digests[$slot] !== $digest,
                    'digest' => $digest,
                    'created_at' => now(),
                ]);

                $changes += (isset($digests[$slot]) && $digests[$slot] !== $digest) ? 1 : 0;
                $digests[$slot] = $digest;
                $written++;
            }
        }

        return [
            'available' => true,
            'ok' => true,
            'probes' => $written,
            'in_play' => $inPlay,
            'upcoming' => count($events) - $inPlay,
            'changes' => $changes,
            'http_ms' => $httpMs,
            'digests' => $digests,
        ];
    }

    /** The same key the state feeds build, so odds and state can be joined. */
    private function gameKey(EventOdds $event): string
    {
        return NameNormalizer::slug($event->awayTeamName).'-at-'.NameNormalizer::slug($event->homeTeamName);
    }

    private function staleness(CarbonImmutable $observedAt, LiveGameState $state): ?int
    {
        if ($state->sourceReportedAt === null) {
            return null;
        }

        return max(0, (int) round($state->sourceReportedAt->diffInMilliseconds($observedAt, true)));
    }
}
