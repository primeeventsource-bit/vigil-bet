<?php

declare(strict_types=1);

namespace App\Services\Parlay;

use App\Modeling\Odds\Parlay as ParlayMath;
use App\Models\BankrollEntry;
use App\Models\ParlayEvent;
use App\Models\ParlayLeg;
use App\Models\User;
use App\Models\UserParlay;
use App\Services\Bets\Bankroll;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes saved parlays, moves the bankroll, and keeps the audit trail.
 *
 * The counterpart to BetLedger, and separated from ParlaySettler for the
 * same reason BetLedger is separated from BetSettler: the settler decides
 * what happened, the ledger is the only thing that writes it. Money moves
 * in one place or it moves in several, and several is how a tracker ends
 * up with a balance nobody can explain.
 */
class ParlayLedger
{
    /** A parlay needs at least two legs to be a parlay. */
    public const MINIMUM_LEGS = 2;

    public function __construct(private readonly Bankroll $bankroll) {}

    /**
     * Save a parlay the user built.
     *
     * Saved as a draft unless they say it was placed. The spec is
     * explicit that a saved parlay must not be represented as a
     * real-money wager until the user records it as one, and a draft is
     * how that holds: no `placed_at`, no bankroll entry, and the settler
     * walks past it.
     *
     * @param  array<int, array<string, mixed>>  $legs
     * @param  array<string, mixed>  $attributes
     *
     * @throws RuntimeException when there is not a parlay to save
     */
    public function save(User $user, array $legs, array $attributes = []): UserParlay
    {
        if (count($legs) < self::MINIMUM_LEGS) {
            throw new RuntimeException('A parlay needs at least '.self::MINIMUM_LEGS.' legs.');
        }

        $stake = (int) ($attributes['stake_cents'] ?? 0);

        if ($stake < 1) {
            throw new RuntimeException('A parlay needs a stake.');
        }

        $placed = (bool) ($attributes['placed'] ?? false);

        return DB::transaction(function () use ($user, $legs, $attributes, $stake, $placed): UserParlay {
            $parlay = UserParlay::create([
                'user_id' => $user->id,
                'parlay_grade_id' => $attributes['parlay_grade_id'] ?? null,
                'stake_cents' => $stake,
                'computed_price_american' => ParlayMath::americanPrice(
                    array_map(static fn (array $leg): int => (int) $leg['price_american'], $legs)
                ),
                'accepted_price_american' => $attributes['accepted_price_american'] ?? null,
                'book' => $attributes['book'] ?? null,
                'book_title' => $attributes['book_title'] ?? null,
                'ticket_reference' => $attributes['ticket_reference'] ?? null,
                'is_paper' => (bool) ($attributes['is_paper'] ?? false),
                'status' => $placed ? 'pending' : 'draft',
                'placed_at' => $placed ? now() : null,
            ]);

            foreach ($legs as $leg) {
                ParlayLeg::create([
                    'user_parlay_id' => $parlay->id,
                    'edge_lab_event_id' => $leg['edge_lab_event_id'] ?? null,
                    'board_entry_id' => $leg['board_entry_id'] ?? null,
                    'sport' => $leg['sport'],
                    'market' => $leg['market'],
                    'side' => $leg['side'] ?? null,
                    'line' => $leg['line'] ?? null,
                    'selection' => $leg['selection'],
                    'price_american' => (int) $leg['price_american'],
                    'status' => 'pending',
                    /*
                     * The engine's view, frozen. Read live it would be
                     * rewritten by every retrain, and a comparison that
                     * changes after the game is not a comparison.
                     */
                    'model_probability' => $leg['model_probability'] ?? null,
                    'model_version' => $leg['model_version'] ?? null,
                    'model_edge' => $leg['model_edge'] ?? null,
                    'model_agreement' => $leg['model_agreement'] ?? null,
                ]);
            }

            $this->audit($parlay, $placed ? 'placed' : 'drafted', [
                'stake_cents' => $stake,
                'leg_count' => count($legs),
                'is_paper' => $parlay->is_paper,
            ], $user->id);

            if ($placed) {
                $this->debitStake($parlay);
            }

            return $parlay->fresh('legs');
        });
    }

