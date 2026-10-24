<?php

declare(strict_types=1);

namespace App\Jobs\Bets;

use App\Enums\EventStatus;
use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\Bet;
use App\Services\Engine\Bets\BetGrader;
use App\Services\Engine\Bets\BetLedger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Settles every pending bet whose game has finished.
 *
 * Runs after results land, so a graded bet follows the final whistle by at
 * most an hour without anyone opening the app. Grading is append-only like
 * everything else: this adds a settlement event to the ledger and the bets
 * row is reprojected from it.
 *
 * There is no lookback window. A bet stays pending until something grades
 * it, however old, because an ungraded bet is money missing from the track
 * record and a window would eventually drop it for good.
 */
class SettleBets implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public int $tries = 2;

    public function __construct(
        public readonly int $maxBets = 500,
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('bets:settle'))->expireAfter(900)];
    }

    public function handle(BetGrader $grader, BetLedger $ledger): void
    {
        $this->tracked('bets:settle', function () use ($grader, $ledger): array {
            $bets = Bet::query()
                ->pending()
                ->whereNotNull('event_id')
                ->whereHas('event', fn ($q) => $q->whereIn('status', [
                    EventStatus::Final->value,
                    EventStatus::Canceled->value,
                ]))
                ->with('event')
                ->orderBy('placed_at')
                ->limit($this->maxBets)
                ->get();

            $settled = 0;
            $undecidable = [];
            $failed = [];

            foreach ($bets as $bet) {
                $outcome = $grader->outcomeFor($bet);

                if ($outcome === null) {
                    // A final game whose bet still cannot be graded means
                    // something is missing - no score, no line stored - and
                    // it needs a person, so it is named rather than counted.
                    $undecidable[] = $bet->id;

                    continue;
                }

                try {
                    $ledger->grade($bet, $outcome);
                    $settled++;
                } catch (Throwable $e) {
                    // One unsettleable bet must not strand the rest of the
                    // slate, but it does not disappear either: it lands on
                    // the Jobs screen with its reason attached.
                    $failed[$bet->id] = $e->getMessage();
                }
            }

            return [
                'records_in' => $bets->count(),
                'records_out' => $settled,
                'undecidable_bet_ids' => $undecidable,
                'failed_bets' => $failed,
            ];
        });
    }
}
