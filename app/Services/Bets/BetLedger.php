<?php

declare(strict_types=1);

namespace App\Services\Bets;

use App\Models\BankrollEntry;
use App\Models\User;
use App\Models\UserBet;
use App\Models\UserBetEvent;
use App\Models\VlBoardEntry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Logs and settles a customer's own bets.
 *
 * Nothing here places a bet. Vigil holds no money, has no sportsbook and
 * cannot move a cent at anybody's book - these rows record that a
 * customer says they backed a selection at a price, so that their record,
 * their ROI and their closing line value have something to measure.
 *
 * Every change is an event first and a projection second. §11 asks for
 * that shape and the reason is evidential: a track record is a claim
 * about the past, and a claim about the past that lives in a mutable row
 * is not evidence of anything. It also means a settlement bug can be
 * fixed and replayed rather than leaving every affected row wrong for
 * ever.
 */
class BetLedger
{
    public function __construct(private readonly Bankroll $bankroll) {}

    /**
     * Log a bet taken from the board.
     *
     * The gate is checked here as well as on the page, because a board
     * entry id is a number anybody can type into a form.
     *
     * @throws RuntimeException when the selection is not sellable or a limit is hit
     */
    public function fromBoardEntry(User $user, VlBoardEntry $entry, int $stakeCents): UserBet
    {
        if (! $entry->isSellablePick()) {
            throw new RuntimeException('That selection is not a pick from a proven market.');
        }

        if ($entry->best_price_american === null) {
            throw new RuntimeException('That selection has no price stored to log against.');
        }

        return $this->place($user, [
            'board_entry_id' => $entry->id,
            'edge_lab_event_id' => $entry->edge_lab_event_id,
            'sport' => $entry->sport,
            'market' => $entry->market,
            'side' => $entry->side,
            'line' => $entry->line,
            'selection' => $entry->selection,
            'price_american' => (int) $entry->best_price_american,
            'book' => $entry->best_book,
            'book_title' => $entry->best_book_title,
            'stake_cents' => $stakeCents,
            'source' => 'board',
        ]);
    }

    /**
     * Log a bet the customer entered by hand.
     *
     * No board entry, so no model probability and no closing line value.
     * Those stay null rather than being estimated - a CLV figure derived
     * from a price nobody recorded would be a fabricated measurement of
     * exactly the thing the product is selling.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function manual(User $user, array $attributes): UserBet
    {
        return $this->place($user, array_merge($attributes, ['source' => 'manual']));
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws RuntimeException
     */
    private function place(User $user, array $attributes): UserBet
    {
        $stake = (int) ($attributes['stake_cents'] ?? 0);

        if ($stake <= 0) {
            throw new RuntimeException('A bet needs a stake.');
        }

        /*
         * The loss limit, with no way round it. Checked here rather than
         * only in the controller so that every path into the ledger -
         * form, import, parlay - hits the same wall.
         */
        if (($blocked = $this->bankroll->blockedReason($user)) !== null) {
            throw new RuntimeException($blocked);
        }

        return DB::transaction(function () use ($user, $attributes, $stake): UserBet {
            $bet = UserBet::create(array_merge($attributes, [
                'user_id' => $user->id,
                'status' => 'pending',
                'placed_at' => $attributes['placed_at'] ?? now(),
            ]));

            $event = $this->event($bet, UserBetEvent::PLACED, [
                'price_american' => $bet->price_american,
                'stake_cents' => $stake,
                'selection' => $bet->selection,
            ], $user->id, 'Logged by the customer');

            // The stake leaves the bankroll now, not at settlement. Money
            // on a running bet is money that is gone until it is not.
            $this->bankroll->record($user, BankrollEntry::STAKE, -$stake, [
                'user_bet_id' => $bet->id,
                'reason' => 'Stake on '.$bet->selection,
            ]);

            $bet->update(['last_event_id' => $event->id]);

            return $bet->refresh();
        });
    }

    /**
     * Settle a bet.
     *
     * `push` and `void` both return the stake and are not the same thing:
     * a push is a tie against the line and a void is a game that never
     * ran. Only the push belongs in a record, because only the push was
     * a prediction that got to be tested. Conflating them would quietly
     * flatter the sample.
     *
     * @throws RuntimeException on an unknown outcome
     */
    public function settle(UserBet $bet, string $outcome, ?int $actorId = null, ?string $reason = null): UserBet
    {
        if (! in_array($outcome, ['won', 'lost', 'push', 'void'], true)) {
            throw new RuntimeException('Unknown outcome: '.$outcome);
        }

        if ($bet->isSettled()) {
            // Idempotent on purpose: the settler runs on a schedule and
            // re-settling would double the return in the ledger.
            return $bet;
        }

        $stake = (int) $bet->stake_cents;

        $return = match ($outcome) {
            'won' => $bet->potentialReturnCents(),
            'lost' => 0,
            // Stake back, nothing made.
            'push', 'void' => $stake,
        };

        return DB::transaction(function () use ($bet, $outcome, $return, $actorId, $reason): UserBet {
            $event = $this->event($bet, $outcome === 'void' ? UserBetEvent::VOIDED : UserBetEvent::SETTLED, [
                'outcome' => $outcome,
                'return_cents' => $return,
            ], $actorId, $reason ?? ($actorId === null ? 'Graded from the final score' : 'Settled by hand'));

            $bet->update([
                'status' => $outcome,
                'return_cents' => $return,
                'settled_at' => now(),
                'last_event_id' => $event->id,
            ]);

            if ($return > 0) {
                $this->bankroll->record($bet->user, BankrollEntry::RETURN_, $return, [
                    'user_bet_id' => $bet->id,
                    'reason' => ucfirst($outcome).': '.$bet->selection,
                ]);
            }

            return $bet->refresh();
        });
    }

    /**
     * Record the closing price and the value against it.
     *
     * Closing line value is the most honest measure of whether a bet was
     * good, because it does not depend on whether it won. A customer who
     * beat the close consistently and lost money had variance; one who
     * lost to the close and made money got lucky, and only one of those
     * is repeatable.
     *
     * Stored as the difference in no-vig implied probability, so it is
     * comparable across prices and sports.
     */
    public function recordClosingLine(UserBet $bet, int $closingPriceAmerican): UserBet
    {
        $taken = $bet->impliedProbability();

        $closing = $closingPriceAmerican > 0
            ? 100 / ($closingPriceAmerican + 100)
            : abs($closingPriceAmerican) / (abs($closingPriceAmerican) + 100);

        /*
         * Positive means the price shortened after it was taken - the
         * market came to agree, and the bet was ahead of it.
         */
        $clv = round($closing - $taken, 5);

        $event = $this->event($bet, UserBetEvent::CLV_RECORDED, [
            'closing_price_american' => $closingPriceAmerican,
            'clv' => $clv,
        ], null, 'Closing price read from the stored quotes');

        $bet->update([
            'closing_price_american' => $closingPriceAmerican,
            'clv' => $clv,
            'last_event_id' => $event->id,
        ]);

        return $bet->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(UserBet $bet, string $type, array $payload, ?int $actorId, ?string $reason): UserBetEvent
    {
        return UserBetEvent::create([
            'user_bet_id' => $bet->id,
            'user_id' => $bet->user_id,
            'type' => $type,
            'payload' => $payload,
            'actor_id' => $actorId,
            'reason' => $reason,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
