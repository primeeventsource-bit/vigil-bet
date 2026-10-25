<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\Verdict;
use App\Models\ParlayGrade;
use App\Models\VlBoardEntry;
use App\Models\VlEvent;
use App\Models\VlModel;
use App\Models\VlOddsDisplay;
use Illuminate\Support\Collection;

/**
 * The only thing the model is ever shown.
 *
 * Every value here is read from a row Edge Lab wrote and mirrored into
 * Vigil. Nothing is computed, inferred, rounded into existence or looked
 * up elsewhere, because the validator downstream checks the model's output
 * against exactly this - and a figure the sheet does not contain is a
 * figure the Read may not state, whether or not it happens to be true.
 *
 * That is also why percentages are written out as the model should say
 * them. If the sheet holds 0.124 and the Read says "12.4%", a validator
 * comparing numerals has to either understand the conversion or reject a
 * correct sentence. Putting both forms in the sheet makes the check exact
 * instead of clever.
 */
class FactSheet
{
    public function __construct(private readonly ResearchBrief $research) {}

    /** @return array<string, mixed> */
    public function forBoardEntry(VlBoardEntry $entry): array
    {
        $sheet = [
            'kind' => 'board_entry',
            'sport' => strtoupper((string) $entry->sport),
            'market' => $entry->market,
            'side' => $entry->side,
            'selection' => $entry->selection,
            'line' => $entry->line,

            /*
             * The gate, stated plainly, because the model is required to
             * say where a market stands and must not have to infer it
             * from the recommendation.
             *
             * Two separate facts, and a Read that confused them would
             * misdescribe the product. `proven` means it beats the
             * closing line - nothing currently does. `may publish` is the
             * weaker tier: honest probabilities, no edge claimed. A
             * calibrated market is published and is not proven, and both
             * halves of that have to reach the prompt.
             */
            'market_verdict' => $entry->market_verdict,
            'market_is_proven' => $entry->market_verdict === Verdict::Proven->value,
            'market_may_publish' => Verdict::tryFrom((string) $entry->market_verdict)
                ?->allowsRecommendation() ?? false,
            'recommendation' => $entry->recommendation,
            'pass_reason' => $entry->pass_reason,
            'engine_sentence' => $entry->reason,
        ];

        $sheet += $this->probability('model_probability', $entry->model_prob);
        $sheet += $this->probability('market_fair_probability', $entry->fair_prob);
        $sheet += $this->probability('edge_over_fair_price', $entry->edge);
        $sheet += $this->probability('edge_at_this_price', $entry->edge_at_price);

        if ($entry->best_price_american !== null) {
            $sheet['price_american'] = $entry->best_price_american;
            // Written the way a book prints it, so the model can quote it
            // verbatim rather than reconstructing the sign.
            $sheet['price_written'] = ($entry->best_price_american > 0 ? '+' : '').$entry->best_price_american;
        }

        if ($entry->best_book_title !== null) {
            $sheet['book'] = $entry->best_book_title;
        }

        if ($entry->suggested_stake_cents !== null && $entry->suggested_stake_cents > 0) {
            $sheet['suggested_stake'] = '$'.number_format($entry->suggested_stake_cents / 100, 2);
        }

        if ($entry->kelly_fraction !== null) {
            $sheet['kelly_fraction'] = round((float) $entry->kelly_fraction, 4);
        }

        /*
         * Availability is collected by the engine and read by no model, so
         * it is flagged rather than quietly included. A Read that implied
         * an injury had moved a number would be describing a mechanism
         * that does not exist.
         */
        $sheet['injuries_modelled'] = false;

        /*
         * The research, which is the substance of a Read on an unproven
         * market - and every market is unproven. Without it the Read had
         * prices, line movement and a verdict, and was explaining a
         * number nobody should act on while saying nothing a customer
         * could use.
         *
         * Added after the flag above and carrying its own, because the
         * honest shape of this is "here is what is known, and the number
         * does not include it" rather than a sheet that reads as though
         * the engine weighed any of it.
         */
        if ($entry->edge_lab_event_id !== null) {
            $sheet += $this->research->forEvent((int) $entry->edge_lab_event_id);
        }

        $sheet['observed_at'] = $entry->observed_at?->toIso8601String();

        $sheet += $this->game($entry);
        $sheet += $this->model($entry);
        $sheet += $this->movement($entry);

        return array_filter($sheet, static fn ($v) => $v !== null);
    }

