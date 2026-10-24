<?php

declare(strict_types=1);

namespace App\Jobs\Odds;

use App\Enums\Market;
use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\JobRun;
use App\Services\Odds\HistoricalOddsWriter;
use App\Services\Odds\TheOddsApiClient;
use App\Services\Ops\JobRunRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * The board of one sport as it stood at one past moment, written as
 * ordinary append-only snapshots.
 *
 * One request per job rather than one per season, because a request here
 * costs ten credits per market per region and a job that fails half way
 * through a season should not have to spend the first half again. The
 * command works out which moments to ask about and queues one of these for
 * each.
 *
 * The rows are real prices from real books, so they go in under those books
 * rather than under a placeholder - the nflverse import uses a fake book
 * because what it carries is a derived closing number, which is a different
 * kind of thing. What keeps these out of line shopping is their age: a
 * quote from last March is outside every freshness window there is.
 */
class BackfillHistoricalOdds implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public int $tries = 3;

    public int $backoff = 120;

    /** @param  array<int, string>  $marketValues */
    public function __construct(
        public readonly Sport $sport,
        public readonly string $at,
        public readonly array $marketValues = [],
        public readonly TriggerSource $triggeredBy = TriggerSource::Manual,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('odds-backfill:'.$this->sport->value))->expireAfter(900)];
    }

    public function handle(
        TheOddsApiClient $client,
        HistoricalOddsWriter $writer,
        JobRunRecorder $recorder,
    ): void {
        $this->tracked(
            "odds:backfill:{$this->sport->value}",
            function (JobRun $run) use ($client, $writer, $recorder): array {
                if (! $client->isConfigured()) {
                    $recorder->skip($run, 'ODDS_API_KEY is not set.');

                    return [];
                }

                // Checked here as well as in the command, because the
                // command counted once and this runs some time later with
                // however much of the month is left by then.
                if (! $client->hasBudget()) {
                    $recorder->skip($run, 'Monthly API quota reserve reached; refusing to backfill.');

                    return [];
                }

                $snapshot = $client->historicalOddsForSport(
                    $this->sport,
                    CarbonImmutable::parse($this->at),
                    $this->markets(),
                    $run->id,
                );

                if ($snapshot->isEmpty()) {
                    return ['records_in' => 0, 'records_out' => 0, 'requested_at' => $this->at];
                }

                $written = $writer->write($snapshot);

                return [
                    'records_in' => count($snapshot->events),
                    'records_out' => $written['snapshots'],
                    'requested_at' => $this->at,
                    'snapshot_at' => $snapshot->timestamp?->toIso8601String(),
                    'events_matched' => $written['events_matched'],
                    'events_unmatched' => $written['events_unmatched'],
                ];
            },
            $this->sport,
            ['at' => $this->at],
        );
    }

    /** @return array<int, Market>|null */
    private function markets(): ?array
    {
        if ($this->marketValues === []) {
            return null;
        }

        return array_map(static fn (string $m) => Market::from($m), $this->marketValues);
    }
}
