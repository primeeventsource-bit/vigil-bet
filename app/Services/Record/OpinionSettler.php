<?php

declare(strict_types=1);

namespace App\Services\Record;

use App\Models\EventLineMarker;
use App\Models\Opinion;
use App\Models\OpinionResult;
use App\Models\UserBet;
use App\Services\Bets\BetSettler;

/**
 * Grades published opinions against what actually happened.
 *
 * The half of the product that makes the other half honest. An opinion
 * is published on a weaker claim than a pick - the model's probabilities
 * land close to the truth, not that they beat the market - and the only
 * thing that turns that from an assertion into something a customer can
 * check is every one of them being graded in public.
 *
 * Grading goes through the same settler a customer's own bet does, so an
 * opinion and a real bet on the same selection can never disagree about
 * whether it won.
 *
 * Closing line value is recorded even though an opinion makes no claim
 * to beat the close. It is the measurement that would eventually earn a
 * market the stronger badge, and it can only be collected while these
 * are being published - which is the way out of the gate's circularity.
 */
class OpinionSettler
{
    public function __construct(private readonly BetSettler $grading) {}

    /** @return array{settled:int, unresolved:int} */
    public function run(): array
    {
        $opinions = Opinion::query()->awaitingSettlement()->with('event')->limit(500)->get();

        $settled = 0;
        $unresolved = 0;

        foreach ($opinions as $opinion) {
            $event = $opinion->event;

            if ($event?->final_home_score === null || $event->final_away_score === null) {
                // Finished but not yet graded, or not finished. Either
                // way the answer is "not yet" rather than a guess.
                $unresolved++;

                continue;
            }

            $status = $this->statusFor($opinion, (int) $event->final_home_score, (int) $event->final_away_score);

            if ($status === null) {
                $unresolved++;

                continue;
            }

            $closing = $this->closingPrice($opinion);

            OpinionResult::create([
                'opinion_id' => $opinion->id,
                'status' => $status,
                'final_home_score' => $event->final_home_score,
                'final_away_score' => $event->final_away_score,
                'units' => $this->units($opinion, $status),
                'closing_price_american' => $closing,
                'clv' => $closing === null ? null : $this->clv($opinion, $closing),
                'settled_at' => now(),
                'created_at' => now(),
            ]);

            $settled++;
        }

        return ['settled' => $settled, 'unresolved' => $unresolved];
    }

    private function statusFor(Opinion $opinion, int $home, int $away): ?string
    {
        return $this->grading->outcome(new UserBet([
            'market' => $opinion->market,
            'side' => $opinion->side,
            'line' => $opinion->line,
            'price_american' => $opinion->price_american,
            'stake_cents' => 100,
        ]), $home, $away);
    }

    /** Signed, per unit staked, at the price that was published. */
    private function units(Opinion $opinion, string $status): float
    {
        $won = $opinion->price_american > 0
            ? $opinion->price_american / 100
            : 100 / abs($opinion->price_american);

        return round(match ($status) {
            'won' => $won,
            'lost' => -1.0,
            default => 0.0,
        }, 4);
    }

    /**
     * The price this selection closed at, if one was stored.
     *
     * Null rather than a guess. A closing line exists only in the
     * minutes before kickoff, so a missing one cannot be recovered later
     * and estimating it would put an invented number in a public record.
     */
    private function closingPrice(Opinion $opinion): ?int
    {
        return EventLineMarker::query()
            ->where('event_id', $opinion->event_id)
            ->where('market', $opinion->market)
            ->where('side', $opinion->side)
            ->whereNotNull('price_close_american')
            ->orderByDesc('closed_at')
            ->value('price_close_american');
    }

    /**
     * Closing line value: how much the market moved toward the opinion.
     *
     * Positive means the price shortened after it was published - the
     * market came to agree. At this sample size it says more than the
     * win rate does, because it does not depend on which way a handful
     * of games happened to fall.
     */
    private function clv(Opinion $opinion, int $closing): float
    {
        return round($this->implied($closing) - $this->implied($opinion->price_american), 5);
    }

    private function implied(int $price): float
    {
        return $price > 0
            ? 100 / ($price + 100)
            : abs($price) / (abs($price) + 100);
    }
}
