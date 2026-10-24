<?php

declare(strict_types=1);

namespace App\Jobs\Odds;

use App\Enums\EventStatus;
use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\Event;
use App\Models\JobRun;
use App\Services\Odds\PropSnapshotWriter;
use App\Services\Odds\PullCadence;
use App\Services\Odds\TheOddsApiClient;
use App\Services\Ops\JobRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Player props for every game of one sport that is close enough to have
 * them posted.
 *
 * Props cost a call per game rather than a call per league, which is the
 * single fact that shapes this job: a horizon so the call is not spent on a
 * game no book has priced yet, a ceiling on how many games one run will
 * touch, and a budget check before any of it.
 *
 * Nothing here can produce a pick. Prop rows are written with a market key
 * and no market, and every reader that feeds a prediction asks for one of
 * the three core markets by name.
 */
class PullPropsForSport implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly Sport $sport,
        public readonly bool $force = false,
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('odds-props:'.$this->sport->value))->expireAfter(600)];
    }

    public function handle(
        TheOddsApiClient $client,
        PropSnapshotWriter $writer,
        PullCadence $cadence,
        JobRunRecorder $recorder,
    ): void {
        $this->tracked(
            $cadence->propsJobKey($this->sport),
            function (JobRun $run) use ($client, $writer, $cadence, $recorder): array {
                $markets = $this->sport->propMarkets();

                if (! (bool) config('edge.odds_api.props.enabled')) {
                    $recorder->skip($run, 'Player props are switched off.');

                    return [];
                }

                if ($markets === []) {
                    $recorder->skip($run, "No prop markets configured for {$this->sport->value}.");

                    return [];
                }

                if (! $client->isConfigured()) {
                    $recorder->skip($run, 'ODDS_API_KEY is not set.');

                    return [];
                }

                if (! $this->force && ! $cadence->isDueForProps($this->sport)) {
                    $recorder->skip($run, 'Not due yet under the props cadence.', [
                        'interval_minutes' => $cadence->propsIntervalMinutes($this->sport),
                    ]);

                    return [];
                }

                if (! $client->hasBudget()) {
                    $recorder->skip($run, 'Monthly API quota reserve reached; refusing to pull props.');

                    return [];
                }

                $events = $this->eventsInRange();

                if ($events === []) {
                    $recorder->skip($run, 'No games inside the props horizon.');

                    return [];
                }

                $snapshots = 0;
                $withProps = 0;
                $pulled = 0;
                $mismatched = 0;
                $expired = 0;
                $stoppedEarly = false;

                foreach ($events as $event) {
                    /*
                     * Checked every game rather than once up front: this
                     * loop is what spends the credits, and a long slate can
                     * cross the reserve part-way through.
                     *
                     * Stopping is not a skip. Whatever has already been
                     * written is real and gets reported as written; the run
                     * succeeds and says in its context why it stopped
                     * short, which a skipped run - having written nothing
                     * by definition - has no way to say.
                     */
                    if (! $client->hasBudget()) {
                        $stoppedEarly = true;

                        break;
                    }

                    /*
                     * A dead event id is about this game and nothing else.
                     *
                     * The provider drops an event from its index around the
                     * time it starts and answers 422 for the id afterwards,
                     * and our own schedule can still have the game as
                     * upcoming for a few minutes either side. That threw out
                     * of the loop and failed the whole run, so one game that
                     * had just kicked off cost every other game on the slate
                     * its props - four runs in six minutes on the night this
                     * was found, each losing a full NFL card.
                     *
                     * Only 404 and 422 are caught, and only here. A 401 is
                     * the key, a 429 is the allowance and a 5xx is the
                     * provider: none of those is about one fixture, and all
                     * of them should still fail the run loudly.
                     */
                    try {
                        $props = $client->propsForEvent(
                            $this->sport,
                            (string) $event->odds_api_event_id,
                            $markets,
                            $run->id,
                        );
                    } catch (RequestException $e) {
                        $status = $e->response->status();

                        if ($status !== 404 && $status !== 422) {
                            throw $e;
                        }

                        $expired++;

                        continue;
                    }

                    $written = $writer->write($props);
                    $pulled++;
                    $snapshots += $written['snapshots'];
                    $withProps += $written['snapshots'] > 0 ? 1 : 0;
                    $mismatched += $props->isForRequestedEvent() ? 0 : 1;
                }

                return [
                    'records_in' => $pulled,
                    'records_out' => $snapshots,
                    'events_in_range' => count($events),
                    'events_with_props' => $withProps,
                    'markets_requested' => count($markets),
                    'stopped_on_budget' => $stoppedEarly,
                    // Should always be zero. If it is not, the provider
                    // answered about a different game than the URL asked
                    // for and those prices were dropped rather than filed
                    // under the wrong fixture.
                    'wrong_event_returned' => $mismatched,
                    // Games the provider no longer holds an id for, which
                    // is ordinary near kickoff and worth seeing if it is not.
                    'event_id_expired' => $expired,
                ];
            },
            $this->sport,
        );
    }

    /**
     * Games close enough that a book has probably posted props, and that we
     * hold an Odds API id for. Without the id there is nothing to ask about.
     *
     * @return array<int, Event>
     */
    private function eventsInRange(): array
    {
        return Event::query()
            ->where('sport', $this->sport->value)
            ->where('status', EventStatus::Scheduled)
            ->whereNotNull('odds_api_event_id')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours((int) config('edge.odds_api.props.horizon_hours')))
            ->orderBy('starts_at')
            ->limit((int) config('edge.odds_api.props.max_events_per_run'))
            ->get()
            ->all();
    }
}
