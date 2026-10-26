<?php

declare(strict_types=1);

namespace App\Services\Record;

use App\Models\PickResult;
use App\Models\PublishedPick;
use App\Models\UserBet;
use App\Models\VlEvent;
use App\Models\VlOddsDisplay;
use App\Services\Bets\BetSettler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Settles published picks from Edge Lab's results.
 *
 * Shares its grading rules with the customer's own bets, deliberately:
 * the public record and a customer's record must agree about what a
 * 3-point win against a 3-point line is, and two copies of that logic
 * would eventually disagree.
 *
 * A pick with no final score stays unsettled for ever rather than being
 * marked anything. §7's record is only worth reading if an unanswered
 * pick appears as unanswered.
 */
class PickSettler
{
    public function __construct(private readonly BetSettler $grading) {}

    /** @return array{settled:int, skipped:int} */
    public function run(): array
    {
        $pending = PublishedPick::query()->pending()->get();

        $settled = 0;
        $skipped = 0;

        foreach ($pending as $pick) {
            $event = VlEvent::query()->current()
                ->forEdgeLabId((int) $pick->edge_lab_event_id)
                ->first();

            if ($event?->final_home_score === null || $event->final_away_score === null) {
                $skipped++;

                continue;
            }

            $status = $this->statusFor($pick, (int) $event->final_home_score, (int) $event->final_away_score);

            if ($status === null) {
                $skipped++;

                continue;
            }

            try {
                // Nested so a duplicate cannot abort the
                // surrounding transaction and take the rest of the
                // batch with it on Postgres.
                DB::transaction(fn () => PickResult::create([
                    'published_pick_id' => $pick->id,
                    'status' => $status,
                    'final_home_score' => $event->final_home_score,
                    'final_away_score' => $event->final_away_score,
                    'units' => $this->units($pick, $status),
                    'closing_price_american' => $closing = $this->closingPrice($pick, $event),
                    'clv' => $closing === null ? null : $this->clv($pick, $closing),
                    'settled_at' => now(),
                    'created_at' => now(),
                ]));
            } catch (QueryException $e) {
                // The unique key doing its job. A pick settled twice
                // would appear twice in the record, and a record that
                // can be padded by re-running a command is not one.
                if (! str_contains(strtolower($e->getMessage()), 'unique')) {
                    throw $e;
                }

                $skipped++;

                continue;
            }

            $settled++;
        }

        return ['settled' => $settled, 'skipped' => $skipped];
    }

    /**
     * Graded by the same rules as a customer's own bet.
     *
     * The settler takes a UserBet, so a throwaway instance is built to
     * carry the market, side and line. Not elegant, and much better than
     * a second copy of the grading rules that can drift from the first.
     */
    private function statusFor(PublishedPick $pick, int $home, int $away): ?string
    {
        $stand_in = new UserBet([
            'market' => $pick->market,
            'side' => $pick->side,
            'line' => $pick->line,
            'price_american' => $pick->price_american,
            'stake_cents' => 100,
        ]);

        return $this->grading->outcome($stand_in, $home, $away);
    }

    /**
     * Profit per unit staked, signed.
     *
     * Units rather than dollars: a public record in dollars would imply
     * a stake size nobody was told to use, and §7 wants a figure that is
     * comparable across readers.
     */
    private function units(PublishedPick $pick, string $status): float
    {
        return round(match ($status) {
            'won' => $pick->unitsIfWon(),
            'lost' => -1.0,
            // Stake back, nothing made.
            'push', 'void' => 0.0,
        }, 4);
    }

    /**
     * The last price quoted before kickoff.
     *
     * From stored quotes only. A closing price fetched after the fact
     * would be a number nobody recorded at the time, and the whole
     * point of this column is that it was.
     */
    private function closingPrice(PublishedPick $pick, VlEvent $event): ?int
    {
        if ($event->starts_at === null) {
            return null;
        }

        $quote = VlOddsDisplay::query()
            ->where('edge_lab_event_id', $pick->edge_lab_event_id)
            ->where('market', $pick->market)
            ->when($pick->side !== null, fn ($q) => $q->where('side', $pick->side))
            ->where('quoted_at', '<=', $event->starts_at)
            ->orderByDesc('quoted_at')
            ->first();

        return $quote?->price_american;
    }

    /**
     * Closing line value, as a difference in implied probability.
     *
     * Positive means the price shortened after publication: the market
     * came to agree, and the pick was ahead of it. This is the figure
     * that tells a model from a hot streak, because it does not care
     * whether the pick won.
     */
    private function clv(PublishedPick $pick, int $closing): float
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
