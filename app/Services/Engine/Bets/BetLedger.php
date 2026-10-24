<?php

declare(strict_types=1);

namespace App\Services\Engine\Bets;

use App\Enums\BetEventType;
use App\Enums\BetStatus;
use App\Enums\LedgerEntryType;
use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use App\Modeling\Odds\OddsConverter;
use App\Models\BankrollLedgerEntry;
use App\Models\Bet;
use App\Models\BetEvent;
use App\Models\Event;
use App\Models\ValueBet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records bets.
 *
 * bet_events is the truth and is append-only; the bets row is a projection
 * rebuilt from it. A mistake is corrected by appending a correction, never
 * by editing what was written, because the whole value of a track record is
 * that it cannot be tidied up after the fact.
 *
 * The price is stored exactly as entered. If the bet slip said -115, that is
 * what gets recorded, whatever the app believed the price was a moment
 * earlier.
 */
class BetLedger
{
    public function place(
        Event $event,
        Market $market,
        Side $side,
        int $priceAmerican,
        int $stakeCents,
        ?float $line = null,
        ?int $bookId = null,
        ?float $modelProbability = null,
        bool $isPaper = false,
        ?string $notes = null,
        ?ValueBet $from = null,
    ): Bet {
        if (! OddsConverter::isValidAmerican($priceAmerican)) {
            throw new InvalidArgumentException("Invalid American price: {$priceAmerican}.");
        }

        if ($stakeCents <= 0) {
            throw new InvalidArgumentException('A bet needs a stake above zero.');
        }

        return DB::transaction(function () use (
            $event, $market, $side, $priceAmerican, $stakeCents, $line, $bookId, $modelProbability, $isPaper, $notes, $from
        ): Bet {
            $bet = Bet::create([
                'event_id' => $event->id,
                'sport' => $event->sport instanceof Sport ? $event->sport->value : $event->sport,
                'market' => $market->value,
                'side' => $side->value,
                'line' => $line,
                'book_id' => $bookId,
                'price_american' => $priceAmerican,
                'price_decimal' => OddsConverter::americanToDecimal($priceAmerican),
                'stake_cents' => $stakeCents,
                'placed_at' => now(),
                'status' => BetStatus::Pending->value,
                'is_paper' => $isPaper,
                'model_prob_at_placement' => $modelProbability ?? $from?->model_prob,
                'notes' => $notes,
            ] + $this->provenance($from));

            BetEvent::create([
                'bet_id' => $bet->id,
                'sequence' => 1,
                'type' => BetEventType::Placed->value,
                'payload' => [
                    'price_american' => $priceAmerican,
                    'stake_cents' => $stakeCents,
                    'market' => $market->value,
                    'side' => $side->value,
                    'line' => $line,
                ],
                'stake_delta_cents' => $stakeCents,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            // Paper bets never touch the bankroll; that is what makes them
            // paper. Real ones reserve the stake immediately.
            if (! $isPaper) {
                $this->appendLedger(LedgerEntryType::BetStake, -$stakeCents, "Stake on bet {$bet->id}");
            }

            return $bet;
        });
    }

    /**
     * Settle a bet. Appends to the ledger and rebuilds the projection.
     */
    public function grade(Bet $bet, BetStatus $outcome, ?int $payoutCents = null): Bet
    {
        if ($bet->status !== BetStatus::Pending) {
            throw new InvalidArgumentException("Bet {$bet->id} is already settled.");
        }

        $payoutCents ??= match ($outcome) {
            BetStatus::Won => (int) round($bet->stake_cents * $bet->price_decimal),
            BetStatus::Push, BetStatus::Void => $bet->stake_cents,
            default => 0,
        };

        $pnl = $payoutCents - $bet->stake_cents;

        return DB::transaction(function () use ($bet, $outcome, $payoutCents, $pnl): Bet {
            BetEvent::create([
                'bet_id' => $bet->id,
                'sequence' => ($bet->events()->max('sequence') ?? 0) + 1,
                'type' => $outcome === BetStatus::Void ? BetEventType::Voided->value : BetEventType::Graded->value,
                'payload' => ['outcome' => $outcome->value, 'payout_cents' => $payoutCents],
                'pnl_delta_cents' => $pnl,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            $bet->forceFill([
                'status' => $outcome->value,
                'settled_at' => now(),
                'payout_cents' => $payoutCents,
                'pnl_cents' => $pnl,
            ])->save();

            if (! $bet->is_paper && $payoutCents > 0) {
                $this->appendLedger(LedgerEntryType::BetPayout, $payoutCents, "Payout on bet {$bet->id}");
            }

            return $bet;
        });
    }

    /**
     * Where this bet came from, if it came from a pick.
     *
     * Recording the scan row the bet was taken off makes the track record
     * answerable later: what the model said at the time, what the market's
     * fair price was, and how big the edge looked. Those are frozen onto
     * the bet because the scan that produced them is one of many and the
     * board moves, so looking them up afterwards would give a different
     * answer than the one the decision was made on.
     *
     * @return array<string, mixed>
     */
    private function provenance(?ValueBet $from): array
    {
        if ($from === null) {
            return [];
        }

        return [
            'value_bet_id' => $from->id,
            'prediction_id' => $from->prediction_id,
            'model_version_id' => $from->model_version_id,
            'fair_prob_at_placement' => $from->fair_prob,
            // The edge at the price actually taken, not the edge against
            // the consensus: that is the one that described this bet.
            'edge_at_placement' => $from->edge_at_price,
        ];
    }

    private function appendLedger(LedgerEntryType $type, int $amountCents, string $note): void
    {
        $balance = BankrollLedgerEntry::currentBalanceCents();

        BankrollLedgerEntry::create([
            'entry_type' => $type->value,
            'amount_cents' => $amountCents,
            'balance_after_cents' => $balance + $amountCents,
            'occurred_at' => now(),
            'note' => $note,
            'created_at' => now(),
        ]);
    }
}
