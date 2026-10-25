<?php

declare(strict_types=1);

namespace App\Services\Players;

use Illuminate\Support\Str;

/**
 * The key that joins a priced prop to a box score.
 *
 * There is no players table - players are identified by the source's own
 * id plus a name - and the odds feed does not carry anybody's id. So the
 * only join available between "the book is pricing this player" and "here
 * is what he has done" is his name, written by two sources that disagree
 * about punctuation and suffixes.
 *
 * Measured against production, the disagreement costs 76 of 520 priced
 * NFL players and 13 of 228 in the NBA. Read as a list the near misses
 * are all the same three things:
 *
 *   initials   - the odds feed writes "C.J. McCollum", ESPN "CJ McCollum"
 *   suffixes   - "Jabari Smith Jr" against "Jabari Smith Jr.", and
 *                "Derrick Jones" against "Derrick Jones Jr."
 *   not people - "Arizona Cardinals D/ST" is a fantasy entry, and no
 *                amount of matching will find his box score
 *
 * The first two are fixed here. The third is detected and refused, which
 * is the only honest answer: a team defence has no player line, so a
 * projection for one would have to be invented.
 *
 * What this deliberately does not do is guess. No edit distance, no
 * first-initial-plus-surname fallback, no "closest match". A prop
 * projection joined to the wrong player is worse than no projection: it
 * is a confident number about somebody else, and nothing downstream could
 * tell. Anything that does not resolve exactly after normalising is
 * reported unmatched and left alone.
 */
final class PlayerNameKey
{
    /**
     * Generational suffixes, which the two feeds disagree about freely.
     *
     * Stripped from both sides rather than from one, because the
     * disagreement runs both ways: the odds feed writes "Jabari Smith Jr"
     * where ESPN writes "Jabari Smith Jr.", and writes "Derrick Jones"
     * where ESPN writes "Derrick Jones Jr."
     *
     * This is the one lossy step here, and it can in principle collide a
     * father with a son. That is why the resolver refuses a key claimed
     * by two different players rather than picking one.
     */
    private const SUFFIXES = ['jr', 'sr', 'ii', 'iii', 'iv', 'v'];

    /**
     * Markers of a fantasy team entry rather than a person.
     *
     * The props feed prices team defences alongside players, under the
     * same `description` field. They are not people and have no player
     * line, so they are refused rather than searched for.
     */
    private const NOT_A_PERSON = ['d/st', 'dst', 'defense', 'defence'];

    public static function for(string $name): string
    {
        // Transliterate before stripping punctuation, so an accent folds
        // into its letter instead of being replaced by a space and
        // splitting the word.
        $value = mb_strtolower(trim(Str::ascii($name)));

        /*
         * Periods and apostrophes are removed, not spaced. That is the
         * whole initials fix: "a.j." becomes "aj" and matches the feed
         * that wrote "AJ", where spacing it would give "a j" and match
         * neither. Every other mark - hyphens in "Amon-Ra", slashes -
         * becomes a space, which is right for a word boundary.
         */
        $value = str_replace(['.', "'"], '', $value);
        $value = preg_replace('/[^a-z0-9 ]/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = trim($value);

        return self::withoutSuffix($value);
    }

    /**
     * Whether this is a person at all.
     *
     * False for the team entries the props feed mixes in with players.
     */
    public static function isPerson(string $name): bool
    {
        $lower = mb_strtolower(trim($name));

        foreach (self::NOT_A_PERSON as $marker) {
            if (str_contains($lower, $marker)) {
                return false;
            }
        }

        // A single word is a team or a placeholder, not a player the
        // box scores file under a full name.
        return str_contains(trim($lower), ' ');
    }

    /**
     * Drop one trailing generational suffix.
     *
     * One, not all: "Robert Griffin III" has a suffix and "Robert
     * Griffin" has none, and peeling repeatedly would eat a surname that
     * happens to be on the list - every player named Jackson Jr. would
     * become "jackson".
     */
    private static function withoutSuffix(string $value): string
    {
        $parts = explode(' ', $value);

        // Never strip down to a bare surname. "Jones Jr" keeping only
        // "jones" would match every Jones in the league.
        if (count($parts) < 3) {
            return $value;
        }

        if (in_array(end($parts), self::SUFFIXES, true)) {
            array_pop($parts);
        }

        return implode(' ', $parts);
    }
}