    /**
     * A parlay grade, as Edge Lab judged it.
     *
     * §6 names parlay hold and shaded edge specifically, and they are the
     * two figures a customer most needs and least often sees. The hold is
     * what the book keeps; it compounds with every leg, which is why a
     * six-leg parlay at fair-looking prices is usually terrible. The
     * shaded edge is what is left after the model's own uncertainty is
     * taken into account, which is the only version of the number worth
     * acting on.
     *
     * Every value comes from the stored grade. Nothing is recomputed
     * here: multiplying leg probabilities on this side would produce a
     * figure that looked like a parlay probability and was not one, since
     * legs on the same game are correlated.
     *
     * @return array<string, mixed>
     */
    public function forParlay(ParlayGrade $grade): array
    {
        $sheet = [
            'kind' => 'parlay',
            'legs' => $grade->leg_count,
            // Said plainly, because a Read about an unanswered grade must
            // not describe it as a bad bet.
            'judge_answered' => $grade->wasAnswered(),
            'unavailable_reason' => $grade->unavailable_reason,
            'verdict' => $grade->verdict,
            'injuries_modelled' => false,
        ];

        $sheet += $this->probability('win_probability', $grade->win_probability);
        $sheet += $this->probability('book_hold', $grade->book_hold);
        $sheet += $this->probability('shaded_edge', $grade->shaded_edge);

        if ($grade->fair_price_american !== null) {
            $sheet['fair_price_american'] = $grade->fair_price_american;
            $sheet['fair_price_written'] = ($grade->fair_price_american > 0 ? '+' : '').$grade->fair_price_american;
        }

        if ($grade->offered_price_american !== null) {
            $sheet['offered_price_american'] = $grade->offered_price_american;
            $sheet['offered_price_written'] = ($grade->offered_price_american > 0 ? '+' : '').$grade->offered_price_american;
        }

        /*
         * The legs themselves, so the Read can name them. Only what was
         * submitted - the judge's own per-leg breakdown, where it
         * returns one, is already in the stored result.
         */
        $sheet['leg_markets'] = collect($grade->legs)
            ->map(fn (array $leg) => trim(($leg['market'] ?? '').' '.($leg['side'] ?? '')))
            ->filter()
            ->values()
            ->all();

        return array_filter($sheet, static fn ($v) => $v !== null);
    }

    /**
     * The teams and the kickoff.
     *
     * §6 asks for both in the sheet, and the reason is that without them
     * the model has to write about "this market" in the abstract. Read
     * from the mirrored event rather than parsed out of the selection
     * string - a selection is a label, not a data source.
     *
     * @return array<string, mixed>
     */
    private function game(VlBoardEntry $entry): array
    {
        if ($entry->edge_lab_event_id === null) {
            return [];
        }

        $event = VlEvent::query()->current()
            ->forEdgeLabId($entry->edge_lab_event_id)
            ->first();

        if ($event === null) {
            return [];
        }

        return array_filter([
            'home_team' => $event->home_name,
            'away_team' => $event->away_name,
            'matchup' => $event->matchup(),
            // ISO, and in words. The model quotes the words; without
            // them the validator would see the "8" in "8:15pm" as a
            // figure it had to find in the sheet.
            'kickoff' => $event->starts_at?->toIso8601String(),
            'kickoff_written' => $event->starts_at?->format('l j F, H:i T'),
            'event_status' => $event->status,
        ], static fn ($v) => $v !== null);
    }

