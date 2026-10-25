<?php

declare(strict_types=1);

use App\Enums\Verdict;
use App\Modeling\Backtest\BacktestEvaluator;
use App\Modeling\Backtest\BacktestPrediction;
use App\Modeling\Backtest\Verdicter;
use Carbon\CarbonImmutable;

/**
 * @param  array<int, array{0: float, 1: int, 2: array<string,int>}>  $rows
 * @return array<int, BacktestPrediction>
 */
function predictions(array $rows): array
{
    $out = [];

    foreach ($rows as $i => [$probability, $outcome, $market]) {
        $out[] = new BacktestPrediction(
            eventId: $i + 1,
            startsAt: CarbonImmutable::parse('2024-09-08')->addDays($i),
            probability: $probability,
            outcome: $outcome,
            closingMarket: $market,
        );
    }

    return $out;
}

/** A market priced at the true rate, with a normal hold on both sides. */
function marketAt(float $trueProbability): array
{
    $vig = 1.045;
    $home = 1 / ($trueProbability * $vig);
    $away = 1 / ((1 - $trueProbability) * $vig);

    $toAmerican = fn (float $d) => $d >= 2.0
        ? (int) round(($d - 1) * 100)
        : (int) round(-100 / ($d - 1));

    return ['home' => $toAmerican($home), 'away' => $toAmerican($away)];
}

it('returns an empty summary for an empty run', function () {
    $summary = (new BacktestEvaluator)->evaluate([]);

    expect($summary->predictions)->toBe(0)
        ->and($summary->verdict()->verdict)->toBe(Verdict::Insufficient);
});

it('scores the model and the market on exactly the same games', function () {
    // One game has no closing price. It must count toward the model score
    // and toward neither side of the comparison.
    $rows = [
        [0.60, 1, marketAt(0.58)],
        [0.55, 0, marketAt(0.54)],
        [0.70, 1, []],
    ];

    $summary = (new BacktestEvaluator)->evaluate(predictions($rows));

    expect($summary->predictions)->toBe(3)
        ->and($summary->withMarket)->toBe(2);
});

it('finds no improvement when the model simply is the market', function () {
    // A model that reproduces the fair closing probability exactly should
    // score the same as the market and lean neither way on home teams.
    // Anything else would mean the comparison is measuring something other
    // than skill.
    mt_srand(11);
    $rows = [];

    for ($i = 0; $i < 400; $i++) {
        $p = 0.40 + (($i % 21) / 100);
        $rows[] = [$p, (mt_rand(0, 9999) / 10000) < $p ? 1 : 0, marketAt($p)];
    }

    $summary = (new BacktestEvaluator)->evaluate(predictions($rows));

    expect($summary->logLossImprovement())->toEqualWithDelta(0.0, 0.01)
        ->and($summary->meanHomeBias)->toEqualWithDelta(0.0, 0.01)
        ->and($summary->calibrationImprovement())->toEqualWithDelta(0.0, 0.01)
        /*
         * A model that reproduces the market's own probabilities is
         * perfectly calibrated by construction and beats nothing. That is
         * exactly the calibrated tier: honest numbers, no edge claimed.
         */
        ->and($summary->verdict(new Verdicter(minPredictions: 100))->verdict)
        ->toBe(Verdict::Calibrated);
});

it('proves a model that genuinely knows more than the market', function () {
    // The market is systematically overconfident; the model is right.
    mt_srand(12);
    $rows = [];

    for ($i = 0; $i < 500; $i++) {
        $true = 0.45 + (($i % 15) / 100);
        $marketBelief = 0.5 + ($true - 0.5) * 1.5;   // market exaggerates
        $rows[] = [$true, (mt_rand(0, 9999) / 10000) < $true ? 1 : 0, marketAt($marketBelief)];
    }

    $summary = (new BacktestEvaluator)->evaluate(predictions($rows));

    expect($summary->logLossImprovement())->toBeGreaterThan(0.0)
        ->and($summary->brierSkill)->toBeGreaterThan(0.0);
});

