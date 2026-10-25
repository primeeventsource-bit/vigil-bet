<?php

declare(strict_types=1);

namespace App\Services\Players;

use App\Modeling\Math\Metrics;
use Illuminate\Support\Facades\DB;

/**
 * How good the prop model has actually turned out to be.
 *
 * Two separate questions, kept apart because they have different
 * answers and the stronger one is the one that matters:
 *
 *   calibration - when the model says 70%, does it happen 70% of the
 *     time? A model can pass this and still be useless, because the
 *     market says 70% on the same games.
 *
 *   against the market - is the model's log loss lower than the log
 *     loss of the no-vig market price on the same selections? This is
 *     the bar, and it is the one the game markets are held to. Nothing
 *     is published for beating the first test alone.
 *
 * The second question cannot be asked at all where the board is
 * one-sided, because a single price cannot have its vig removed and
 * there is no fair probability to compare against. Every anytime
 * touchdown quote collected is yes-only, so the largest prop market on
 * the board can be checked for calibration and not for edge. That is
 * reported rather than papered over.
 *
 * Pushes and voids are excluded. A push is a bet returned, not won or
 * lost, and a void is a player who never took the field - and counting
 * a void as an under would flatter this model specifically, since it
 * leans under and would be marked right for a reason it never
 * predicted.
 */
class PropCalibration
{
    /**
     * Per market, for one model.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byMarket(?string $fingerprint = null, ?string $sport = null): array
    {
        $rows = $this->graded($fingerprint, $sport);

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row->sport.'|'.$row->market_key][] = $row;
        }

        $out = [];

        foreach ($grouped as $market => $entries) {
            $out[] = ['market' => $market] + $this->measure($entries);
        }

        usort($out, fn (array $a, array $b) => $b['graded'] <=> $a['graded']);

        return $out;
    }

    /** Everything, as one figure. */
    public function overall(?string $fingerprint = null, ?string $sport = null): array
    {
        return $this->measure($this->graded($fingerprint, $sport));
    }

    /**
     * The calibration curve: predicted against observed, in buckets.
     *
     * This is what catches a model that is directionally right and
     * systematically overconfident - the shape the live bias check
     * could only hint at before anything was graded.
     *
     * @return array<int, array<string, mixed>>
     */
    public function curve(?string $fingerprint = null, ?string $sport = null, int $bins = 10): array
    {
        $rows = $this->graded($fingerprint, $sport);

        if ($rows === []) {
            return [];
        }

        [$model, , $outcomes] = $this->series($rows);

        return Metrics::calibrationCurve($model, $outcomes, $bins);
    }

    /**
     * One set of numbers from a set of graded rows.
     *
     * @param  array<int, object>  $rows
     * @return array<string, mixed>
     */
    private function measure(array $rows): array
    {
        if ($rows === []) {
            return [
                'graded' => 0,
                'model_log_loss' => null,
                'market_log_loss' => null,
                'beats_market' => null,
                'brier' => null,
                'brier_skill_vs_market' => null,
                'calibration_error' => null,
                'with_fair_price' => 0,
                'mean_predicted' => null,
                'observed_rate' => null,
            ];
        }

        [$model, $market, $outcomes] = $this->series($rows);

        // The market comparison is only possible where a fair price
        // exists, so it is computed over that subset and the subset's
        // size is reported beside it.
        $pairedModel = [];
        $pairedMarket = [];
        $pairedOutcomes = [];

        foreach ($rows as $i => $row) {
            if ($market[$i] === null) {
                continue;
            }

            $pairedModel[] = $model[$i];
            $pairedMarket[] = $market[$i];
            $pairedOutcomes[] = $outcomes[$i];
        }

        $modelLoss = $pairedModel === [] ? null : Metrics::logLoss($pairedModel, $pairedOutcomes);
        $marketLoss = $pairedMarket === [] ? null : Metrics::logLoss($pairedMarket, $pairedOutcomes);

        return [
            'graded' => count($rows),
            'with_fair_price' => count($pairedModel),
            'model_log_loss' => $modelLoss,
            'market_log_loss' => $marketLoss,
            'beats_market' => $modelLoss === null || $marketLoss === null
                ? null
                : $modelLoss < $marketLoss,
            'brier' => Metrics::brier($model, $outcomes),
            'brier_skill_vs_market' => $pairedModel === []
                ? null
                : Metrics::brierSkillScore($pairedModel, $pairedMarket, $pairedOutcomes),
            'calibration_error' => Metrics::expectedCalibrationError($model, $outcomes),
            'mean_predicted' => array_sum($model) / count($model),
            'observed_rate' => array_sum($outcomes) / count($outcomes),
        ];
    }

    /**
     * Model probability, market probability and outcome, aligned.
     *
     * Everything is stated as "did the over happen", including yes/no
     * markets, where the yes side is the over - one direction for every
     * row, so a log loss computed across markets is about one question.
     *
     * The model's probability is renormalised over the two sides that
     * can win, because the push has been excluded from the outcomes: a
     * projection of 48% over, 44% under and 8% push is a 52% over among
     * the bets that resolve, and 48 against a resolved outcome would
     * score the model for a push that did not happen.
     *
     * @param  array<int, object>  $rows
     * @return array{0: array<int, float>, 1: array<int, float|null>, 2: array<int, int>}
     */
    private function series(array $rows): array
    {
        $model = [];
        $market = [];
        $outcomes = [];

        foreach ($rows as $row) {
            $decided = (float) $row->prob_over + (float) $row->prob_under;

            $model[] = $decided <= 0.0 ? 0.5 : (float) $row->prob_over / $decided;
            $market[] = $row->fair_prob_over === null ? null : (float) $row->fair_prob_over;
            $outcomes[] = $row->outcome === 'over' ? 1 : 0;
        }

        return [$model, $market, $outcomes];
    }

    /**
     * Graded rows: over or under only.
     *
     * @return array<int, object>
     */
    private function graded(?string $fingerprint, ?string $sport): array
    {
        return DB::table('prop_projections as p')
            ->join('prop_projection_results as r', 'r.prop_projection_id', '=', 'p.id')
            ->whereIn('r.outcome', ['over', 'under'])
            ->when($fingerprint, fn ($q) => $q->where('p.model_fingerprint', $fingerprint))
            ->when($sport, fn ($q) => $q->where('p.sport', $sport))
            ->orderBy('p.id')
            ->get([
                'p.sport', 'p.market_key', 'p.prob_over', 'p.prob_under',
                'p.fair_prob_over', 'p.line', 'p.projected_mean', 'r.outcome', 'r.actual',
            ])
            ->all();
    }
}
