<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A feed that answers with nothing has not succeeded
|--------------------------------------------------------------------------
|
| This job ran four times a day for weeks against college basketball and
| reported success every time while writing nothing at all - ESPN has no
| injury feed for it. Against college football it returned three teams out
| of 136. The NFL came back with all 32, and all three looked the same on
| the Jobs screen.
|
| Absent coverage and absent injuries are identical in a row count, so the
| comparison has to be against the teams we hold. Nobody being hurt is a
| fact about the league. Nobody being reported on is a fact about the
| feed, and only one of them is a problem.
|
*/

use App\Enums\JobStatus;
use App\Enums\Sport;
use App\Jobs\Ingest\SyncInjuries;
use App\Models\JobRun;
use App\Models\Team;
use App\Services\Ingestion\InjurySources;
use App\Services\Ops\JobRunRecorder;

/** A source that answers with whatever the test needs it to. */
function injurySourceReturning(array $stats): void
{
    $source = new class($stats)
    {
        public function __construct(private array $stats) {}

        public function key(): string
        {
            return 'fake';
        }

        public function withJobRun(?int $id): self
        {
            return $this;
        }

        public function sync(Sport $sport): array
        {
            return $this->stats;
        }
    };

    app()->instance(InjurySources::class, new class($source) extends InjurySources
    {
        public function __construct(private object $source)
        {
            parent::__construct();
        }

        public function forSport(Sport $sport): ?object
        {
            return $this->source;
        }
    });
}

function nflTeams(int $count): void
{
    foreach (range(1, $count) as $i) {
        Team::create([
            'sport' => 'nfl',
            'name' => 'Team '.$i,
            'display_name' => 'Team '.$i,
            'slug' => 'team-'.$i,
        ]);
    }
}

function runInjurySync(): JobRun
{
    (new SyncInjuries(Sport::Nfl))->handle(app(InjurySources::class), app(JobRunRecorder::class));

    return JobRun::query()->where('job_key', 'ingest:injuries:nfl')->latest('id')->firstOrFail();
}

it('refuses to call a run successful when the feed named no team at all', function () {
    nflTeams(32);
    injurySourceReturning(['reports' => 0, 'teams' => 0, 'unresolved' => 0, 'unknown_status' => 0]);

    $run = runInjurySync();

    expect($run->status)->toBe(JobStatus::Skipped)
        ->and($run->error_message)->toContain('no injury coverage')
        // Said out loud, because the two are easy to confuse and the
        // whole bug was confusing them.
        ->and($run->error_message)->toContain('not the same as nobody being hurt');
});

it('accepts a feed that answered for the league and found nobody hurt', function () {
    /*
     * The legitimate zero. Every team reported, none of them with an
     * injury - a fact about the league, not about the feed.
     */
    nflTeams(32);
    injurySourceReturning(['reports' => 0, 'teams' => 32, 'unresolved' => 0, 'unknown_status' => 0]);

    expect(runInjurySync()->status)->toBe(JobStatus::Success);
});

it('records how much of the league the feed covered', function () {
    /*
     * Three of 136 is a feed that technically answered, and "success"
     * beside it is why this went unnoticed for weeks. The figure is
     * named rather than left to be worked out from two numbers.
     */
    nflTeams(100);
    injurySourceReturning(['reports' => 9, 'teams' => 3, 'unresolved' => 0, 'unknown_status' => 0]);

    $run = runInjurySync();

    expect($run->status)->toBe(JobStatus::Success)
        ->and($run->context['team_coverage'])->toBe(0.03)
        ->and($run->context['teams_known'])->toBe(100)
        ->and($run->context['teams'])->toBe(3);
});

it('does not condemn a sport we hold no teams for', function () {
    /*
     * Nothing to compare against. A feed returning nothing for a league
     * we have not imported is our gap, not the feed's, and calling it a
     * coverage failure would point at the wrong thing.
     */
    injurySourceReturning(['reports' => 0, 'teams' => 0, 'unresolved' => 0, 'unknown_status' => 0]);

    expect(runInjurySync()->status)->toBe(JobStatus::Success);
});
