<?php

declare(strict_types=1);

namespace App\Services\Engine\Bets;

use App\Enums\BetStatus;
use App\Enums\EventStatus;
use App\Enums\Market;
use App\Enums\Side;
use App\Models\Bet;
use App\Models\Event;

/**
 * Decides what a bet became once the game finished.
 *
 * Pure arithmetic on the final score, deliberately kept apart from BetLedger,
 * which is what writes the settlement down. This class answers "what
 * happened"; the ledger answers "record that it happened". Splitting them
 * means every grading rule here is testable without a database, a bankroll
 * or an append-only ledger in the way.
 *
 * It returns null rather than guessing whenever the answer is not knowable:
 * the game is not final, the score never arrived, a spread bet has no line
 * stored, or the side makes no sense for the market. A bet left pending is
 * visible and fixable; a bet graded wrong is a corrupted track record, and
 * the whole point of this app is that the track record can be trusted.
 */
class BetGrader
{
    /** How close two lines must be to count as the same number. */
    private const EPSILON = 0.001;

    /**
     * The outcome of this bet, or null if it cannot be graded yet.
     */
    public function outcomeFor(Bet $bet, ?Event $event = null): ?BetStatus
    {
        $event ??= $bet->event;

        if ($event === null || $bet->is_custom) {
            // A custom bet has no event behind it and no result to read.
            // It is graded by hand or not at all.
            return null;
        }

        if ($event->status === EventStatus::Canceled) {
            // Nobody played, so the stake comes back.
            return BetStatus::Void;
        }

        // Postponed games are usually replayed and the bet usually stands,
        // so this stays pending rather than voiding something that is about
        // to have a real result.
        if ($event->status !== EventStatus::Final) {
            return null;
        }

        $margin = $event->margin();
        $total = $event->totalPoints();

        if ($margin === null || $total === null) {
            return null;
        }

        return match ($bet->market) {
            Market::Moneyline => $this->moneyline($bet->side, $margin),
            Market::Spread => $this->spread($bet->side, $bet->line, $margin),
            Market::Total => $this->total($bet->side, $bet->line, $total),
            default => null,
        };
    }

    private function moneyline(?Side $side, int $margin): ?BetStatus
    {
        // A tie refunds both sides. It only happens in NFL regular season
        // here, but it happens.
        if ($margin === 0) {
            return $side === Side::Draw ? BetStatus::Won : BetStatus::Push;
        }

        return match ($side) {
            Side::Home => $margin > 0 ? BetStatus::Won : BetStatus::Lost,
            Side::Away => $margin < 0 ? BetStatus::Won : BetStatus::Lost,
            Side::Draw => BetStatus::Lost,
            default => null,
        };
    }

    private function spread(?Side $side, ?float $line, int $margin): ?BetStatus
    {
        // The line is stored from the backed side's own point of view, the
        // way the book quotes it: home -3, away +3. Without it there is
        // nothing to compare the margin against.
        if ($line === null || ! in_array($side, [Side::Home, Side::Away], true)) {
            return null;
        }

        $ownMargin = $side === Side::Home ? $margin : -$margin;

        return $this->verdict($ownMargin + $line);
    }

    private function total(?Side $side, ?float $line, int $total): ?BetStatus
    {
        if ($line === null || ! in_array($side, [Side::Over, Side::Under], true)) {
            return null;
        }

        $difference = $side === Side::Over ? $total - $line : $line - $total;

        return $this->verdict($difference);
    }

    /**
     * A margin against the line: over it wins, under it loses, exactly on it
     * is a push. Whole-number lines are the only way to land on a push, and
     * they are common enough that treating the epsilon case as a loss would
     * quietly cost real money.
     */
    private function verdict(float $difference): BetStatus
    {
        if (abs($difference) < self::EPSILON) {
            return BetStatus::Push;
        }

        return $difference > 0 ? BetStatus::Won : BetStatus::Lost;
    }
}
