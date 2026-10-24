<?php

declare(strict_types=1);

namespace App\Modeling\Features;

/**
 * The feature vector, named and ordered.
 *
 * The order is part of the contract: a trained model stores weights by
 * position, so reordering this list silently repoints every weight at the
 * wrong feature. VERSION is bumped whenever the list changes, and a model
 * trained against an older version is refused rather than reinterpreted.
 *
 * Every feature here is knowable before kickoff. That is the whole rule.
 * Anything the source only publishes afterwards - final weather, snap
 * counts, injuries that first appear in the box score - is leakage however
 * predictive it looks in a backtest.
 *
 * The first feature is Elo's own answer, in log odds. That is deliberate:
 * it makes the model a correction to the rating system rather than a rival
 * to it, so if the other nine features carry no information the fit can
 * recover Elo exactly and the comparison means something. A model that
 * cannot reproduce the thing it claims to beat has proved nothing.
 */
final class FeatureSchema
{
    public const VERSION = 1;

    /** @var array<int, string> */
    public const FEATURES = [
        // What the rating system already knows, home advantage and neutral
        // site included, expressed as log odds so the model can scale it.
        'elo_logit',
        // Rest. A short week is a real disadvantage and the schedule says so
        // weeks in advance.
        'rest_diff',
        'home_short_week',
        'away_short_week',
        // Venue, fixed when the schedule is published. A fixed dome and a
        // retractable roof are separate features on purpose: see the note in
        // FeatureBuilder on why the roof's actual state cannot be one.
        'indoors',
        'retractable',
        'divisional',
        // Form, from games already finished and nothing else.
        'home_form',
        'away_form',
        'home_margin_form',
        'away_margin_form',
    ];

    /**
     * Plain English for each feature, for the game detail page.
     *
     * A contribution of -0.21 against "away_short_week" explains nothing to
     * anyone who is not already holding the schema in their head, and a
     * number nobody can read is a number nobody can argue with.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        'elo_logit' => 'Power rating',
        'rest_diff' => 'Rest advantage',
        'home_short_week' => 'Home on a short week',
        'away_short_week' => 'Away on a short week',
        'indoors' => 'Indoors',
        'retractable' => 'Retractable roof',
        'divisional' => 'Divisional game',
        'home_form' => 'Home recent form',
        'away_form' => 'Away recent form',
        'home_margin_form' => 'Home recent margins',
        'away_margin_form' => 'Away recent margins',
    ];

    public static function count(): int
    {
        return count(self::FEATURES);
    }

    /**
     * The readable name of a feature, or the stored key when a model
     * predates the label. Falling back to the key keeps an older
     * prediction readable instead of blank.
     */
    public static function describe(string $name): string
    {
        return self::LABELS[$name] ?? $name;
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return self::FEATURES;
    }

    /**
     * Pull a vector out of a named map in the declared order, so a caller
     * cannot hand the model its features shuffled.
     *
     * @param  array<string, float>  $named
     * @return array<int, float>
     */
    public static function vector(array $named): array
    {
        $vector = [];

        foreach (self::FEATURES as $name) {
            $vector[] = (float) ($named[$name] ?? 0.0);
        }

        return $vector;
    }

    /**
     * Pair numbers back up with their names, for reporting what the model
     * actually learned or why it priced one game the way it did.
     *
     * @param  array<int, float>  $values
     * @return array<string, float>
     */
    public static function label(array $values): array
    {
        $labelled = [];

        foreach (self::FEATURES as $index => $name) {
            $labelled[$name] = $values[$index] ?? 0.0;
        }

        return $labelled;
    }
}