it('condemns a model the market clearly beats', function () {
    // Badly overconfident: it takes the market edge and quadruples it, so it
    // is confidently wrong often enough to be measurably worse.
    mt_srand(13);
    $rows = [];

    for ($i = 0; $i < 500; $i++) {
        $true = 0.45 + (($i % 15) / 100);
        $modelBelief = min(0.97, max(0.03, 0.5 + ($true - 0.5) * 4));
        $rows[] = [$modelBelief, (mt_rand(0, 9999) / 10000) < $true ? 1 : 0, marketAt($true)];
    }

    $summary = (new BacktestEvaluator)->evaluate(predictions($rows));
    $verdict = $summary->verdict(new Verdicter(minPredictions: 100));

    expect($summary->improvementTStat())->toBeLessThan(-1.64)
        ->and($verdict->verdict)->toBe(Verdict::Negative);
});

it('does not condemn a model that is merely indistinguishable from the market', function () {
    // Mildly overconfident, and only 0.08 standard errors from the market.
    // That is indistinguishable from it, so neither Proven nor Negative is
    // an honest answer.
    mt_srand(13);
    $rows = [];

    for ($i = 0; $i < 500; $i++) {
        $true = 0.45 + (($i % 15) / 100);
        $rows[] = [0.5 + ($true - 0.5) * 1.8, (mt_rand(0, 9999) / 10000) < $true ? 1 : 0, marketAt($true)];
    }

    $verdict = (new BacktestEvaluator)->evaluate(predictions($rows))
        ->verdict(new Verdicter(minPredictions: 100));

    /*
     * The claim is about what it is not. Indistinguishable from the
     * market is neither an edge nor evidence the model is wrong, so
     * whether it lands as unproven or as calibrated depends on its own
     * probabilities - and either is honest. Condemning it would not be.
     */
    expect($verdict->verdict)->not->toBe(Verdict::Negative)
        ->and($verdict->verdict)->not->toBe(Verdict::Proven);
});

it('is not fooled by a model that merely leans away from home teams', function () {
    // A model shaded toward the away side reads as a large positive home
    // bias on every market, because that number is just the gap between two
    // opinions. It is also badly wrong. The gate rests on log loss and
    // calibration, so the lean buys it nothing.
    mt_srand(13);
    $rows = [];

    for ($i = 0; $i < 500; $i++) {
        $true = 0.45 + (($i % 15) / 100);
        $rows[] = [min(0.97, max(0.03, 1 - $true)), (mt_rand(0, 9999) / 10000) < $true ? 1 : 0, marketAt($true)];
    }

    $summary = (new BacktestEvaluator)->evaluate(predictions($rows));

    expect($summary->meanHomeBias)->toBeGreaterThan(0.0)
        ->and($summary->logLossImprovement())->toBeLessThan(0.0)
        ->and($summary->verdict(new Verdicter(minPredictions: 100))->verdict)
        ->not->toBe(Verdict::Proven);
});

it('reports flat roi without letting it decide anything', function () {
    mt_srand(14);
    $rows = [];

    for ($i = 0; $i < 400; $i++) {
        $true = 0.52;
        $rows[] = [0.52, (mt_rand(0, 9999) / 10000) < $true ? 1 : 0, marketAt(0.52)];
    }

    $summary = (new BacktestEvaluator)->evaluate(predictions($rows));

    expect($summary->flatRoi)->not->toBeNull()
        // Betting into the hold every game loses, and the verdict is still
        // decided by log loss and CLV rather than by this number.
        ->and($summary->flatRoi)->toBeLessThan(0.0)
        ->and($summary->verdict(new Verdicter(minPredictions: 100))->verdict)
        ->not->toBe(Verdict::Proven);
});

it('says there is no benchmark when nothing had a closing price', function () {
    $rows = [];

    for ($i = 0; $i < 400; $i++) {
        $rows[] = [0.55, $i % 2, []];
    }

    $summary = (new BacktestEvaluator)->evaluate(predictions($rows));
    $verdict = $summary->verdict(new Verdicter(minPredictions: 100));

    expect($summary->marketLogLoss)->toBeNull()
        ->and($verdict->verdict)->toBe(Verdict::Insufficient)
        ->and($verdict->reason)->toContain('no benchmark');
});
