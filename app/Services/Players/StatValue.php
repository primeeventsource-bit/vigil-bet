<?php

declare(strict_types=1);

namespace App\Services\Players;

/**
 * Reading one number out of one box score field.
 *
 * Extracted so the ratings and the projections cannot drift apart. They
 * are built from the same rows and must read them identically: a
 * projection that takes 5 from "5-12" where the rating took 5 is fine,
 * and one that takes 512 is a number nobody would notice was wrong.
 *
 * Three fields disagree with a plain cast, and all three are real:
 *
 *   "27/44"  a completion line. Cast gives 27, which is not wrong so
 *            much as about a different thing from the yardage field it
 *            sits beside.
 *   "5-12"   threes made from attempted. The first part is what
 *            happened, so it is taken deliberately rather than by
 *            letting a cast pick it up by accident.
 *   "5.2"    five innings and two thirds, not five point two. Read as a
 *            decimal it still sorts correctly and is still wrong, so
 *            anything that later sums or averages it inherits the error.
 */
final class StatValue
{
    /** A stat as a number, read the way its kind says to read it. */
    public static function read(mixed $value, string $kind): ?float
    {
        return match ($kind) {
            'made' => self::madePart($value),
            'innings' => self::innings($value),
            default => self::numeric($value),
        };
    }

    /** Baseball's innings notation as a real number. */
    public static function innings(mixed $value): ?float
    {
        $raw = self::numeric($value);

        if ($raw === null) {
            return null;
        }

        $full = floor(abs($raw));
        $thirds = (int) round((abs($raw) - $full) * 10);

        // Only .0, .1 and .2 are meaningful; anything else is a feed
        // writing a plain decimal, and is taken at face value.
        if ($thirds > 2) {
            return $raw;
        }

        $innings = $full + ($thirds / 3);

        return $raw < 0 ? -$innings : $innings;
    }

    /** The first half of a "made-attempted" compound. */
    public static function madePart(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $parts = preg_split('/[-\/]/', trim($value), 2);

        if ($parts === false || $parts === []) {
            return null;
        }

        return is_numeric(trim($parts[0])) ? (float) trim($parts[0]) : null;
    }

    public static function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric(trim($value)) ? (float) trim($value) : null;
    }
}
