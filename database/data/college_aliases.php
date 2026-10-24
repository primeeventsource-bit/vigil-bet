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
];
