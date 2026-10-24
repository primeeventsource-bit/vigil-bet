<?php

declare(strict_types=1);

namespace App\Services\Record;

use App\Models\Event;
use App\Models\EventLineMarker;
use App\Models\ShadowPick;
use App\Models\ShadowResult;
use App\Models\UserBet;
use App\Models\ValueBet;
use App\Services\Bets\BetSettler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Records what the engine would have bet, and how it turned out.
 *
 * The verdict gate is a closed loop as it stands. A market becomes proven
 * by accumulating graded predictions that beat the closing line, and
 * nothing in the product accumulates evidence about whether the engine's
 * own *selections* would have won - only whether its probabilities were
 * better calibrated than the market's. Those are different questions, and
 * only the second one is currently being asked.
 *
 * So this writes the first one down. Every selection the scanner would
 * have bet, had the gate not refused it, recorded before kickoff at the
 * price that was on the board, and settled afterwards against the real
 * result with closing line value.
 *
 * Three rules make it an experiment rather than a track record:
 *
 *  - It never touches `published_picks`, and nothing that builds the
 *    public record reads these tables.
 *  - It is never shown to a customer. The only view is behind the admin
 *    gate, labelled.
 *  - A row is written before kickoff or not at all, enforced by the
 *    database. A shadow record that could be written once the result was
 *    known would answer nothing.
 *
 * It is not a backtest. Every row is out of sample by construction,
 * because the scan that produced it ran before the game.
 */
class ShadowRecorder
{
    /**
     * The same lead the real publisher requires.
     *
     * A pick recorded eight seconds before kickoff is technically
     * pre-kickoff and practically unplayable, and an experiment built
     * from those would flatter itself on prices nobody could have taken.
     */
    public const MINIMUM_LEAD_MINUTES = 15;

    public function __construct(private readonly BetSettler $grading) {}

    /** @return array{recorded:int, skipped:array<string, int>} */
    public function record(): array
    {
        $candidates = ValueBet::query()
            ->where('shadow_recommendation', 'bet')
            ->whereNotNull('best_price_american')
            ->whereDoesntHave('shadowPick')
            /*
             * Not already recorded as a selection, which is a different
             * question from not already recorded as a value bet.
             *
             * The board is rescanned hourly and each scan writes its own
             * value_bets row, so `whereDoesntHave('shadowPick')` is true
             * again every hour for the same game at the same price. That
             * wrote one row per rescan - 92 rows for 31 selections in six
             * hours - and each would have been settled and counted on its
             * own, scaling the record by the scan rate.
             *
             * The line is part of the identity: on spreads a genuinely
             * different number is a genuinely different bet, and it is
             * compared null-safely because a moneyline has no line at all.
             */
            ->whereNotExists(function ($recorded): void {
                $recorded->selectRaw('1')
                    ->from('shadow_picks')
                    ->whereColumn('shadow_picks.event_id', 'value_bets.event_id')
                    ->whereColumn('shadow_picks.market', 'value_bets.market')
                    ->whereColumn('shadow_picks.side', 'value_bets.side')
                    ->whereRaw('(shadow_picks.line = value_bets.line or (shadow_picks.line is null and value_bets.line is null))');
            })
            ->orderByDesc('flagged_at')
            ->limit(500)
            ->get();

        $recorded = 0;
        $skipped = ['no_event' => 0, 'too_late' => 0, 'already_recorded' => 0];

        /*
         * Seen inside this batch as well as in the table.
         *
         * The query above excludes selections already recorded, and it
         * is evaluated once, before anything is written. So several
         * scans' rows for the same selection all pass it together and
         * the second write is a duplicate - which the database refuses,
         * and which aborted the whole run the first time this happened
         * on an empty table.
         *
         * The batch is ordered newest first, so the row kept is the most
         * recent price for that selection, which is the price that was on
         * the board when it was recorded.
         */
        $seen = [];

        foreach ($candidates as $bet) {
            $key = implode('|', [
                $bet->event_id,
                $bet->market?->value,
                $bet->side?->value,
                $bet->line ?? 'null',
            ]);

            if (isset($seen[$key])) {
                $skipped['already_recorded']++;

                continue;
            }

            $seen[$key] = true;

            $outcome = $this->write($bet);

            if ($outcome === null) {
                $recorded++;

                continue;
            }

            $skipped[$outcome] = ($skipped[$outcome] ?? 0) + 1;
        }

        return ['recorded' => $recorded, 'skipped' => $skipped];
    }

