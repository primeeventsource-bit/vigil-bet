<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * The explanation that gets written when the model's does not pass.
 *
 * Assembled from the fact sheet by string concatenation, so it cannot
 * state a number the sheet does not contain - not as a policy, but
 * because there is no code path by which it could. It reads less well
 * than a good generated Read and better than a bad one.
 *
 * It exists so that a validation failure degrades to a plainer
 * explanation rather than to a blank space where the reasoning should be.
 * A customer who sees nothing assumes the product has nothing to say.
 */
class TemplatedRead
{
    /** @param  array<string, mixed>  $sheet */
    public function compose(array $sheet): string
    {
        return ($sheet['kind'] ?? null) === 'parlay'
            ? $this->parlay($sheet)
            : $this->boardEntry($sheet);
    }

    /**
     * A parlay grade, in plain words.
     *
     * The hold is what leads, because it is the figure that decides the
     * question and the one a customer has almost certainly never been
     * shown. It compounds with every leg, which is why a six-leg slip at
     * prices that each look fair is usually dreadful.
     *
     * @param  array<string, mixed>  $sheet
     */
    private function parlay(array $sheet): string
    {
        $legs = (int) ($sheet['legs'] ?? 0);

        if (($sheet['judge_answered'] ?? false) !== true) {
            // Not "no value". Nobody answered, and saying otherwise would
            // be a verdict the engine never gave.
            return "This {$legs}-leg slip has not been graded: the engine could not be reached, "
                .'so there is no probability, no fair price and no edge to report. '
                .'Nothing here says the slip is good or bad - it says the question went unanswered. '
                .'Try again shortly.';
        }

        $parts = [];

        $parts[] = "This slip has {$legs} legs.";

        if (isset($sheet['win_probability_written'])) {
            $parts[] = "The engine puts the chance of all of them landing at {$sheet['win_probability_written']}.";
        }

        if (isset($sheet['fair_price_written'], $sheet['offered_price_written'])) {
            $parts[] = "A fair price for that would be {$sheet['fair_price_written']}; the book is offering "
                ."{$sheet['offered_price_written']}.";
        }

        if (isset($sheet['book_hold_written'])) {
            $parts[] = "The book keeps {$sheet['book_hold_written']} of every dollar staked on this "
                .'combination, and that figure grows with each leg added.';
        }

        if (isset($sheet['shaded_edge_written'])) {
            $parts[] = "After allowing for the model's own uncertainty, the edge on this slip is "
                ."{$sheet['shaded_edge_written']}.";
        }

        return implode(' ', $parts);
    }

    /** @param  array<string, mixed>  $sheet */
    private function boardEntry(array $sheet): string
    {
        $parts = [];

        $selection = $sheet['selection'] ?? 'This selection';
        $proven = (bool) ($sheet['market_is_proven'] ?? false);

        $parts[] = $proven
            ? "{$selection} comes from a market that has beaten the closing line, so it can produce a pick."
            : "{$selection} is in a market that has not yet been proven against the closing line, so it is analysis rather than a pick.";

        if (isset($sheet['model_probability_written'])) {
            $line = "The model puts this at {$sheet['model_probability_written']}";

            if (isset($sheet['market_fair_probability_written'])) {
                $line .= ", against a vig-free market price of {$sheet['market_fair_probability_written']}";
            }

            $parts[] = $line.'.';
        }

        if (isset($sheet['price_written'])) {
            $book = isset($sheet['book']) ? " at {$sheet['book']}" : '';
            $parts[] = "The price is {$sheet['price_written']}{$book}.";
        }

        if (isset($sheet['edge_at_this_price_written'])) {
            $parts[] = "That leaves {$sheet['edge_at_this_price_written']} against the break-even rate at this price.";
        }

        if (($sheet['recommendation'] ?? null) === 'pass' && isset($sheet['engine_sentence'])) {
            $parts[] = (string) $sheet['engine_sentence'];
        }

        // The responsible-gambling line goes on picks only, per the spec.
        if (($sheet['recommendation'] ?? null) === 'bet' && $proven) {
            $parts[] = (string) config('vigil.responsible_gambling_line');
        }

        return implode(' ', $parts);
    }
}
