<?php

declare(strict_types=1);

namespace App\Services\Odds;

use App\Enums\JobStatus;
use App\Enums\Sport;
use App\Models\Event;
use App\Models\JobRun;
use Illuminate\Support\Carbon;

/**
 * Decides whether a sport is due for an odds pull.
 *
 * The scheduler ticks every few minutes; this class is what keeps a monthly
 * API quota from being spent on games that are days away. Pulls get more
 * frequent as kickoff approaches, because the closing line is the number
 * that matters for CLV.
 */
class PullCadence
{
    public function jobKey(Sport $sport): string
    {
        return "odds:pull:{$sport->value}";
    }

    public function propsJobKey(Sport $sport): string
    {
        return "odds:props:{$sport->value}";
    }

    public function isDue(Sport $sport, ?Carbon $now = null): bool
    {
        return $this->due($sport, $this->intervalMinutes($sport, $now), $this->jobKey($sport), $now);
    }

    /**
     * Props run on their own, looser clock and their own job key.
     *
     * Looser because a prop line moves on news rather than continuously,
     * and because this costs a call per game instead of a call per league:
     * on the core cadence a twelve-game Saturday would spend more credits
     * on props before lunch than the whole board spends in a week.
     */
    public function isDueForProps(Sport $sport, ?Carbon $now = null): bool
    {
        return $this->due($sport, $this->propsIntervalMinutes($sport, $now), $this->propsJobKey($sport), $now);
    }

    /** Minutes between pulls right now, or null when there is nothing to pull. */
    public function intervalMinutes(Sport $sport, ?Carbon $now = null): ?int
    {
        return $this->interval($sport, (array) config('edge.cadence'), $now);
    }

    /** The same, on the props clock. Null when props are off for the sport. */
    public function propsIntervalMinutes(Sport $sport, ?Carbon $now = null): ?int
    {
        if (! $sport->hasProps() || ! (bool) config('edge.odds_api.props.enabled')) {
            return null;
        }

        return $this->interval($sport, (array) config('edge.odds_api.props.cadence'), $now);
    }

    /** @param  array<int, array{within_minutes:int, every_minutes:int}>  $windows */
    private function interval(Sport $sport, array $windows, ?Carbon $now = null): ?int
    {
        $now ??= now();

        $nextStart = $this->nextStartAt($sport, $now);

        if ($nextStart === null) {
            return null;
        }

        $minutesAway = max(0, $now->diffInMinutes($nextStart, false));

        foreach ($windows as $window) {
            if ($minutesAway <= (int) $window['within_minutes']) {
                return (int) $window['every_minutes'];
            }
        }

        return null;
    }

    private function due(Sport $sport, ?int $interval, string $jobKey, ?Carbon $now = null): bool
    {
        if ($interval === null) {
            return false;
        }

        $now ??= now();
        $last = $this->lastSuccessfulRunAt($jobKey);

        return $last === null || $last->lte($now->copy()->subMinutes($interval));
    }

    public function nextStartAt(Sport $sport, ?Carbon $now = null): ?Carbon
    {
        $now ??= now();

        $value = Event::query()
            ->where('sport', $sport->value)
            ->where('starts_at', '>=', $now)
            ->orderBy('starts_at')
            ->value('starts_at');

        return $value ? Carbon::parse($value) : null;
    }

    public function lastSuccessfulPullAt(Sport $sport): ?Carbon
    {
        return $this->lastSuccessfulRunAt($this->jobKey($sport));
    }

    public function lastSuccessfulPropsPullAt(Sport $sport): ?Carbon
    {
        return $this->lastSuccessfulRunAt($this->propsJobKey($sport));
    }

    private function lastSuccessfulRunAt(string $jobKey): ?Carbon
    {
        $value = JobRun::query()
            ->where('job_key', $jobKey)
            ->where('status', JobStatus::Success)
            ->orderByDesc('started_at')
            ->value('started_at');

        return $value ? Carbon::parse($value) : null;
    }

    /**
     * Sports with a game starting inside the tightest window, used to decide
     * when closing lines need marking.
     *
     * @return array<int, Sport>
     */
    public function sportsStartingWithin(int $minutes, ?Carbon $now = null): array
    {
        $now ??= now();

        return array_values(array_filter(
            Sport::enabled(),
            function (Sport $sport) use ($minutes, $now): bool {
                $next = $this->nextStartAt($sport, $now);

                return $next !== null && $now->diffInMinutes($next, false) <= $minutes;
            }
        ));
    }
}