    /** Returns null on success, or the reason it was skipped. */
    public function write(ValueBet $bet): ?string
    {
        $event = Event::query()->with(['homeTeam:id,display_name', 'awayTeam:id,display_name'])->find($bet->event_id);

        if ($event?->starts_at === null) {
            return 'no_event';
        }

        if ($event->starts_at->isBefore(now()->addMinutes(self::MINIMUM_LEAD_MINUTES))) {
            /*
             * Normal rather than exceptional: the board is rescanned
             * hourly and some games are always close to kicking off. The
             * row is dropped instead of being written late, because a
             * late row is the one thing that would make the whole table
             * worthless.
             */
            Log::info('Shadow pick not recorded: too close to kickoff', [
                'value_bet_id' => $bet->id,
                'kickoff' => $event->starts_at->toIso8601String(),
            ]);

            return 'too_late';
        }

        try {
            $this->insert($bet, $event);
        } catch (QueryException $e) {
            /*
             * The database guard had the last word. With the in-batch
             * check above this should not be reachable, which is exactly
             * why it is caught rather than left to abort an hourly job:
             * if some other route to a duplicate exists, the cost should
             * be one unrecorded selection, not a whole batch.
             */
            Log::warning('Shadow pick refused by the database', [
                'value_bet_id' => $bet->id,
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);

            return 'already_recorded';
        }

        return null;
    }

    private function insert(ValueBet $bet, Event $event): void
    {
        ShadowPick::create([
            'value_bet_id' => $bet->id,
            'event_id' => $event->id,
            'sport' => $bet->sport?->value ?? $event->sport->value,
            'market' => $bet->market?->value,
            'side' => $bet->side?->value,
            'line' => $bet->line,
            'selection' => $bet->selectionLabel(),
            'matchup' => trim(($event->awayTeam?->display_name ?? '?').' at '.($event->homeTeam?->display_name ?? '?')),
            'price_american' => (int) $bet->best_price_american,
            'book' => $bet->bestBook?->key,
            'model_prob' => $bet->model_prob,
            'fair_prob' => $bet->fair_prob,
            'edge_at_price' => $bet->edge_at_price,
            'stake_fraction' => $bet->kelly_fraction,
            // Carried so the row can never be mistaken for a pick: this
            // is what the gate said at the time it declined to sell it.
            'market_verdict' => $bet->market_verdict?->value,
            'model_version' => $bet->model_version_id,
            'kickoff_at' => $event->starts_at,
            'recorded_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** @return array{settled:int, unresolved:int} */
    public function settle(): array
    {
        $picks = ShadowPick::query()->awaitingSettlement()->with('event')->limit(500)->get();

        $settled = 0;
        $unresolved = 0;

        foreach ($picks as $pick) {
            $event = $pick->event;

            if ($event?->final_home_score === null || $event->final_away_score === null) {
                $unresolved++;

                continue;
            }

            $status = $this->statusFor($pick, (int) $event->final_home_score, (int) $event->final_away_score);

            if ($status === null) {
                $unresolved++;

                continue;
            }

            $closing = $this->closingPrice($pick);

            ShadowResult::create([
                'shadow_pick_id' => $pick->id,
                'status' => $status,
                'final_home_score' => $event->final_home_score,
                'final_away_score' => $event->final_away_score,
                'units' => $this->units($pick, $status),
                'closing_price_american' => $closing,
                'clv' => $closing === null ? null : $this->clv($pick, $closing),
                'settled_at' => now(),
                'created_at' => now(),
            ]);

            $settled++;
        }

        return ['settled' => $settled, 'unresolved' => $unresolved];
    }

    private function statusFor(ShadowPick $pick, int $home, int $away): ?string
    {
        // The same grader the customer's own bets go through, so a shadow
        // pick and a real bet on the same selection can never disagree.
        return $this->grading->outcome(new UserBet([
            'market' => $pick->market,
            'side' => $pick->side,
            'line' => $pick->line,
            'price_american' => $pick->price_american,
            'stake_cents' => 100,
        ]), $home, $away);
    }

    private function units(ShadowPick $pick, string $status): float
    {
        $won = $pick->price_american > 0
            ? $pick->price_american / 100
            : 100 / abs($pick->price_american);

        return round(match ($status) {
            'won' => $won,
            'lost' => -1.0,
            'push', 'void' => 0.0,
        }, 4);
    }

    /**
     * The closing price, from the marker frozen before kickoff.
     *
     * Read from `event_line_markers` rather than fetched now. The whole
     * value of the column is that the number was recorded at the time; a
     * price looked up afterwards would be a claim nobody can check.
     */
    private function closingPrice(ShadowPick $pick): ?int
    {
        return EventLineMarker::query()
            ->where('event_id', $pick->event_id)
            ->where('market', $pick->market)
            ->when($pick->side !== null, fn ($q) => $q->where('side', $pick->side))
            ->whereNotNull('price_close_american')
            ->orderByDesc('closed_at')
            ->value('price_close_american');
    }

    /**
     * Closing line value, as a difference in implied probability.
     *
     * Positive means the price shortened after the pick was recorded: the
     * market moved toward it. This is the figure worth watching, because
     * it does not care whether the pick won, and at this sample size
     * whether it won is mostly noise.
     */
    private function clv(ShadowPick $pick, int $closing): float
    {
        return round($this->implied($closing) - $this->implied((int) $pick->price_american), 5);
    }

    private function implied(int $price): float
    {
        return $price > 0
            ? 100 / ($price + 100)
            : abs($price) / (abs($price) + 100);
    }
}
