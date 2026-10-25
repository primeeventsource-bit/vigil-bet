<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\EventStatus;
use App\Enums\Sport;
use App\Models\Event;
use App\Models\JobRun;
use Illuminate\Support\Carbon;

/**
 * Decides whether a sport is due for a results pull.
 *
 * The same arrangement as PullCadence and for the opposite reason. That
 * one asks how close the next kickoff is, because a closing line exists
 * for a few minutes and is gone. This one asks whether a game that
 * should have finished is still missing its score, because that is the
 * only state in which pulling results achieves anything at all.
 *
 * Run hourly regardless, a game ending at ten past is not graded until
 * the next pass - and everything downstream waits on it, so the public
 * page says `pending` for over an hour about a game anyone can look up.
 * Run every five minutes regardless, a sport whose results come from a
 * metered feed spends its quota overnight on a slate that finished
 * yesterday.
 *
 * So frequency follows whether there is an answer outstanding. The chase
 * is bounded at both ends: a game is not expected to have a score until
 * it could plausibly have finished, and is stopped being chased some
 * hours later. Without that second bound one postponement nothing ever
 * scores would hold a sport at the fast cadence indefinitely, which is
 * the failure mode a cadence class exists to prevent.
 */
class ResultsCadence
{
    public function jobKey(Sport $sport): string
    {
        return "ingest:results:{$sport->value}";
    }

    public function isDue(Sport $sport, ?Carbon $now = null): bool
    {
        $now ??= now();
        $last = $this->lastRunAt($this->jobKey($sport));

        return $last === null
            || $last->lte($now->copy()->subMinutes($this->intervalMinutes($sport, $now)));
    }

    /** Minutes between pulls right now. */
    public function intervalMinutes(Sport $sport, ?Carbon $now = null): int
    {
        return $this->isChasing($sport, $now)
            ? (int) config('edge.results.chasing_minutes')
            : (int) config('edge.results.idle_minutes');
    }

    /**
     * Is a game that should have finished still without a score?
     *
     * Canceled and postponed games are not waiting on anything, so they
     * are excluded rather than chased for the rest of the window.
     */
    public function isChasing(Sport $sport, ?Carbon $now = null): bool
    {
        $now ??= now();

        return Event::query()
            ->where('sport', $sport->value)
            ->whereNull('final_home_score')
            ->whereNotIn('status', [EventStatus::Canceled->value, EventStatus::Postponed->value])
            ->whereBetween('starts_at', [
                $now->copy()->subHours((int) config('edge.results.chase_for_hours')),
                $now->copy()->subMinutes((int) config('edge.results.finished_after_minutes')),
            ])
            ->exists();
    }

    /**
     * When this sport's results were last looked at, successful or not.
     *
     * PullCadence counts only successful runs, because a failed odds pull
     * should be retried at once - the price it missed is perishable. A
     * result is not: it will still be there in five minutes, and a sport
     * with no results source at all records a skip every time it is
     * asked. Counting those keeps a permanently skipping sport on its
     * interval instead of retrying as fast as the tick allows.
     */
    public function lastRunAt(string $jobKey): ?Carbon
    {
        $value = JobRun::query()
            ->where('job_key', $jobKey)
            ->orderByDesc('started_at')
            ->value('started_at');

        return $value ? Carbon::parse($value) : null;
    }
}
