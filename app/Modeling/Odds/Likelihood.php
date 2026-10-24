<?php

declare(strict_types=1);

namespace App\Modeling\Odds;

use InvalidArgumentException;

/**
 * Turns a probability into something a person can act on without doing
 * arithmetic, and without being misled.
 *
 * The honesty is in the wording. "Wins about 7 out of 10 bets like this" is
 * true and carries its own warning: three of them lose. "70% chance" invites
 * the reader to hear "basically certain", which is how people end up chasing
 * a losing week.
 */
final class Likelihood
{
    /** Rounded to the nearest whole bet out of ten. */
    public static function winsOutOfTen(float $probability): int
    {
        self::assert($probability);

        return (int) round($probability * 10);
    }

    public static function sentence(float $probability): string
    {
        $wins = self::winsOutOfTen($probability);

        return "Wins about {$wins} out of 10 bets like this";
    }

    /** The other half of the sentence, which is the half people skip. */
    public static function losesSentence(float $probability): string
    {
        $losses = 10 - self::winsOutOfTen($probability);

        return $losses === 1
            ? 'Still loses about 1 time in 10'
            : "Still loses about {$losses} times in 10";
    }

    /**
     * Ten dots, filled for expected wins. A row of dots makes the losing
     * ones visible, which a percentage never does.
     *
     * @return array<int, bool>
     */
    public static function dots(float $probability): array
    {
        $wins = self::winsOutOfTen($probability);

        return array_map(static fn (int $i) => $i < $wins, range(0, 9));
    }

    /**
     * The win rate a price needs just to break even. The model has to beat
     * this number, not merely be above a coin flip.
     */
    public static function breakEvenWinRate(int $american): float
    {
        return OddsConverter::americanToProbability($american);
    }

    /**
     * Edge measured at the price actually on offer: how far the model is
     * above the break-even rate for that price.
     *
     * This is the number that decides whether a bet makes money. It is not
     * the same as disagreeing with the wider market, which is what says
     * whether the model is any good.
     */
    public static function edgeAtPrice(float $probability, int $american): float
    {
        self::assert($probability);

        return $probability - self::breakEvenWinRate($american);
    }

    /** A short, honest reason line for a pick card. */
    public static function reason(float $probability, int $american, ?string $bookTitle = null): string
    {
        $book = $bookTitle ?? 'this book';
        $breakEven = self::breakEvenWinRate($american);

        return sprintf(
            'Model gives it %s. %s needs %s to break even at %s.',
            self::percent($probability),
            $book,
            self::percent($breakEven),
            OddsConverter::formatAmerican($american),
        );
    }

    public static function percent(float $probability): string
    {
        return number_format($probability * 100, 1).'%';
    }

    private static function assert(float $probability): void
    {
        if ($probability < 0.0 || $probability > 1.0) {
            throw new InvalidArgumentException("Probability must be between 0 and 1, got {$probability}.");
        }
    }
}
