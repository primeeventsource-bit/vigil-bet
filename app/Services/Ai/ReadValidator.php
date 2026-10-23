<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Refuses to let a Read reach a customer unless every number in it came
 * from the fact sheet.
 *
 * This is the component the whole feature rests on. A language model
 * writing about probabilities will, occasionally and with total
 * confidence, produce a number that is plausible, well-placed and wrong -
 * and a wrong number in a betting product is not a typo, it is the thing
 * the customer acted on.
 *
 * So the check is mechanical rather than judgemental: pull every numeric
 * token out of the prose, pull every numeric value out of the sheet, and
 * reject the Read if the first set is not contained in the second. It
 * cannot tell whether a sentence is *well reasoned*; it can tell whether
 * the figures in it were given.
 *
 * Deliberately strict in the direction that fails safe. A Read rejected
 * for a number that was actually fine costs one regeneration. A Read
 * accepted with a number that was invented costs the thing the product is
 * selling.
 */
class ReadValidator
{
    /**
     * @param  array<string, mixed>  $factSheet
     * @return array{ok:bool, reason:?string, offending:array<int, string>}
     */
    public function check(string $body, array $factSheet): array
    {
        if (trim($body) === '') {
            return $this->fail('empty');
        }

        if (($banned = $this->bannedWord($body)) !== null) {
            return $this->fail('banned_word:'.$banned);
        }

        $wordCount = str_word_count(strip_tags($body));

        // 60-120 words, with a little slack either side. A Read far
        // outside that is not the thing that was asked for, and the most
        // common cause is the model having answered a different question.
        if ($wordCount < 40 || $wordCount > 180) {
            return $this->fail('length:'.$wordCount);
        }

        $allowed = $this->numbersIn($factSheet);
        $used = $this->numbersInProse($body);
        $offending = [];

        foreach ($used as $number) {
            if (! in_array($number, $allowed, true)) {
                $offending[] = $number;
            }
        }

        if ($offending !== []) {
            return [
                'ok' => false,
                'reason' => 'unsupported_number',
                'offending' => array_values(array_unique($offending)),
            ];
        }

        return ['ok' => true, 'reason' => null, 'offending' => []];
    }

    private function bannedWord(string $body): ?string
    {
        $haystack = mb_strtolower($body);

        foreach ((array) config('ai.banned_words') as $word) {
            // Word-boundary matched, so "locked in" is caught and
            // "unlocked" is not.
            if (preg_match('/\b'.preg_quote(mb_strtolower((string) $word), '/').'\b/u', $haystack) === 1) {
                return (string) $word;
            }
        }

        return null;
    }

    /**
     * Every numeric token the prose states, normalised.
     *
     * @return array<int, string>
     */
    public function numbersInProse(string $body): array
    {
        preg_match_all('/[+-]?\d[\d,]*(?:\.\d+)?%?/u', $body, $matches);

        $numbers = [];

        foreach ($matches[0] as $raw) {
            /*
             * A year, a count of games, an ordinal - the prose will carry
             * small integers that are plainly not claims about this bet
             * ("one of the three markets"). Those are not what this guards
             * against. The rule is: anything with a decimal point, a
             * percent sign, a sign, or more than two digits must be in the
             * sheet. A bare 0-99 is allowed through.
             */
            $normalised = $this->normalise($raw);

            if ($this->isIncidental($raw)) {
                continue;
            }

            $numbers[] = $normalised;
        }

        return array_values(array_unique($numbers));
    }

    /**
     * Every numeric value the sheet offers, in every form it offers it.
     *
     * @param  array<string, mixed>  $factSheet
     * @return array<int, string>
     */
    public function numbersIn(array $factSheet): array
    {
        $allowed = [];

        array_walk_recursive($factSheet, function ($value) use (&$allowed): void {
            if ($value === null || is_bool($value)) {
                return;
            }

            if (is_int($value) || is_float($value)) {
                $allowed[] = $this->normalise((string) $value);
                // A probability stored as 0.76 is quoted as 76%.
                $allowed[] = $this->normalise(rtrim(rtrim(number_format((float) $value * 100, 1), '0'), '.').'%');
                // And an odds integer is quoted with its sign.
                $allowed[] = $this->normalise(($value > 0 ? '+' : '').$value);

                return;
            }

            if (is_string($value)) {
                preg_match_all('/[+-]?\d[\d,]*(?:\.\d+)?%?/u', $value, $m);

                foreach ($m[0] as $raw) {
                    $allowed[] = $this->normalise($raw);
                }
            }
        });

        return array_values(array_unique($allowed));
    }

    /**
     * A bare small integer is prose, not a claim.
     *
     * "all three markets", "two of them" - these are not figures about the
     * bet and demanding they appear in the sheet would reject correct
     * English. Anything carrying a decimal, a percent, a sign, or three or
     * more digits is treated as a claim and must be supported.
     */
    private function isIncidental(string $raw): bool
    {
        if (str_contains($raw, '%') || str_contains($raw, '.')) {
            return false;
        }

        if (str_starts_with($raw, '+') || str_starts_with($raw, '-')) {
            return false;
        }

        $digits = (int) preg_replace('/\D/', '', $raw);

        return $digits < 100;
    }

    /** Comparable form: no commas, no leading plus, trailing zeros trimmed. */
    private function normalise(string $raw): string
    {
        $value = str_replace([',', ' ', '+'], '', trim($raw));
        $isPercent = str_ends_with($value, '%');
        $value = rtrim($value, '%');

        if ($value === '' || ! is_numeric($value)) {
            return $raw;
        }

        $number = (float) $value;
        $formatted = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');

        return $formatted.($isPercent ? '%' : '');
    }

    /** @return array{ok:bool, reason:?string, offending:array<int, string>} */
    private function fail(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'offending' => []];
    }
}
