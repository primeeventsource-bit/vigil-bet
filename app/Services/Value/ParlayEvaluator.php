<?php

declare(strict_types=1);

namespace App\Services\Value;

use App\Enums\Market;
use App\Enums\PassReason;
use App\Enums\Recommendation;
use App\Enums\Side;
use App\Enums\Verdict;
use App\Modeling\Odds\Parlay;
use App\Models\BankrollLedgerEntry;
use App\Models\BankrollSetting;
use App\Models\ParlayEvaluation;
use App\Models\ValueBet;
use App\Services\Engine\Bankroll\StakeSizer;

/**
 * Judges a parlay, and almost always declines it.
 *
 * That is not pessimism, it is the arithmetic. A parlay compounds three
 * things at once: the payout, the book's margin, and the model's error. The
 * first is the only one anyone notices, and this class exists to put the
 * other two next to it.
 *
 * Every leg's numbers are read from the scan that judged it rather than
 * recomputed here, for the same reason the board reads them: two answers to
 * one question is how a screen ends up disagreeing with the table behind
 * it. A leg no scan has judged cannot be given a model probability at all,
 * and the parlay says so instead of guessing one.
 *
 * Two refusals are absolute, whatever the numbers look like:
 *
 *  - Legs from the same game are correlated. Their probabilities cannot be
 *    multiplied, and the product of their prices is not what a book pays
 *    for a same-game parlay. Nothing here will print either.
 *  - An edge that vanishes when every leg is shaded by the amount a model
 *    is routinely wrong by was never an edge. Singles survive a small
 *    error; a parlay multiplies it.
 */
class ParlayEvaluator
{
    public function __construct(private readonly StakeSizer $sizer) {}

    /**
     * Judge a set of selections as one parlay, and write the judgement down.
     *
     * @param  array<int, array{event_id:int, market:Market|string, side:Side|string}>  $selections
     */
    public function evaluate(array $selections, ?float $shade = null): ParlayEvaluation
    {
        return ParlayEvaluation::create($this->build($selections, $shade));
    }

    /**
     * The same judgement, unsaved.
     *
     * A slip being assembled is judged on every change, and writing each of
     * those to an append-only table would bury the parlays actually
     * considered under every intermediate state of building one.
     *
     * @param  array<int, array{event_id:int, market:Market|string, side:Side|string}>  $selections
     */
    public function preview(array $selections, ?float $shade = null): ParlayEvaluation
    {
        return (new ParlayEvaluation)->forceFill($this->build($selections, $shade));
    }

    /**
     * @param  array<int, array{event_id:int, market:Market|string, side:Side|string}>  $selections
     * @return array<string, mixed>
     */
    private function build(array $selections, ?float $shade = null): array
    {
        $shade ??= (float) config('edge.parlay.shade');
        $settings = BankrollSetting::current();
        $bankroll = BankrollLedgerEntry::currentBalanceCents()
            ?: (int) ($settings?->starting_bankroll_cents ?? 0);

        $legs = array_map(fn (array $selection) => $this->leg($selection), $selections);

        $context = [
            'legs' => $legs,
            'leg_count' => count($legs),
            'sport' => $this->commonSport($legs),
            'correlated' => $this->correlated($legs),
            'shade_used' => $shade,
            'bankroll_cents' => $bankroll,
            'evaluated_at' => now(),
            'created_at' => now(),
            'value_scan_id' => $this->scanId($legs),
        ];

        $priced = array_values(array_filter($legs, fn (array $leg) => $leg['price_american'] !== null));
        $prices = array_column($priced, 'price_american');

        // The price is arithmetic on real quotes, so it is stated whenever
        // every leg has one - even where the parlay is refused for other
        // reasons. Knowing what a book is offering is useful on its own.
        $decimal = $prices === [] ? 1.0 : Parlay::decimalPrice($prices);
        $context['offered_price_american'] = $prices === [] ? 0 : Parlay::americanPrice($prices);
        $context['offered_decimal'] = $decimal;
        $context['break_even'] = $prices === [] ? 1.0 : Parlay::breakEven($decimal);

        if (($refusal = $this->refuse($legs, $context)) !== null) {
            return $refusal;
        }

        $modelProbs = array_column($legs, 'model_prob');
        $fairProbs = array_column($legs, 'fair_prob');

        $joint = Parlay::jointProbability($modelProbs);
        $shaded = Parlay::shaded($modelProbs, $shade);

        $context['model_joint_prob'] = $joint;
        $context['fair_joint_prob'] = Parlay::jointProbability($fairProbs);
        $context['shaded_joint_prob'] = $shaded;
        $context['hold'] = Parlay::hold($fairProbs, $prices);
        $context['hold_per_leg'] = Parlay::holdPerLeg($fairProbs, $prices);
        $context['ev_per_dollar'] = Parlay::expectedValue($joint, $decimal);
        $context['ev_shaded'] = Parlay::expectedValue($shaded, $decimal);

        // The gate. A parlay is judged on the shaded probability, never the
        // raw one, because the raw one is the model marking its own work
        // and then multiplying the mark by the number of legs.
        if ($context['ev_shaded'] <= 0.0) {
            return $context + [
                'recommendation' => Recommendation::Pass->value,
                'pass_reason' => PassReason::EdgeGoneWhenShaded->value,
                'reason' => sprintf(
                    'Raw edge %s, but %s once every leg is shaded %s. %s',
                    $this->pct($context['ev_per_dollar']),
                    $this->pct($context['ev_shaded']),
                    $this->pct($shade),
                    PassReason::EdgeGoneWhenShaded->detail(),
                ),
            ];
        }

        // Sized on the shaded probability too, for the same reason.
        $stake = $this->sizer->size($shaded, $context['offered_price_american'], $bankroll, $settings);

        if (! $stake->isActionable) {
            return $context + [
                'kelly_fraction' => $stake->kellyFraction,
                'recommendation' => Recommendation::Pass->value,
                'pass_reason' => $stake->passReason?->value,
                'reason' => $stake->explanation,
            ];
        }

        return $context + [
            'kelly_fraction' => $stake->kellyFraction,
            'suggested_stake_cents' => $stake->stakeCents,
            'recommendation' => Recommendation::Bet->value,
            'reason' => sprintf(
                '%s The book keeps %s of this parlay, %s a leg.',
                $stake->explanation,
                $this->pct($context['hold']),
                $this->pct($context['hold_per_leg']),
            ),
        ];
    }

