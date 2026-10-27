<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| A retry has to wait for something
|--------------------------------------------------------------------------
|
| `value:scan` failed in production with "SQLSTATE[HY000]: General error:
| 7 no connection to the server". Not a bug in the scan: the database is
| serverless and in a different region from the application, and both
| sides hibernate, so a connection can be dropped by something that is
| merely asleep.
|
| The job was configured to try twice. With no backoff, the second
| attempt goes out in the same second as the first and meets the same
| dead socket, which spends the retry on exactly the condition the retry
| exists for. Seven jobs were in that state.
|
| Derived by reflection rather than from a list in this file, for the
| same reason as the append-only sweep: a hand-kept list is a list
| somebody forgets to extend, and the next job written with `tries = 2`
| and no backoff would look fine.
|
*/

/**
 * Every queued job in the application.
 *
 * @return array<int, class-string>
 */
function queuedJobs(): array
{
    $jobs = [];

    foreach (File::allFiles(app_path('Jobs')) as $file) {
        $relative = Str::of($file->getRelativePathname())
            ->replace(['/', '.php'], [chr(92), ''])
            ->toString();

        $class = 'App'.chr(92).'Jobs'.chr(92).$relative;

        if (! class_exists($class)) {
            continue;
        }

        if (! is_subclass_of($class, ShouldQueue::class) && ! in_array(ShouldQueue::class, class_implements($class) ?: [], true)) {
            continue;
        }

        $jobs[] = $class;
    }

    return $jobs;
}

it('finds the jobs rather than trusting a list', function () {
    // A floor, so this fails if the reflection breaks and the sweep below
    // starts asserting over an empty set - not every time somebody
    // legitimately adds a job.
    expect(count(queuedJobs()))->toBeGreaterThanOrEqual(20);
});

it('makes every job that retries wait before it does', function () {
    $immediate = [];

    foreach (queuedJobs() as $class) {
        $defaults = (new ReflectionClass($class))->getDefaultProperties();

        $tries = $defaults['tries'] ?? 1;

        if (! is_int($tries) || $tries <= 1) {
            // One attempt cannot retry, so it has nothing to wait for.
            continue;
        }

        $backoff = $defaults['backoff'] ?? null;

        if ($backoff === null || $backoff === 0) {
            $immediate[] = class_basename($class);
        }
    }

    expect($immediate)->toBe(
        [],
        'These jobs retry in the same second they failed: '.implode(', ', $immediate),
    );
});
