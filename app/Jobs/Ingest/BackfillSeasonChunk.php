<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Enums\SeasonType;
use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\JobRun;
use App\Models\Season;
use App\Services\Ingestion\EventSynchronizer;
use App\Services\Ingestion\SourceRegistry;
use App\Services\Ops\JobRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One season of history for one sport. The backfill command queues one of
 * these per season rather than looping in a single process, so a five-season
 * backfill fits inside worker memory and time limits and can resume.
 */
class BackfillSeasonChunk implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(
        public readonly Sport $sport,
        public readonly int $season,
        public readonly SeasonType $seasonType = SeasonType::Regular,
        public readonly TriggerSource $triggeredBy = TriggerSource::Manual,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("backfill:{$this->sport->value}:{$this->season}"))->expireAfter(3600),
        ];
    }

    public function handle(SourceRegistry $registry, EventSynchronizer $sync, JobRunRecorder $recorder): void
    {
        $this->tracked(
            "backfill:{$this->sport->value}",
            function (JobRun $run) use ($registry, $sync, $recorder): array {
                $source = $registry->forRole($this->sport, 'schedules');

                if ($source === null || ! $source->isAvailable()) {
                    $recorder->skip($run, "No available schedule source for {$this->sport->value}.");

                    return [];
                }

                $source->withJobRun($run->id);

                Season::query()->updateOrCreate(
                    [
                        'sport' => $this->sport->value,
                        'year' => $this->season,
                        'season_type' => $this->seasonType->value,
                    ],
                    []
                );

                $stats = $sync->sync(
                    $source->schedule($this->season, $this->seasonType),
                    $source->key(),
                );

                return [
                    'records_in' => $stats['seen'],
                    'records_out' => $stats['created'] + $stats['updated'],
                    'created' => $stats['created'],
                    'unresolved_teams' => $stats['unresolved'],
                    'season' => $this->season,
                    'source' => $source->key(),
                ];
            },
            $this->sport,
            ['season' => $this->season, 'season_type' => $this->seasonType->value],
        );
    }
}