    /**
     * Everything that stops a parlay before its edge is even computed.
     *
     * @param  array<int, array<string, mixed>>  $legs
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function refuse(array $legs, array $context): ?array
    {
        $min = (int) config('edge.parlay.min_legs');
        $max = (int) config('edge.parlay.max_legs');

        $fail = function (PassReason $reason, ?string $detail = null) use ($context): array {
            return $context + [
                'recommendation' => Recommendation::Pass->value,
                'pass_reason' => $reason->value,
                'reason' => $detail ?? $reason->detail(),
            ];
        };

        if (count($legs) < $min) {
            return $fail(PassReason::TooFewLegs);
        }

        if (count($legs) > $max) {
            return $fail(PassReason::TooManyLegs, sprintf(
                'This app prices at most %d legs. %s',
                $max,
                PassReason::TooManyLegs->detail(),
            ));
        }

        if ($context['correlated']) {
            return $fail(PassReason::CorrelatedLegs);
        }

        foreach ($legs as $leg) {
            if ($leg['price_american'] === null) {
                return $fail(PassReason::NoPrice, sprintf(
                    'No price for %s at your book, so the parlay has no price either.',
                    $leg['selection'],
                ));
            }
        }

        foreach ($legs as $leg) {
            if ($leg['model_prob'] === null || $leg['fair_prob'] === null) {
                return $fail(PassReason::LegUnmodelled, sprintf(
                    '%s has not been judged by a scan. %s',
                    $leg['selection'],
                    PassReason::LegUnmodelled->detail(),
                ));
            }
        }

        // The same bar a single bet has to clear. A parlay of unproven legs
        // is not a way around a verdict.
        foreach ($legs as $leg) {
            $verdict = Verdict::tryFrom((string) ($leg['verdict'] ?? ''));

            if ($verdict === null || ! $verdict->allowsRecommendation()) {
                return $fail(PassReason::MarketUnproven, sprintf(
                    '%s is in a market that has not beaten a closing line, so it cannot be a leg. %s',
                    $leg['selection'],
                    'Stacking unproven legs multiplies the doubt rather than diluting it.',
                ));
            }
        }

        return null;
    }

    /**
     * One leg, read from the newest scan that judged it.
     *
     * @param  array{event_id:int, market:Market|string, side:Side|string}  $selection
     * @return array<string, mixed>
     */
    private function leg(array $selection): array
    {
        $market = $selection['market'] instanceof Market
            ? $selection['market']
            : Market::from((string) $selection['market']);
        $side = $selection['side'] instanceof Side
            ? $selection['side']
            : Side::from((string) $selection['side']);

        $scanned = ValueBet::query()
            ->where('event_id', $selection['event_id'])
            ->where('market', $market->value)
            ->where('side', $side->value)
            ->with(['event.homeTeam:id,display_name', 'event.awayTeam:id,display_name'])
            ->orderByDesc('value_scan_id')
            ->orderByDesc('id')
            ->first();

        return [
            'event_id' => (int) $selection['event_id'],
            'value_bet_id' => $scanned?->id,
            'value_scan_id' => $scanned?->value_scan_id,
            'sport' => $scanned?->sport?->value,
            'market' => $market->value,
            'side' => $side->value,
            'line' => $scanned?->line,
            'selection' => $scanned?->selectionLabel() ?? $market->label().' '.ucfirst($side->value),
            'matchup' => $scanned?->event?->matchup(),
            'book' => $scanned?->bestBook?->title,
            // The price at the book the bet would actually be placed at.
            // A parlay built from the best price across the board is a
            // parlay nobody can place.
            'price_american' => $scanned?->best_price_american,
            'model_prob' => $scanned?->model_prob,
            'fair_prob' => $scanned?->fair_prob,
            'verdict' => $scanned?->market_verdict?->value,
            'scanned_at' => $scanned?->flagged_at?->toIso8601String(),
        ];
    }

    /** @param  array<int, array<string, mixed>>  $legs */
    private function correlated(array $legs): bool
    {
        $events = array_column($legs, 'event_id');

        return count($events) !== count(array_unique($events));
    }

    /** @param  array<int, array<string, mixed>>  $legs */
    private function commonSport(array $legs): ?string
    {
        $sports = array_values(array_unique(array_filter(array_column($legs, 'sport'))));

        return count($sports) === 1 ? $sports[0] : null;
    }

    /** @param  array<int, array<string, mixed>>  $legs */
    private function scanId(array $legs): ?int
    {
        $ids = array_filter(array_column($legs, 'value_scan_id'));

        return $ids === [] ? null : (int) max($ids);
    }

    private function pct(?float $value): string
    {
        if ($value === null) {
            return 'an unknown amount';
        }

        return ($value > 0 ? '+' : '').number_format($value * 100, 1).'%';
    }
}
