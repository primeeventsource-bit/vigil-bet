<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\VlBoardEntry;

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
    /** @return array<string, mixed> */
    public function forBoardEntry(VlBoardEntry $entry): array
    {
        $sheet = [
            'kind' => 'board_entry',
            'sport' => strtoupper((string) $entry->sport),
            'market' => $entry->market,
            'selection' => $entry->selection,
            'line' => $entry->line,

            // The gate, stated plainly, because the model is required to
            // say whether the market is proven and must not have to infer
            // it from the recommendation.
            'market_verdict' => $entry->market_verdict,
            'market_is_proven' => $entry->market_verdict === 'proven',
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

        $sheet['observed_at'] = $entry->observed_at?->toIso8601String();

        return array_filter($sheet, static fn ($v) => $v !== null);
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
