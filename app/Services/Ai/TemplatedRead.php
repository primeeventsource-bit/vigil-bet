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
