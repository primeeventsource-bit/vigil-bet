<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\PassReason;
use App\Enums\Side;
use App\Enums\Verdict;
use App\Exceptions\AppendOnlyViolation;
use App\Modeling\Odds\Kelly;
use App\Models\Event;
use App\Models\Team;
use App\Services\Value\ParlayEvaluator;

it('refuses a single selection as a parlay', function () {
    $scan = parlayScan();

    $parlay = app(ParlayEvaluator::class)->evaluate([parlayLeg($scan)]);

    expect($parlay->isPick())->toBeFalse()
        ->and($parlay->pass_reason)->toBe(PassReason::TooFewLegs);
});

it('refuses more legs than it will price', function () {
    $scan = parlayScan();
    $legs = [];

    for ($i = 0; $i < 6; $i++) {
        $legs[] = parlayLeg($scan);
    }

    $parlay = app(ParlayEvaluator::class)->evaluate($legs);

    expect($parlay->pass_reason)->toBe(PassReason::TooManyLegs)
        ->and($parlay->reason)->toContain('at most 5 legs');
});

it('refuses two legs from the same game outright', function () {
    // They are correlated, so neither their probabilities nor their prices
    // may be multiplied.
    $scan = parlayScan();
    $first = parlayLeg($scan);

    $event = Event::find($first['event_id']);
    $second = parlayLeg($scan, event: $event, market: Market::Total, side: Side::Over);

    $parlay = app(ParlayEvaluator::class)->evaluate([$first, $second]);

    expect($parlay->correlated)->toBeTrue()
        ->and($parlay->pass_reason)->toBe(PassReason::CorrelatedLegs)
        // Refused means refused: no joint probability is stated at all.
        ->and($parlay->model_joint_prob)->toBeNull()
        ->and($parlay->ev_per_dollar)->toBeNull();
});

it('still states the price of a parlay it refuses', function () {
    // Knowing what the book is offering is useful even when the answer is no.
    $scan = parlayScan();
    $first = parlayLeg($scan);
    $event = Event::find($first['event_id']);
    $second = parlayLeg($scan, event: $event, market: Market::Total, side: Side::Over);

    $parlay = app(ParlayEvaluator::class)->evaluate([$first, $second]);

    expect($parlay->offered_price_american)->toBe(264)
        ->and(round($parlay->break_even, 4))->toBe(0.2744);
});

it('refuses a leg no scan has judged', function () {
    $scan = parlayScan();

    $home = Team::create(['sport' => 'nfl', 'name' => 'Unjudged', 'display_name' => 'Unjudged', 'slug' => 'unjudged']);
    $away = Team::create(['sport' => 'nfl', 'name' => 'Other', 'display_name' => 'Other', 'slug' => 'other']);
    $event = Event::create(['sport' => 'nfl', 'home_team_id' => $home->id, 'away_team_id' => $away->id, 'starts_at' => now()->addDay(), 'status' => 'scheduled']);

    $parlay = app(ParlayEvaluator::class)->evaluate([
        parlayLeg($scan),
        ['event_id' => $event->id, 'market' => Market::Moneyline, 'side' => Side::Home],
    ]);

    expect($parlay->pass_reason)->toBe(PassReason::NoPrice)
        ->and($parlay->model_joint_prob)->toBeNull();
});

it('will not let a parlay smuggle an unproven market through', function () {
    // The same bar a single bet has to clear. Stacking unproven legs
    // multiplies the doubt rather than diluting it.
    $scan = parlayScan();

    $parlay = app(ParlayEvaluator::class)->evaluate([
        parlayLeg($scan, verdict: Verdict::Proven),
        parlayLeg($scan, verdict: Verdict::Negative),
    ]);

    expect($parlay->pass_reason)->toBe(PassReason::MarketUnproven)
        ->and($parlay->reason)->toContain('multiplies the doubt');
});

it('multiplies the legs into a joint probability and a price', function () {
    parlayBankroll();
    $scan = parlayScan();

    $parlay = app(ParlayEvaluator::class)->evaluate([
        parlayLeg($scan, modelProb: 0.62, fairProb: 0.50, price: -110),
        parlayLeg($scan, modelProb: 0.60, fairProb: 0.51, price: -110),
    ]);

    expect(round((float) $parlay->model_joint_prob, 4))->toBe(0.372)
        ->and(round((float) $parlay->fair_joint_prob, 4))->toBe(0.255)
        ->and($parlay->offered_price_american)->toBe(264)
        ->and($parlay->leg_count)->toBe(2);
});

