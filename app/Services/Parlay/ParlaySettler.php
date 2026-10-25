<?php

declare(strict_types=1);

namespace App\Services\Parlay;

use App\Modeling\Odds\OddsConverter;
use App\Models\ParlayLeg;
use App\Models\UserBet;
use App\Models\UserParlay;
use App\Models\VlEvent;
use App\Services\Bets\BetSettler;
use Illuminate\Support\Collection;

/**
 * Grades saved parlays against the games they were built from.
 *
 * Each leg is the same claim about a game as a straight bet, so the leg
 * grader is the straight-bet grader - a transient UserBet carrying the
 * selection, handed to BetSettler, exactly as OpinionSettler already does
 * it. A second grader for the same question would be a second place for
 * the answer to go wrong.
 *
 * What is genuinely parlay-specific is two things, and they are the two a
 * home-made tracker gets wrong:
 *
 *   - A pushed or voided leg does not lose the parlay, it shortens it.
 *     Every sportsbook drops the leg and reprices on what is left, and
 *     counting it as a loss understates a bettor's record more than any
 *     other single mistake.
 *
 *   - The payout comes from the price the sportsbook accepted, not the
 *     product of the legs as we priced them. Those differ by the book's
 *     margin, and settling on ours would invent money.
 */
class ParlaySettler
{
    public function __construct(
        private readonly BetSettler $legGrader,
        private readonly ParlayLedger $ledger,
    ) {}

    /**
     * Settle everything that can be settled.
     *
     * @return array{settled:int, pending:int}
     */
    public function run(): array
    {
        $settled = 0;
        $pending = 0;

        foreach (UserParlay::query()->pending()->with('legs')->get() as $parlay) {
            $this->settleParlay($parlay);

            $parlay->refresh()->status === 'pending' ? $pending++ : $settled++;
        }

        return ['settled' => $settled, 'pending' => $pending];
    }

    /**
     * Grade one parlay: its legs first, then the parlay itself.
     *
     * Wrapped in a transaction and guarded on `settled_at`, because this
     * runs on a schedule and will meet the same parlay again. A second
     * settlement would write a second audit row and a second bankroll
     * entry, paying the customer twice on paper.
     */
    public function settleParlay(UserParlay $parlay): void
    {
        // A draft is not a wager. Settling one would put money on a bet
        // nobody made.
        if ($parlay->status !== 'pending') {
            return;
        }

        $this->settleLegs($parlay);

        $legs = $parlay->legs()->get();

        /*
         * Vacuously, every leg of an empty parlay won. Left pending
         * rather than paid out on nothing.
         */
        if ($legs->isEmpty()) {
            return;
        }

        $outcome = $this->outcomeFor($legs);

        if ($outcome === null) {
            return;
        }

        /*
         * The ledger does the writing. The settler decides what happened
         * and nothing else, so the bankroll moves in one place - which is
         * the difference between a balance that can be explained and one
         * that cannot.
         */
        $settled = $this->ledger->settle(
            $parlay,
            $outcome,
            $this->returnCents($parlay, $legs, $outcome),
            $legs,
        );

        $parlay->setRawAttributes($settled->getAttributes());
    }

    /**
     * Grade any leg whose game has finished.
     *
     * A leg we cannot grade stays pending rather than being guessed at,
     * which holds the whole parlay - the honest answer for a parlay whose
     * outcome genuinely is not known yet.
     */
    private function settleLegs(UserParlay $parlay): void
    {
        foreach ($parlay->legs()->pending()->get() as $leg) {
            $event = $this->eventFor($leg);

            if ($event === null || $event->final_home_score === null || $event->final_away_score === null) {
                continue;
            }

            $outcome = $this->legGrader->outcome(
                new UserBet([
                    'market' => $leg->market,
                    'side' => $leg->side,
                    'line' => $leg->line,
                    'price_american' => $leg->price_american,
                    'stake_cents' => 100,
                ]),
                (int) $event->final_home_score,
                (int) $event->final_away_score,
            );

            if ($outcome === null) {
                // A market this product has no rule for. Counted nowhere
                // and left visible as ungraded rather than called a loss.
                continue;
            }

            $leg->forceFill(['status' => $outcome, 'settled_at' => now()])->save();
        }
    }

    /**
     * The parlay's outcome, or null while it is undecided.
     *
     * @param  Collection<int, ParlayLeg>  $legs
     */
    private function outcomeFor($legs): ?string
    {
        /*
         * A lost leg decides the parlay on its own, so this is checked
         * before anything else. Waiting on the remaining legs would leave
         * a known loss sitting in the pending column.
         */
        if ($legs->contains(fn (ParlayLeg $leg) => $leg->status === 'lost')) {
            return 'lost';
        }

        if ($legs->contains(fn (ParlayLeg $leg) => $leg->status === 'pending')) {
            return null;
        }

        $counting = $legs->filter(fn (ParlayLeg $leg) => $leg->counts());

        /*
         * Nothing was decided. Recorded as a push rather than a win: the
         * money is the same, and a parlay that beat nobody should not
         * raise a win rate.
         */
        if ($counting->isEmpty()) {
            return 'push';
        }

        return 'won';
    }

    /**
     * What comes back, stake included.
     *
     * @param  Collection<int, ParlayLeg>  $legs
     */
    private function returnCents(UserParlay $parlay, $legs, string $outcome): int
    {
        if ($outcome === 'lost') {
            return 0;
        }

        $stake = (int) $parlay->stake_cents;

        if ($outcome === 'push') {
            return $stake;
        }

        $price = $parlay->payoutPriceAmerican();

        if ($price === null) {
            return $stake;
        }

        $decimal = OddsConverter::americanToDecimal($price);

        /*
         * Dropped legs are divided out of the accepted price.
         *
         * A parlay's decimal price is the product of its legs, so a book
         * removing a leg divides that leg's decimal back out - which is
         * what this does, and it leaves the book's margin spread across
         * the legs that remain rather than refunding it. Recomputing from
         * the surviving legs' own prices instead would quietly hand back
         * the margin and overstate the payout.
         */
        foreach ($legs as $leg) {
            if ($leg->counts()) {
                continue;
            }

            $legDecimal = OddsConverter::americanToDecimal((int) $leg->price_american);

            if ($legDecimal > 0.0) {
                $decimal /= $legDecimal;
            }
        }

        return (int) round($stake * $decimal);
    }

    private function eventFor(ParlayLeg $leg): ?VlEvent
    {
        if ($leg->edge_lab_event_id === null) {
            return null;
        }

        return VlEvent::query()->current()->forEdgeLabId((int) $leg->edge_lab_event_id)->first();
    }
}
