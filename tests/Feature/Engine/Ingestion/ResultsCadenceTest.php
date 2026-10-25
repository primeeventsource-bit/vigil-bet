<?php

declare(strict_types=1);

use App\Enums\JobStatus;
use App\Enums\Sport;
use App\Jobs\Ingest\SyncResults;
use App\Models\JobRun;
use App\Services\Ingestion\EventSynchronizer;
use App\Services\Ingestion\ResultsCadence;
use App\Services\Ingestion\SourceRegistry;
use App\Services\Ops\JobRunRecorder;
use Illuminate\Support\Facades\Http;

/*
 * How often to ask a feed for final scores.
 *
 * Hourly regardless, a game ending at ten past is not graded until the
 * next pass, and the opinions page says `pending` for over an hour about
 * a game anybody can look up. Every five minutes regardless, a sport on
 * a metered feed spends its quota overnight re-reading a slate that was
 * graded yesterday. So the frequency follows whether an answer is
 * actually outstanding.
 */

function cadence(): ResultsCadence
{
    return app(ResultsCadence::class);
}

function finishedGameWithoutScore(array $overrides = []): void
{
    engineGame('Cleveland Browns', 'Tennessee Titans', array_merge([
        'sport' => 'nfl',
        'starts_at' => now()->subHours(4),
        'status' => 'final',
        'final_home_score' => null,
        'final_away_score' => null,
    ], $overrides));
}

/** The last time this sport's results were looked at, whatever came of it. */
function resultsRanMinutesAgo(int $minutes, JobStatus $status = JobStatus::Success): void
{
    // Replaces rather than adds: the cadence reads the most recent run,
    // so a second, older row would say nothing about what it does.
    JobRun::query()->where('job_key', cadence()->jobKey(Sport::Nfl))->delete();

    JobRun::create([
        'job_key' => cadence()->jobKey(Sport::Nfl),
        'sport' => 'nfl',
        'status' => $status,
        'started_at' => now()->subMinutes($minutes),
        'finished_at' => now()->subMinutes($minutes),
    ]);
}

it('chases a game that should have finished and has no score', function () {
    finishedGameWithoutScore();

    expect(cadence()->isChasing(Sport::Nfl))->toBeTrue()
        ->and(cadence()->intervalMinutes(Sport::Nfl))->toBe(5);
});

it('leaves a sport alone once every finished game has its score', function () {
    finishedGameWithoutScore(['final_home_score' => 27, 'final_away_score' => 24]);

    expect(cadence()->isChasing(Sport::Nfl))->toBeFalse()
        ->and(cadence()->intervalMinutes(Sport::Nfl))->toBe(60);
});

it('does not expect a score from a game still being played', function () {
    // Kicked off ten minutes ago. Asking now spends a call to be told
    // what we already know.
    finishedGameWithoutScore(['starts_at' => now()->subMinutes(10), 'status' => 'in_progress']);

    expect(cadence()->isChasing(Sport::Nfl))->toBeFalse();
});

it('gives up chasing rather than asking for ever', function () {
    // A day old and still unscored. Something upstream is not coming,
    // and the alternative to giving up is one bad row holding the sport
    // at the fast cadence indefinitely - which is the thing a cadence
    // class exists to prevent. The idle pass still picks it up.
    finishedGameWithoutScore(['starts_at' => now()->subHours(24)]);

    expect(cadence()->isChasing(Sport::Nfl))->toBeFalse()
        ->and(cadence()->intervalMinutes(Sport::Nfl))->toBe(60);
});

it('does not chase a game that was never played', function () {
    finishedGameWithoutScore(['status' => 'postponed']);
    finishedGameWithoutScore(['status' => 'canceled']);

    expect(cadence()->isChasing(Sport::Nfl))->toBeFalse();
});

it('asks about one sport without speaking for another', function () {
    finishedGameWithoutScore(['sport' => 'nba']);

    expect(cadence()->isChasing(Sport::Nba))->toBeTrue()
        ->and(cadence()->isChasing(Sport::Nfl))->toBeFalse();
});

it('holds off until the interval has passed', function () {
    finishedGameWithoutScore();
    resultsRanMinutesAgo(2);

    expect(cadence()->isDue(Sport::Nfl))->toBeFalse();

    resultsRanMinutesAgo(6);

    expect(cadence()->isDue(Sport::Nfl))->toBeTrue();
});

it('runs when it has never run at all', function () {
    expect(cadence()->isDue(Sport::Nfl))->toBeTrue();
});

it('counts a skipped run as having looked', function () {
    /*
     * A sport with no results source records a skip every time it is
     * asked. PullCadence counts only successes, because a missed odds
     * pull is perishable; a result is not. Counting skips here is what
     * keeps a permanently skipping sport on its interval rather than
     * retrying as fast as the tick allows.
     */
    finishedGameWithoutScore();
    resultsRanMinutesAgo(2, JobStatus::Skipped);

    expect(cadence()->isDue(Sport::Nfl))->toBeFalse();
});

it('falls back to the idle clock when nothing is outstanding', function () {
    resultsRanMinutesAgo(30);

    expect(cadence()->isDue(Sport::Nfl))->toBeFalse();

    resultsRanMinutesAgo(61);

    expect(cadence()->isDue(Sport::Nfl))->toBeTrue();
});

/*
 * The one piece of this that is a string in two places.
 *
 * The cadence decides by looking up when the job last ran, and it finds
 * that run by its key. Get the key wrong and nothing throws: the lookup
 * simply never finds a run, every tick looks like the first one, and the
 * job runs every five minutes for ever against a metered feed. So the
 * two are tied together here rather than trusted to stay in step.
 */
it('watches the key the results job actually records under', function () {
    $cache = nflverseCacheSave();

    try {
        Http::fake(['raw.githubusercontent.com/*' => Http::response(nflverseCsv('27', '24'))]);
        nflverseCacheAgedMinutes(nflverseCsv('27', '24'), 1);

        (new SyncResults(Sport::Nfl))->handle(
            app(SourceRegistry::class),
            app(EventSynchronizer::class),
            app(JobRunRecorder::class),
        );
    } finally {
        nflverseCacheRestore($cache);
    }

    expect(JobRun::query()->where('job_key', cadence()->jobKey(Sport::Nfl))->exists())->toBeTrue()
        ->and(cadence()->lastRunAt(cadence()->jobKey(Sport::Nfl)))->not->toBeNull();
});
