<?php

declare(strict_types=1);

namespace App\Services\Bets;

use App\Models\UserBet;
use App\Models\VlEvent;
use App\Models\VlOddsDisplay;
use Illuminate\Support\Collection;

/**
 * Grades pending bets from the final score Edge Lab mirrored.
 *
 * Nothing is marked by hand here. The event log records whether a
 * settlement came from a score or from a person, and these are the ones
 * that came from a score - which is the difference between a track record
 * and a claim.
 *
 * A bet whose event has no final score stays pending. Guessing at an
 * outcome to clear a queue is how a record stops being one.
 */
class BetSettler
{
    public function __construct(private readonly BetLedger $ledger) {}

    /**
     * Settle everything that can be settled.
     *
     * @return array{settled:int, skipped:int, clv:int}
     */
    public function run(): array
    {
        $pending = UserBet::query()->pending()->get();

        $settled = 0;
        $skipped = 0;
        $clv = 0;

        foreach ($pending as $bet) {
            $event = $this->eventFor($bet);

            if ($event === null || $event->final_home_score === null || $event->final_away_score === null) {
                $skipped++;

                continue;
            }

            /*
             * The closing price first, while the bet is still pending.
             * Afterwards is fine too, but doing it first means a bet that
             * cannot be graded still gets its closing line value - the
             * two measurements are independent and losing one because
             * the other failed would be a waste.
             */
            if ($bet->closing_price_american === null
                && ($closing = $this->closingPrice($bet, $event)) !== null) {
                $this->ledger->recordClosingLine($bet, $closing);
                $clv++;
            }

            $outcome = $this->outcome($bet, (int) $event->final_home_score, (int) $event->final_away_score);

            if ($outcome === null) {
                // A market this product cannot grade. Left pending and
                // counted, so it shows up as a gap rather than as a
                // silently wrong record.
                $skipped++;

                continue;
            }

            $this->ledger->settle($bet, $outcome);
            $settled++;
        }

        return ['settled' => $settled, 'skipped' => $skipped, 'clv' => $clv];
    }

    private function eventFor(UserBet $bet): ?VlEvent
    {
        if ($bet->edge_lab_event_id === null) {
            return null;
        }

        return VlEvent::query()->current()->forEdgeLabId((int) $bet->edge_lab_event_id)->first();
    }

    /**
     * Who won, against the line.
     *
     * Null for a market this product cannot grade. That is honest: a
     * market nobody wrote a rule for should appear as an ungraded bet,
     * not as a loss.
     */
    public function outcome(UserBet $bet, int $homeScore, int $awayScore): ?string
    {
        $side = $bet->side;
        $line = $bet->line;

        return match ($bet->market) {
            // Moneyline. A tie is a push, which happens in the NFL and
            // would otherwise be graded as a loss for both sides.
            'h2h', 'moneyline' => match (true) {
                $homeScore === $awayScore => 'push',
                $side === 'home' => $homeScore > $awayScore ? 'won' : 'lost',
                $side === 'away' => $awayScore > $homeScore ? 'won' : 'lost',
                default => null,
            },

            /*
             * Spreads. The line is the handicap applied to the side that
             * was backed, so a home bet at -3.5 wins when the margin
             * beats 3.5. Exactly on the number is a push, which is why
             * books sell half-points.
             */
            'spreads', 'spread' => $line === null ? null : match ($side) {
                'home' => $this->versusLine(($homeScore - $awayScore) + (float) $line),
                'away' => $this->versusLine(($awayScore - $homeScore) + (float) $line),
                default => null,
            },

            // Totals. Both scores, against the number.
            'totals', 'total' => $line === null ? null : match ($side) {
                'over' => $this->versusLine(($homeScore + $awayScore) - (float) $line),
                'under' => $this->versusLine((float) $line - ($homeScore + $awayScore)),
                default => null,
            },

            default => null,
        };
    }

    /** Positive beats the line, zero ties it, negative misses. */
    private function versusLine(float $margin): string
    {
        return match (true) {
            $margin > 0 => 'won',
            $margin < 0 => 'lost',
            default => 'push',
        };
    }

    /**
     * The last price quoted before kickoff, at the book the bet was taken
     * at.
     *
     * Read from the stored quotes and from nowhere else. There is no
     * backfill call that fetches a closing price we never recorded: a
     * closing line value computed from a number nobody wrote down at the
     * time would be a fabricated measurement of exactly the thing this
     * product sells.
     */
    public function closingPrice(UserBet $bet, VlEvent $event): ?int
    {
        if ($event->starts_at === null) {
            return null;
        }

        $quotes = VlOddsDisplay::query()
            ->where('edge_lab_event_id', $bet->edge_lab_event_id)
            ->where('market', $bet->market)
            ->when($bet->side !== null, fn ($q) => $q->where('side', $bet->side))
            ->where('quoted_at', '<=', $event->starts_at)
            ->orderByDesc('quoted_at')
            ->get();

        if ($quotes->isEmpty()) {
            return null;
        }

        return $this->preferSameBook($quotes, $bet)?->price_american;
    }

    /**
     * The customer's own book if we have it, otherwise the latest
     * anywhere.
     *
     * Their book is the right comparison - closing line value is about
     * the price they could have got - but a product that stored no quote
     * from that book and therefore reported nothing would be less useful
     * than one that says which book it used.
     *
     * @param  Collection<int, VlOddsDisplay>  $quotes
     */
    private function preferSameBook(Collection $quotes, UserBet $bet): ?VlOddsDisplay
    {
        if ($bet->book !== null) {
            $own = $quotes->firstWhere('book', $bet->book);

            if ($own !== null) {
                return $own;
            }
        }

        return $quotes->first();
    }
}
