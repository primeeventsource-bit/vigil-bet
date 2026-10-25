<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whether the application is actually serving, for the platform's health
 * check and for anybody who wants an answer without logging in.
 *
 * Laravel's own `/up` returns 200 as soon as the framework boots, which
 * it will do perfectly well while the database is unreachable. That is
 * the state worth knowing about: a container that answers but cannot read
 * anything is worse than one that is plainly down, because nothing
 * upstream notices.
 *
 * What it will not do is report numbers. A public endpoint that says how
 * many jobs failed, or how many rows a table holds, is telling strangers
 * about the business. Whether something is failing is enough to act on,
 * and is all this returns.
 */
class HealthController extends Controller
{
    /** How far back a failed job still counts as a present problem. */
    private const FAILURE_WINDOW_HOURS = 6;

    public function __invoke(): JsonResponse
    {
        $database = $this->databaseAnswers();
        $migrated = $database && $this->migrationsRan();
        $healthy = $database && $migrated;

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'database' => $database,
            'migrations' => $migrated,
            /*
             * Null rather than false when the database is unreachable: we
             * do not know whether jobs are failing, and saying "no" to a
             * question we cannot answer is the one reply that could send
             * somebody looking in the wrong place.
             */
            'jobs_failing' => $healthy ? $this->jobsFailing() : null,
        ], $healthy ? 200 : 503);
    }

    private function databaseAnswers(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function migrationsRan(): bool
    {
        try {
            return DB::table('migrations')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function jobsFailing(): bool
    {
        try {
            return DB::table('failed_jobs')
                ->where('failed_at', '>', now()->subHours(self::FAILURE_WINDOW_HOURS))
                ->exists();
        } catch (Throwable) {
            /*
             * No failed_jobs table is not a failure. A fresh install has
             * not got one, and reporting trouble because a table is
             * missing would make every first deploy look broken.
             */
            return false;
        }
    }
}
