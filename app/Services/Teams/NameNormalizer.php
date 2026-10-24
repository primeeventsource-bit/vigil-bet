<?php

declare(strict_types=1);

namespace App\Services\Teams;

use Illuminate\Support\Str;

/**
 * Turns a team name from any source into a stable lookup key.
 *
 * It deliberately does NOT try to be clever about matching: it lowercases,
 * strips punctuation and known noise words, and collapses whitespace. Fuzzy
 * guessing is what produces silently wrong joins, so anything that does not
 * resolve exactly is logged for a human instead.
 */
final class NameNormalizer
{
    private const NOISE = [
        'st louis' => 'saint louis',
        'st.' => 'saint',
        'mt.' => 'mount',
        'university of' => '',
        'university' => '',
        'state university' => 'state',
        ' univ' => ' ',
    ];

    public static function normalize(string $name): string
    {
        // Transliterate first: sources disagree about accents (the NHL feed
        // says Montreal with an accent, the odds feed does not), and
        // stripping them as punctuation would split the word.
        $value = mb_strtolower(trim(Str::ascii($name)));

        $value = str_replace(['&', '+'], ' and ', $value);

        // Apostrophes are removed, not split on. Every other mark becomes a
        // space below, which is right for a hyphen or a slash and wrong
        // here: it turns Hawai'i into "hawai i" and St John's into
        // "saint john s", so the source that writes the apostrophe and the
        // source that does not land on different keys. ESPN writes
        // Hawai'i and the odds feed writes Hawaii, and that one character
        // was quietly dropping every game that team played.
        //
        // Str::ascii has already folded the typographic and Hawaiian
        // variants down to a plain quote, so one character covers all of
        // them.
        $value = str_replace("'", '', $value);

        foreach (self::NOISE as $search => $replace) {
            $value = str_replace($search, $replace, $value);
        }

        $value = preg_replace('/[^a-z0-9 ]/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /** A URL-safe slug, used as the stable per-sport team key. */
    public static function slug(string $name): string
    {
        return str_replace(' ', '-', self::normalize($name));
    }
}