    /**
     * Turn a draft into a recorded wager.
     *
     * The one moment money is said to be at risk, so it is the moment the
     * stake leaves the bankroll - as it does for a straight bet, and for
     * the same reason: money on a running bet is gone until it is not.
     */
    public function place(UserParlay $parlay, array $attributes = []): UserParlay
    {
        if ($parlay->status !== 'draft') {
            return $parlay;
        }

        return DB::transaction(function () use ($parlay, $attributes): UserParlay {
            $parlay->forceFill(array_filter([
                'accepted_price_american' => $attributes['accepted_price_american'] ?? null,
                'book' => $attributes['book'] ?? null,
                'book_title' => $attributes['book_title'] ?? null,
                'ticket_reference' => $attributes['ticket_reference'] ?? null,
            ], static fn ($value) => $value !== null) + [
                'status' => 'pending',
                'placed_at' => now(),
            ])->save();

            $this->audit($parlay, 'placed', [
                'accepted_price_american' => $parlay->accepted_price_american,
                'ticket_reference' => $parlay->ticket_reference,
            ], $parlay->user_id);

            $this->debitStake($parlay);

            return $parlay->refresh();
        });
    }

    /**
     * Record the outcome the settler worked out.
     *
     * Idempotent on `status`, because the settler runs on a schedule and
     * will meet this parlay again. A second settlement would write a
     * second bankroll entry and pay the customer twice on paper.
     *
     * @param  Collection<int, ParlayLeg>  $legs
     */
    public function settle(
        UserParlay $parlay,
        string $outcome,
        int $returnCents,
        Collection $legs,
        ?int $actorId = null,
        ?string $reason = null,
    ): UserParlay {
        if (! in_array($outcome, ['won', 'lost', 'push', 'void'], true)) {
            throw new RuntimeException('Unknown outcome: '.$outcome);
        }

        return DB::transaction(function () use ($parlay, $outcome, $returnCents, $legs, $actorId, $reason): UserParlay {
            /*
             * Locked and re-read, so two workers meeting the same parlay
             * cannot both find it unsettled and both pay it.
             */
            $fresh = UserParlay::query()->lockForUpdate()->find($parlay->id);

            if ($fresh === null || $fresh->status !== 'pending') {
                return $fresh ?? $parlay;
            }

            $fresh->forceFill([
                'status' => $outcome,
                'return_cents' => $returnCents,
                'settled_at' => now(),
            ])->save();

            $this->audit($fresh, 'settled', [
                'status' => $outcome,
                'return_cents' => $returnCents,
                'legs' => $legs->map(static fn (ParlayLeg $leg): array => [
                    'selection' => $leg->selection,
                    'status' => $leg->status,
                ])->all(),
            ], $actorId, $reason ?? ($actorId === null ? 'Graded from the final scores' : 'Settled by hand'));

            if ($returnCents > 0 && ! $fresh->is_paper) {
                $this->bankroll->record($fresh->user, BankrollEntry::RETURN_, $returnCents, [
                    'user_parlay_id' => $fresh->id,
                    'reason' => ucfirst($outcome).': '.$legs->count().'-leg parlay',
                ]);
            }

            return $fresh;
        });
    }

    /**
     * A paper parlay never touches the bankroll.
     *
     * Recording practice stakes against a real balance would ruin the one
     * figure a customer checks, and the whole point of allowing paper
     * bets is to try something without that cost.
     */
    private function debitStake(UserParlay $parlay): void
    {
        if ($parlay->is_paper) {
            return;
        }

        $this->bankroll->record($parlay->user, BankrollEntry::STAKE, -(int) $parlay->stake_cents, [
            'user_parlay_id' => $parlay->id,
            'reason' => 'Stake on '.$parlay->legs()->count().'-leg parlay',
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function audit(
        UserParlay $parlay,
        string $type,
        array $payload,
        ?int $actorId = null,
        ?string $reason = null,
    ): ParlayEvent {
        return ParlayEvent::create([
            'user_parlay_id' => $parlay->id,
            'user_id' => $parlay->user_id,
            'type' => $type,
            'payload' => $payload,
            'actor_id' => $actorId,
            'reason' => $reason,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
