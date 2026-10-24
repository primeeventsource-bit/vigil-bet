<?php

declare(strict_types=1);

namespace App\Services\Engine\Bets;

use App\Enums\VigMethod;
use App\Modeling\Odds\Clv;
use App\Modeling\Odds\OddsConverter;
use App\Models\Bet;
use App\Models\EventLineMarker;
use Illuminate\Support\Collection;

/**
 * Writes closing line value onto a bet that has one.
 *
 * This is the honest scoreboard for a single bettor. Results need thousands
 * of bets to say anything; beating the closing line shows up in hundreds,
 * because it measures the decision rather than the outcome.
 *
 * Two rules shape everything here:
 *
 *  - The close is read at the book the bet was actually struck at. A price
 *    is only good or bad relative to what that same book went on to offer;
 *    comparing a Hard Rock price against a Pinnacle close measures the gap
 *    between two books, not the quality of the bet.
 *  - The value is stated against the *fair* close, with the hold stripped
 *    out of both sides. Against the raw closing price almost every bet looks
 *    positive, because the raw price contains the book's margin.
 *
 * When the number cannot be computed honestly the closing facts are still
 * recorded and the value is left null. That happens when the other side of
 * the market never closed, so there is no vig to remove, and when the line
 * itself moved on a spread or total - see fairCloseProbability.
 *
 * This writes only the four closing columns on the bets row. Money and
 * status belong to the bet_events ledger and are projected from it; nothing
 * here touches them.
 */
class ClvRecorder
{
    /** How close two lines must be to count as the same number. */
    private const EPSILON = 0.001;

    /**
     * Record what the market closed at and what the price was worth.
     *
     * @return bool whether anything was written
     */
    public function record(Bet $bet): bool
    {
        if ($bet->event_id === null || $bet->book_id === null || $bet->market === null || $bet->side === null) {
            return false;
        }

        $markers = EventLineMarker::query()
            ->where('event_id', $bet->event_id)
            ->where('book_id', $bet->book_id)
            ->where('market', $bet->market->value)
            ->get()
            ->keyBy(fn (EventLineMarker $marker) => $marker->side->value);

        $own = $markers->get($bet->side->value);

        if ($own === null || $own->price_close_american === null) {
            // The book never quoted this side before kickoff, or the closing
            // lines for this game have not been frozen yet. Either way there
            // is nothing to measure against.
            return false;
        }

        $closing = [
            'closing_price_american' => $own->price_close_american,
            'closing_line' => $own->line_close,
        ];

        $fair = $this->fairCloseProbability($bet, $markers, $own);

        if ($fair !== null) {
            $closing['clv_prob_delta'] = $fair - OddsConverter::americanToProbability($bet->price_american);
            $closing['clv_cents'] = Clv::expectedValueCents($bet->stake_cents, $bet->price_american, $fair);
        }

        $bet->forceFill($closing)->save();

        return true;
    }

    /**
     * The market's fair probability for the backed side at the close, or null
     * when it cannot be had.
     *
     * Removing the vig needs both sides of the market, so a one-sided close
     * gives nothing.
     *
     * On a spread or total it also needs the line to have stayed put. Taking
     * -3 and watching it close -3.5 is a real gain that the price alone does
     * not show - both might be -110 - and converting half a point into
     * probability takes a scoring model, not arithmetic. Rather than report
     * the price gap as if it were the whole value, this refuses the number
     * and leaves the closing line on the row so the move is visible.
     *
     * @param  Collection<string, EventLineMarker>  $markers
     */
    private function fairCloseProbability(Bet $bet, Collection $markers, EventLineMarker $own): ?float
    {
        $other = $markers->get($bet->side->opposite()->value);

        if ($other === null || $other->price_close_american === null) {
            return null;
        }

        if ($bet->market->hasLine() && ! $this->linesHeld($bet, $own, $other)) {
            return null;
        }

        $delta = Clv::versusFairClose(
            $bet->price_american,
            [
                $bet->side->value => $own->price_close_american,
                $bet->side->opposite()->value => $other->price_close_american,
            ],
            $bet->side->value,
            $this->vigMethod(),
        );

        if ($delta === null) {
            return null;
        }

        // versusFairClose returns fair minus the price taken, so adding the
        // price back gives the fair probability itself - the same number the
        // delta was built from, with no second vig calculation to disagree
        // with it.
        return $delta + OddsConverter::americanToProbability($bet->price_american);
    }

    /**
     * Did the line stay where the bet was struck, and do the two sides of
     * the close describe the same line.
     *
     * A spread is mirrored between the sides (-3 against +3) and a total is
     * identical on both. Either way, closing prices taken from two different
     * lines cannot have vig removed as a pair.
     */
    private function linesHeld(Bet $bet, EventLineMarker $own, EventLineMarker $other): bool
    {
        if ($bet->line === null || $own->line_close === null || $other->line_close === null) {
            return false;
        }

        if (abs($own->line_close - $bet->line) >= self::EPSILON) {
            return false;
        }

        // A spread is mirrored between the sides, a total is identical.
        $paired = $bet->side->isTeamSide()
            ? $own->line_close + $other->line_close
            : $own->line_close - $other->line_close;

        return abs($paired) < self::EPSILON;
    }

    private function vigMethod(): VigMethod
    {
        return VigMethod::tryFrom((string) config('edge.value.vig_method')) ?? VigMethod::Multiplicative;
    }
}
