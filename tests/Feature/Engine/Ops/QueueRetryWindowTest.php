<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The queue's retry window against the jobs that run in it
|--------------------------------------------------------------------------
|
| `retry_after` is when the database queue presumes an in-flight job
| abandoned and hands it to another worker. A job that runs longer than it
| is not slow, it is broken: the queue reclaims it mid-run, a second
| worker starts it from the beginning, and it dies on max attempts having
| done its work twice.
|
| That already happened once here. At 90 seconds,
| `ingest:results:ncaaf` averaged 442 and failed 8 of 19 runs, and both
| rows it left in failed_jobs said MaxAttemptsExceeded and nothing about
| football.
|
| Raising it to 1800 fixed that one and left the window exactly equal to
| the longest job's own timeout, which is the same bug with no margin
| rather than a fixed one. So this derives the longest timeout from the
| jobs themselves: a new job with a longer one cannot quietly reintroduce
| it.
|
*/

use Illuminate\Support\Facades\File;

/** @return array<string, int> */
function jobTimeouts(): array
{
    $timeouts = [];

    foreach (File::allFiles(app_path('Jobs')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getRealPath());

        if (preg_match('/public int \$timeout\s*=\s*(\d+)/', $contents, $match) === 1) {
            $timeouts[$file->getFilename()] = (int) $match[1];
        }
    }

    return $timeouts;
}

it('finds the job timeouts it is meant to be checking', function (): void {
    // Without this the comparison below passes by comparing nothing.
    expect(jobTimeouts())->not->toBeEmpty();
});

it('leaves every job room to finish before the queue gives up on it', function (): void {
    $retryAfter = (int) config('queue.connections.database.retry_after');
    $longest = max(jobTimeouts());

    expect($retryAfter)->toBeGreaterThan(
        $longest,
        'retry_after is '.$retryAfter.' and the longest job timeout is '.$longest
        .'. A job that reaches its own timeout would be reclaimed at the same moment.'
    );
});

it('keeps real margin rather than a single second of it', function (): void {
    /*
     * A second of headroom satisfies the comparison and nothing else.
     * Five minutes covers the gap between a job timing out and the worker
     * actually releasing it.
     */
    $retryAfter = (int) config('queue.connections.database.retry_after');

    expect($retryAfter - max(jobTimeouts()))->toBeGreaterThanOrEqual(300);
});
