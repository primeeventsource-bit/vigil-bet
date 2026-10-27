<?php

declare(strict_types=1);

namespace App\Jobs\Bets;

use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\Bet;
use App\Services\Engine\Bets\ClvRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Stamps closing line value onto placed bets once their market has closed.
 *
 * Runs after MarkClosingLines, which is what freezes the close this reads.
 *
 * Two kinds of bet are picked up. Anything never recorded, however old,
 * because a bet whose CLV was missed is permanently missing from the only
 * metric that proves an edge inside one person's sample. And anything recent
 * that has a closing price but no value yet, because the other side of the
 * market may have closed since - MarkClosingLines keeps revisiting recent
 * games, so a one-sided close often becomes a two-sided one an hour later.
 */
class RecordBetClv implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public int $tries = 2;

    // Not an immediate retry. Both the database and the application
    // hibernate, so the failure this guards against is a connection
    // dropped by something that is still waking up - and a second
    // attempt in the same second meets exactly the same dead socket.
    public int $backoff = 60;

    public function __construct(
        public readonly int $retryHours = 72,
        public readonly int $maxBets = 500,
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('bets:clv'))->expireAfter(900)];
    }

    public function handle(ClvRecorder $recorder): void
    {
        $this->tracked('bets:clv', function () use ($recorder): array {
            $bets = Bet::query()
                ->whereNotNull('event_id')
                ->whereNotNull('book_id')
                ->whereHas('event', fn ($q) => $q->where('starts_at', '<', now()))
                ->where(fn ($q) => $q
                    ->whereNull('closing_price_american')
                    ->orWhere(fn ($pending) => $pending
                        ->whereNull('clv_prob_delta')
                        ->whereHas('event', fn ($e) => $e->where('starts_at', '>=', now()->subHours($this->retryHours)))
                    )
                )
                ->orderBy('placed_at')
                ->limit($this->maxBets)
                ->get();

            $recorded = 0;

            foreach ($bets as $bet) {
                if ($recorder->record($bet)) {
                    $recorded++;
                }
            }

            return [
                'records_in' => $bets->count(),
                'records_out' => $recorded,
                // Bets whose close is still unreadable. Expected while a
                // slate is live; a number that never falls means the book
                // is not being pulled close enough to kickoff.
                'awaiting_close' => $bets->count() - $recorded,
            ];
        });
    }
}
