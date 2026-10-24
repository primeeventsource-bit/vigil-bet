<?php

declare(strict_types=1);

/**
 * College names that no rule can reconcile.
 *
 * The odds feed and ESPN disagree about how to write a college, and most of
 * the disagreements are truncations that edge:teams:resolve works out on
 * its own - App State for Appalachian State, Southern Miss for Southern
 * Mississippi. These are the ones it cannot, because the two names share no
 * derivable relationship: UMass is not a truncation of Massachusetts, and
 * Sam Houston State lost the word "State" rather than shortening it.
 *
 * Nothing here is a guess. Each line is a fact about two names for one
 * school, and the point of writing them down rather than aliasing them by
 * hand in production is that a fresh install gets them too.
 *
 * Each row: [sport, the name a feed sends, the ESPN display name it means].
 * The target is matched on ESPN's spelling, so it stays correct whatever
 * the normalizer does to either side later.
 */
return [
    ['ncaaf', 'UMass Minutemen', 'Massachusetts Minutemen'],
    ['ncaaf', 'Sam Houston State Bearkats', 'Sam Houston Bearkats'],

    /*
     * These two were briefly derived by a rule that accepted a target word
     * which merely started the same way as a raw one. It got them right
     * and got Northwest University and Delta State badly wrong, because
     * "north" starts "northwest" and "state" starts "statesmen". The rule
     * is gone; the two answers it happened to get right are written down
     * here, checked, where they cost nothing to be sure of.
     */
    ['ncaaf', 'Appalachian State Mountaineers', 'App State Mountaineers'],
    ['ncaaf', 'Southern Mississippi Golden Eagles', 'Southern Miss Golden Eagles'],

    /*
     * College basketball, after edge:teams:resolve had taken the seventy
     * it could derive on its own. These are what was left above twenty
     * hits, and every one was checked against the teams table rather than
     * recalled - the mascot is what survives a college renaming itself,
     * so it is what the match was made on.
     *
     * Three kinds appear here. A school that changed its name (IUPUI
     * became IU Indianapolis, UMKC became Kansas City, Arkansas-Little
     * Rock became Little Rock). A school whose system abbreviation the
     * feed spells out differently (CSU for Cal State). And a mascot the
     * two sources disagree about: Grand Canyon's teams are the Lopes to
     * ESPN and the Antelopes to the odds feed, and Long Beach State is
     * the Beach to one and the 49ers to the other.
     *
     * Four more were left alone because the school is genuinely not in
     * the table - Lindenwood, Queens, Southern Indiana and Saint Francis
     * (PA) are recent Division I arrivals that ESPN's list does not
     * carry. An alias pointing at nothing is worse than an unmatched
     * name, because the log stops showing the problem.
     */
    ['ncaab', 'Long Beach St 49ers', 'Long Beach State Beach'],
    ['ncaab', 'CSU Fullerton Titans', 'Cal State Fullerton Titans'],
    ['ncaab', 'CSU Bakersfield Roadrunners', 'Cal State Bakersfield Roadrunners'],
    ['ncaab', 'CSU Northridge Matadors', 'Cal State Northridge Matadors'],
    ['ncaab', 'Appalachian St Mountaineers', 'App State Mountaineers'],
    ['ncaab', 'Grand Canyon Antelopes', 'Grand Canyon Lopes'],
    ['ncaab', 'LIU Sharks', 'Long Island University Sharks'],
    ['ncaab', 'Arkansas-Little Rock Trojans', 'Little Rock Trojans'],
    ['ncaab', 'IUPUI Jaguars', 'IU Indianapolis Jaguars'],
    ['ncaab', 'UMKC Kangaroos', 'Kansas City Roos'],
    ['ncaab', 'Tenn-Martin Skyhawks', 'UT Martin Skyhawks'],
    ['ncaab', "Florida Int'l Golden Panthers", 'Florida International Panthers'],
    ['ncaaf', 'St. Thomas (MN)', 'St. Thomas Tommies'],
];
