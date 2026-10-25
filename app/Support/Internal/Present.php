<?php

declare(strict_types=1);

namespace App\Support\Internal;

use App\Enums\Verdict;
use App\Modeling\Odds\OddsConverter;
use App\Models\Event;
use App\Models\InjuryReport;
use App\Models\ModelVersion;
use App\Models\OddsSnapshot;
use App\Models\ParlayEvaluation;
use App\Models\Prediction;
use App\Models\ValueBet;

/**
 * The wire shapes of the internal API, in one place.
 *
 * Every value here is read off a stored row. Nothing is computed, rounded,
 * re-derived or improved on the way out: the consumer is meant to display
 * Edge Lab's judgement, and two places that both know how to work out an
 * edge is how a screen ends up disagreeing with the table behind it.
 *
 * Numbers are sent as numbers and not as formatted strings, with one
 * exception: money stays in integer cents, because that is what it is.
 */
final class Present
{
    /** @return array<string, mixed> */
    public static function event(Event $event): array
    {
        return [
            'id' => $event->id,
            'sport' => $event->sport->value,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'status' => $event->status?->value,
            'season' => $event->season,
            'week' => $event->week,
            'neutral_site' => (bool) $event->neutral_site,
            'home' => self::team($event, 'home'),
            'away' => self::team($event, 'away'),
            'final_home_score' => $event->final_home_score,
            'final_away_score' => $event->final_away_score,
            'completed_at' => $event->completed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private static function team(Event $event, string $side): array
    {
        $team = $side === 'home' ? $event->homeTeam : $event->awayTeam;

        return [
            'id' => $team?->id,
            'name' => $team?->display_name,
            'abbreviation' => $team?->abbreviation,
        ];
    }

    /**
     * One evaluated candidate: a pick, or a pass with its reason.
     *
     * No line_shopped. That column holds the best price at every book for
     * this side, and a board page carrying it would be a bulk odds feed for
     * the whole slate under another name. Prices come one game at a time,
     * from the odds endpoint.
     *
     * @return array<string, mixed>
     */
    public static function boardEntry(ValueBet $bet): array
    {
        return [
            'id' => $bet->id,
            'value_scan_id' => $bet->value_scan_id,
            'event_id' => $bet->event_id,
            'sport' => $bet->sport?->value,
            'market' => $bet->market?->value,
            'side' => $bet->side?->value,
            'line' => $bet->line,
            'selection' => $bet->selectionLabel(),

            'model_prob' => $bet->model_prob,
            'fair_prob' => $bet->fair_prob,
            'fair_prob_method' => $bet->fair_prob_method?->value,
            'break_even_win_rate' => $bet->break_even_win_rate,

            // Two different edges, kept apart on purpose. edge_at_price is
            // the one that decides whether a bet makes money; edge is the
            // model disagreeing with the consensus. Collapsing them is how
            // a tool recommends something sound and unprofitable.
            'edge' => $bet->edge,
            'edge_at_price' => $bet->edge_at_price,
            'ev_per_dollar' => $bet->ev_per_dollar,

            'best_book' => $bet->bestBook?->key,
            'best_book_title' => $bet->bestBook?->title,
            'best_price_american' => $bet->best_price_american,
            'better_elsewhere' => (bool) $bet->better_elsewhere,
            'book_count' => $bet->book_count,
            'price_pulled_at' => $bet->price_pulled_at?->toIso8601String(),
            'break_even_price_american' => $bet->break_even_price_american,
            'min_acceptable_price_american' => $bet->min_acceptable_price_american,

            'kelly_fraction' => $bet->kelly_fraction,
            'suggested_stake_cents' => $bet->suggested_stake_cents,
            'suggested_stake_units' => $bet->suggested_stake_units,
            'threshold_used' => $bet->threshold_used,

            'market_verdict' => $bet->market_verdict?->value,
            'recommendation' => $bet->recommendation?->value,
            'pass_reason' => $bet->pass_reason?->value,
            'reason' => $bet->reason,
            'probability_rank' => $bet->probability_rank,
            'value_rank' => $bet->value_rank,

            'model_version_id' => $bet->model_version_id,
            'prediction_id' => $bet->prediction_id,
            'flagged_at' => $bet->flagged_at?->toIso8601String(),
            'created_at' => $bet->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function quote(OddsSnapshot $snapshot): array
    {
        return [
            'book' => $snapshot->book?->key,
            'book_title' => $snapshot->book?->title,
            'market' => $snapshot->market?->value,
            'market_key' => $snapshot->market_key,
            'side' => $snapshot->side?->value,
            'player_name' => $snapshot->player_name,
            'line' => $snapshot->line,
            'price_american' => $snapshot->price_american,
            'price_decimal' => $snapshot->price_decimal,
            'pulled_at' => $snapshot->pulled_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function prediction(Prediction $prediction): array
    {
        return [
            'id' => $prediction->id,
            'event_id' => $prediction->event_id,
            'model_version_id' => $prediction->model_version_id,
            'market' => $prediction->market?->value,
            'side' => $prediction->side?->value,
            'line' => $prediction->line,
            'prob' => $prediction->prob,
            'fair_price_american' => $prediction->fair_price_american,
            'projected_home_score' => $prediction->projected_home_score,
            'projected_away_score' => $prediction->projected_away_score,
            'projected_margin' => $prediction->projected_margin,
            'projected_total' => $prediction->projected_total,
            'sigma' => $prediction->sigma,
            'predicted_at' => $prediction->predicted_at?->toIso8601String(),
            'event_starts_at' => $prediction->event_starts_at?->toIso8601String(),
        ];
    }

    /**
     * A model version and the gate it was measured against.
     *
     * The verdict is read off the stored holdout metrics rather than
     * recomputed, for the same reason as everything else here: the number
     * that gated the picks is the number that has to be shown.
     *
     * @return array<string, mixed>
     */
    public static function model(ModelVersion $version): array
    {
        $metrics = (array) $version->holdout_metrics;

        return [
            'id' => $version->id,
            'sport' => $version->sport->value,
            'market' => $version->market->value,
            'model_type' => $version->model_type->value,
            'version' => $version->version,
            'label' => $version->label,
            'status' => $version->status->value,
            'describe' => $version->describe(),

            'verdict' => $metrics['verdict'] ?? null,
            'verdict_reason' => $metrics['verdict_reason'] ?? null,
            // From the enum, not the string: a calibrated market may
            // surface a pick too, and comparing against 'proven' here
            // would have the engine export it as unpublishable.
            'allows_picks' => Verdict::tryFrom((string) ($metrics['verdict'] ?? ''))
                ?->allowsRecommendation() ?? false,

            'predictions' => $metrics['predictions'] ?? null,
            'log_loss' => $metrics['log_loss'] ?? null,
            'market_log_loss' => $metrics['market_log_loss'] ?? null,
            'log_loss_improvement' => $metrics['log_loss_improvement'] ?? null,
            'calibration_error' => $metrics['calibration_error'] ?? null,
            'market_calibration_error' => $metrics['market_calibration_error'] ?? null,
            'brier' => $metrics['brier'] ?? null,

            // What the gate actually required, so the far side never has to
            // guess at the bar or restate it.
            'gate' => [
                'min_predictions' => (int) config('edge.verdict.min_predictions'),
                'min_log_loss_improvement' => (float) config('edge.verdict.min_log_loss_improvement'),
                'min_t_stat' => (float) config('edge.verdict.min_t_stat'),
                'calibration_tolerance' => (float) config('edge.verdict.calibration_tolerance'),
            ],

            'training_rows' => $version->training_rows,
            'trained_at' => $version->trained_at?->toIso8601String(),
            'trained_through_at' => $version->trained_through_at?->toIso8601String(),
            'activated_at' => $version->activated_at?->toIso8601String(),
            'retired_at' => $version->retired_at?->toIso8601String(),
            'backtest_run_id' => $version->backtest_run_id,
        ];
    }

    /** @return array<string, mixed> */
    public static function parlay(ParlayEvaluation $parlay): array
    {
        return [
            'id' => $parlay->id,
            'leg_count' => $parlay->leg_count,
            'sport' => $parlay->sport?->value,
            'legs' => $parlay->legs,
            'correlated' => (bool) $parlay->correlated,
            'model_joint_prob' => $parlay->model_joint_prob,
            'fair_joint_prob' => $parlay->fair_joint_prob,
            'shaded_joint_prob' => $parlay->shaded_joint_prob,
            'shade_used' => $parlay->shade_used,
            'offered_price_american' => $parlay->offered_price_american,
            'offered_decimal' => $parlay->offered_decimal,

            /*
             * The fair price, in the same unit the book quotes in.
             *
             * A unit conversion of a stored probability, not a second
             * opinion about one - the same relationship offered_decimal
             * already has to offered_price_american above. The conversion
             * belongs to the engine's own converter, so the consumer is
             * never the thing turning a probability into a price.
             */
            'fair_price_american' => $parlay->fair_joint_prob === null
                ? null
                : OddsConverter::probabilityToAmerican((float) $parlay->fair_joint_prob),
            'break_even' => $parlay->break_even,
            'hold' => $parlay->hold,
            'hold_per_leg' => $parlay->hold_per_leg,
            'ev_per_dollar' => $parlay->ev_per_dollar,
            'ev_shaded' => $parlay->ev_shaded,
            'kelly_fraction' => $parlay->kelly_fraction,
            'suggested_stake_cents' => $parlay->suggested_stake_cents,
            'recommendation' => $parlay->recommendation?->value,
            'pass_reason' => $parlay->pass_reason?->value,
            'reason' => $parlay->reason,
            'evaluated_at' => $parlay->evaluated_at?->toIso8601String(),
        ];
    }

    /**
     * Availability, flagged for what it is.
     *
     * `modelled` is false, on every row, out loud. Edge Lab collects
     * availability and no feature reads it yet, and a consumer that assumed
     * otherwise would be telling customers an injury moved a number that it
     * did not move.
     *
     * @return array<string, mixed>
     */
    public static function injury(InjuryReport $report): array
    {
        return [
            'team_id' => $report->team_id,
            'player_name' => $report->player_name,
            'position' => $report->position,
            'status' => $report->status?->value,
            'detail' => $report->detail,
            'reported_at' => $report->reported_at?->toIso8601String(),
            'modelled' => false,
        ];
    }
}