    /**
     * Which model version produced this, and how it did against the gate.
     *
     * §6 asks for "model version and its gate result". It is the honest
     * answer to "why should I believe the probability": not because a
     * model said so, but because this version of it was measured against
     * the closing line, and here is what happened.
     *
     * @return array<string, mixed>
     */
    private function model(VlBoardEntry $entry): array
    {
        $model = VlModel::query()->current()
            ->where('sport', $entry->sport)
            ->where('market', $entry->market)
            ->orderByDesc('trained_at')
            ->first();

        if ($model === null) {
            return [];
        }

        $sheet = array_filter([
            'model_version' => $model->version,
            'model_type' => $model->model_type,
            'model_verdict' => $model->verdict,
            'model_verdict_reason' => $model->verdict_reason,
            'model_may_produce_picks' => $model->allows_picks,
            'model_trained_at' => $model->trained_at?->toIso8601String(),
        ], static fn ($v) => $v !== null);

        if ($model->predictions !== null) {
            // The sample size, always. A verdict on 40 games and a
            // verdict on 4,000 are different claims.
            $sheet['model_predictions_measured'] = (int) $model->predictions;
        }

        if ($model->log_loss_improvement !== null) {
            $sheet += $this->probability('model_log_loss_improvement', $model->log_loss_improvement);
        }

        /*
         * The thresholds the verdict was measured against, carried
         * verbatim. A Read that says "proven" without them is asking the
         * customer to take the word on trust.
         */
        if (is_array($model->gate) && $model->gate !== []) {
            $sheet['gate'] = $model->gate;
        }

        return $sheet;
    }

    /**
     * What the price did, in one sentence.
     *
     * §6 asks for a line movement summary. Written as a sentence rather
     * than a list of quotes so the model can restate it without doing
     * arithmetic: every figure it might quote is already in the string,
     * and the validator reads numbers out of strings too.
     *
     * Read from the display mirror, which only ever holds games somebody
     * has looked at. There is no bulk odds feed on either side of this
     * product and this is not allowed to become one.
     *
     * @return array<string, mixed>
     */
    private function movement(VlBoardEntry $entry): array
    {
        if ($entry->edge_lab_event_id === null || $entry->market === null) {
            return [];
        }

        $quotes = VlOddsDisplay::query()
            ->where('edge_lab_event_id', $entry->edge_lab_event_id)
            ->where('market', $entry->market)
            ->when($entry->side !== null, fn ($q) => $q->where('side', $entry->side))
            ->when($entry->best_book !== null, fn ($q) => $q->where('book', $entry->best_book))
            ->orderBy('quoted_at')
            ->get();

        if ($quotes->count() < 2) {
            // One quote is not a movement, and saying "unchanged" would
            // be a claim about a history we do not have.
            return ['line_movement' => 'Not enough quotes stored to describe how this price has moved.'];
        }

        return ['line_movement' => $this->describeMovement($quotes)];
    }

    /** @param  Collection<int, VlOddsDisplay>  $quotes */
    private function describeMovement(Collection $quotes): string
    {
        $first = $quotes->first();
        $last = $quotes->last();

        $write = static fn (?int $price): string => $price === null
            ? 'no price'
            : ($price > 0 ? '+' : '').$price;

        $direction = match (true) {
            $first->price_american === null || $last->price_american === null => 'changed',
            $last->price_american > $first->price_american => 'drifted out',
            $last->price_american < $first->price_american => 'shortened',
            default => 'held',
        };

        $sentence = 'At '.($last->book_title ?? $last->book).' this price '.$direction
            .' from '.$write($first->price_american).' to '.$write($last->price_american)
            .' across '.$quotes->count().' stored quotes';

        if ($first->line !== null && $last->line !== null && (float) $first->line !== (float) $last->line) {
            $sentence .= ', and the line moved from '.$first->line.' to '.$last->line;
        }

        return $sentence.'.';
    }

    /**
     * A probability in both forms the model might reach for.
     *
     * @return array<string, mixed>
     */
    private function probability(string $key, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $float = (float) $value;

        return [
            $key => round($float, 4),
            $key.'_written' => $this->asPercent($float),
        ];
    }

    /**
     * One form, not two.
     *
     * The dashboard prints an edge as "+12.4%" and a probability as "76%",
     * and writing both variants into the sheet to cover that would mean
     * the sheet disagreed with itself. The sheet holds the plain form and
     * the validator ignores a leading plus when it compares - sign
     * normalisation belongs in the check, not in the facts.
     */
    private function asPercent(float $value): string
    {
        return rtrim(rtrim(number_format(round($value * 100, 1), 1), '0'), '.').'%';
    }
}