it('reports what the book keeps, and the rate it compounds from', function () {
    parlayBankroll();
    $scan = parlayScan();

    $parlay = app(ParlayEvaluator::class)->evaluate([
        parlayLeg($scan, modelProb: 0.62, fairProb: 0.50, price: -110),
        parlayLeg($scan, modelProb: 0.60, fairProb: 0.51, price: -110),
    ]);

    // Each leg carries roughly three and a half points of margin. Together
    // they are seven, not three and a half, and the per-leg figure is what
    // the parlay's own hold compounds from.
    expect($parlay->hold)->toBeGreaterThan(0.065)
        ->and($parlay->hold)->toBeLessThan(0.075)
        ->and($parlay->hold_per_leg)->toBeLessThan($parlay->hold)
        ->and($parlay->hold_per_leg)->toBeGreaterThan(0.03);
});

it('judges on the shaded probability, not the raw one', function () {
    parlayBankroll();
    $scan = parlayScan();

    $parlay = app(ParlayEvaluator::class)->evaluate([
        parlayLeg($scan, modelProb: 0.62, fairProb: 0.50, price: -110),
        parlayLeg($scan, modelProb: 0.60, fairProb: 0.51, price: -110),
    ]);

    expect($parlay->shaded_joint_prob)->toBeLessThan($parlay->model_joint_prob)
        ->and($parlay->ev_shaded)->toBeLessThan($parlay->ev_per_dollar)
        ->and((float) $parlay->shade_used)->toBe(0.02);
});

it('passes when the edge does not survive the shading', function () {
    // Thin edges that look positive raw and are not once each leg is
    // knocked down by what a model is routinely wrong by.
    parlayBankroll();
    $scan = parlayScan();

    $parlay = app(ParlayEvaluator::class)->evaluate([
        parlayLeg($scan, modelProb: 0.54, fairProb: 0.53, price: -110),
        parlayLeg($scan, modelProb: 0.53, fairProb: 0.52, price: -110),
        parlayLeg($scan, modelProb: 0.53, fairProb: 0.52, price: -110),
    ]);

    expect($parlay->ev_per_dollar)->toBeGreaterThan(0.0)
        ->and($parlay->ev_shaded)->toBeLessThan(0.0)
        ->and($parlay->pass_reason)->toBe(PassReason::EdgeGoneWhenShaded)
        ->and($parlay->reason)->toContain('once every leg is shaded');
});

it('recommends a parlay whose edge survives everything', function () {
    parlayBankroll();
    $scan = parlayScan();

    $parlay = app(ParlayEvaluator::class)->evaluate([
        parlayLeg($scan, modelProb: 0.70, fairProb: 0.50, price: -110),
        parlayLeg($scan, modelProb: 0.68, fairProb: 0.51, price: -110),
    ]);

    expect($parlay->isPick())->toBeTrue()
        ->and($parlay->suggested_stake_cents)->toBeGreaterThan(0)
        ->and($parlay->reason)->toContain('The book keeps');
});

it('sizes the stake on the shaded probability', function () {
    parlayBankroll();
    $scan = parlayScan();

    $parlay = app(ParlayEvaluator::class)->evaluate([
        parlayLeg($scan, modelProb: 0.70, fairProb: 0.50, price: -110),
        parlayLeg($scan, modelProb: 0.68, fairProb: 0.51, price: -110),
    ]);

    $onRaw = Kelly::stakeCents(
        (float) $parlay->model_joint_prob,
        (int) $parlay->offered_price_american,
        0.25,
        500000,
    );

    // Staking the raw number would bet more on a probability nobody checked.
    expect($parlay->suggested_stake_cents)->toBeLessThan($onRaw);
});

it('freezes the legs onto the row as they stood', function () {
    parlayBankroll();
    $scan = parlayScan();

    $parlay = app(ParlayEvaluator::class)->evaluate([
        parlayLeg($scan, modelProb: 0.70, fairProb: 0.50, price: -110),
        parlayLeg($scan, modelProb: 0.68, fairProb: 0.51, price: -115),
    ]);

    expect($parlay->legs)->toHaveCount(2)
        ->and($parlay->legs[0]['model_prob'])->toBe(0.7)
        ->and($parlay->legs[1]['price_american'])->toBe(-115)
        ->and($parlay->legs[0]['selection'])->not->toBeNull()
        ->and($parlay->value_scan_id)->toBe($scan->id);
});

it('never rewrites a judged parlay', function () {
    parlayBankroll();
    $scan = parlayScan();

    $parlay = app(ParlayEvaluator::class)->evaluate([parlayLeg($scan), parlayLeg($scan)]);

    expect(fn () => $parlay->update(['suggested_stake_cents' => 999]))
        ->toThrow(AppendOnlyViolation::class);
});

it('records a parlay with no bankroll as a pass rather than a stake', function () {
    $scan = parlayScan();

    $parlay = app(ParlayEvaluator::class)->evaluate([
        parlayLeg($scan, modelProb: 0.70, fairProb: 0.50, price: -110),
        parlayLeg($scan, modelProb: 0.68, fairProb: 0.51, price: -110),
    ]);

    expect($parlay->isPick())->toBeFalse()
        ->and($parlay->pass_reason)->toBe(PassReason::NoBankrollSettings);
});
