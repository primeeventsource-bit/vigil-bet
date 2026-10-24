<?php

declare(strict_types=1);

namespace App\Modeling\Odds;

use App\Data\ConsensusPrice;
use App\Enums\VigMethod;

/**
 * What the market as a whole thinks, with the margin removed.
 *
 * The order of the two operations is the entire point, and getting it
 * backwards is a classic way to build a tool that reports an edge that was
 * never there. Each book's own two-sided market has its vig removed first,
 * and only then are the resulting fair probabilities combined.
 *
 * Averaging the raw implied probabilities first and removing vig afterwards
 * would blend the overrounds together, and books with different holds
 * produce a blended market that no book ever offered - usually one that
 * sums to less than 100 percent, which makes the market look beatable by a
 * few points before a single model has been consulted. TrainingSetBuilder
 * sidesteps the same trap by taking one book rather than an average.
 *
 * The combination is a median rather than a mean. One book left with a stale
 * price, or one outlier that has not moved with the news, shifts a mean by
 * enough to invent an edge; a median of five books ignores it. That matters
 * most in exactly the situation where an edge appears to be largest, which
 * is where a tool like this does its damage.
 */
final class MarketConsensus
{
    /**
     * The fair probability for one side across every book quoting both.
     *
     * @param  array<string, array<string, int>>  $americanByBook  book key => [side => american price]
     * @param  array<int, string>  $sides  the two sides of this market
     */
    public static function fairProbability(
        array $americanByBook,
        string $side,
        array $sides,
        VigMethod $method = VigMethod::Multiplicative,
    ): ?ConsensusPrice {
        $fair = [];
        $sums = [];

        foreach ($americanByBook as $book => $prices) {
            $market = [];

            foreach ($sides as $candidate) {
                if (! isset($prices[$candidate])) {
                    continue 2;
                }

                $market[$candidate] = $prices[$candidate];
            }

            // A one-sided book cannot have its vig removed, so it does not
            // get a vote. Counting it would mean treating a raw price as a
            // fair one.
            $fair[$book] = VigRemover::fairProbabilities($market, $method)[$side];
            $sums[] = VigRemover::overround($market) + 1.0;
        }

        if ($fair === []) {
            return null;
        }

        return new ConsensusPrice(
            fairProbability: self::median(array_values($fair)),
            bookCount: count($fair),
            impliedSum: self::median($sums),
            method: $method,
            perBook: $fair,
        );
    }

    /** @param  array<int, float>  $values */
    public static function median(array $values): float
    {
        sort($values);
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2.0;
    }
}
