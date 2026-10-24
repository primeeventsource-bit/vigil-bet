<?php

declare(strict_types=1);

namespace App\Services\Engine\Bankroll;

use App\Data\StakeDecision;
use App\Enums\PassReason;
use App\Modeling\Odds\Kelly;
use App\Models\BankrollSetting;
use App\Support\Money;

/**
 * Turns a model probability and a price into the number of dollars to put on
 * it, or an honest refusal.
 *
 * The order is deliberate. Kelly proposes, every cap disposes, and the
 * smallest cap wins. The last check is the floor, and it is the one that
 * matters most in practice: if the correct stake is smaller than the smallest
 * bet you are willing to place, the answer is to skip the bet, never to round
 * it up. Rounding a $40 edge up to a $100 bet is how a real edge turns into a
 * real loss.
 */
class StakeSizer
{
    public function __construct(private readonly LimitGuard $limits) {}

    public function size(
        float $modelProbability,
        int $priceAmerican,
        int $bankrollCents,
        ?BankrollSetting $settings = null,
    ): StakeDecision {
        $settings ??= BankrollSetting::current();

        if ($settings === null) {
            // Not NoModel: that reads as "no trained model covers this
            // market", which sends the reader looking for a modelling
            // problem when the bankroll simply has not been set up.
            return StakeDecision::pass(
                PassReason::NoBankrollSettings,
                'No bankroll settings have been saved yet.',
            );
        }

        $kellyFraction = Kelly::fractional($modelProbability, $priceAmerican, $settings->kelly_fraction);
        $kellyCents = Kelly::stakeCents($modelProbability, $priceAmerican, $settings->kelly_fraction, $bankrollCents);

        if ($kellyCents <= 0) {
            return StakeDecision::pass(
                PassReason::NegativeEdge,
                'At this price the model sees no edge, so there is no stake to make.',
                kellyFraction: $kellyFraction,
            );
        }

        // Order is the tie-break. When two caps land on the same number the
        // explicit one I set is the more useful thing to be told, so it is
        // listed ahead of the percentage guard.
        $caps = ['fractional Kelly' => $kellyCents];

        if ($settings->max_stake_cents !== null && $settings->max_stake_cents > 0) {
            $caps['your maximum bet'] = $settings->max_stake_cents;
        }

        // The percentage guard, when there is one. Null means off, which
        // is not the same as a very large percentage: a large one still
        // scales with the balance and still turns up as the thing that
        // sized the bet. Off leaves Kelly and the exposure cap to do it.
        if ($settings->max_stake_pct !== null && $settings->max_stake_pct > 0) {
            $caps['max stake per bet'] = Money::pctOf($bankrollCents, (float) $settings->max_stake_pct);
        }

        $caps['daily exposure cap'] = $this->limits->remainingDailyExposureCents($bankrollCents, $settings);

        $stakeCents = (int) min($caps);
        $cappedBy = array_search($stakeCents, $caps, true);
        $cappedBy = $cappedBy === 'fractional Kelly' ? null : (string) $cappedBy;

        if ($stakeCents <= 0) {
            return StakeDecision::pass(
                PassReason::ExposureCap,
                'Today is already at its exposure cap, so there is nothing left to stake.',
                uncappedKellyCents: $kellyCents,
                kellyFraction: $kellyFraction,
            );
        }

        // The floor, last. A stake below it is not a smaller bet, it is no bet.
        if ($settings->min_stake_cents > 0 && $stakeCents < $settings->min_stake_cents) {
            return StakeDecision::pass(
                PassReason::BelowMinStake,
                sprintf(
                    'The right stake here is %s, below your %s minimum. Skip it rather than overbet it.',
                    Money::format($stakeCents),
                    Money::format($settings->min_stake_cents),
                ),
                uncappedKellyCents: $kellyCents,
                kellyFraction: $kellyFraction,
            );
        }

        return new StakeDecision(
            stakeCents: $stakeCents,
            units: Money::toUnits($stakeCents, $settings->unit_size_cents),
            kellyFraction: $kellyFraction,
            uncappedKellyCents: $kellyCents,
            cappedBy: $cappedBy,
            isActionable: true,
            explanation: $cappedBy === null
                ? sprintf('%s at %s Kelly.', Money::format($stakeCents), $this->describeFraction($settings->kelly_fraction))
                : sprintf('%s, held down by your %s.', Money::format($stakeCents), $cappedBy),
        );
    }

    private function describeFraction(float $fraction): string
    {
        return match (true) {
            abs($fraction - 0.25) < 0.001 => 'quarter',
            abs($fraction - 0.5) < 0.001 => 'half',
            abs($fraction - 1.0) < 0.001 => 'full',
            default => rtrim(rtrim(number_format($fraction, 2), '0'), '.').'x',
        };
    }
}
