<?php

declare(strict_types=1);

use App\Enums\Sport;
use App\Models\Team;
use App\Models\TeamAlias;
use App\Models\UnmatchedName;
use App\Services\Teams\NameNormalizer;
use App\Services\Teams\TeamResolver;
use Database\Seeders\CollegeAliasSeeder;

/*
 * The five names the odds feed sent for two days that nothing resolved, and
 * what each one needed. Between them they are every way two feeds can
 * disagree about a college: a punctuation mark, a truncation, a dropped
 * word and a nickname.
 */

it('resolves a name whose only difference is an apostrophe', function () {
    // No alias needed and none written: once the mark stops splitting the
    // word, the two spellings are the same key.
    espnTeam("Hawai'i Rainbow Warriors", '62');

    $team = app(TeamResolver::class)->resolve(Sport::Ncaaf, 'Hawaii Rainbow Warriors', 'the_odds_api');

    expect($team)->not->toBeNull()
        ->and($team->display_name)->toBe("Hawai'i Rainbow Warriors")
        ->and(UnmatchedName::count())->toBe(0);
});

it('refuses to derive a truncated college name', function () {
    // It cannot tell a truncation from a coincidence. The rule that tried
    // got App State right and turned Northwest University Eagles into
    // North Carolina Central Eagles, because "north" starts "northwest".
    espnTeam('App State Mountaineers', '2026');
    espnTeam('West Virginia Mountaineers', '277');
    unmatched('Appalachian State Mountaineers', 'ncaaf');

    $this->artisan('edge:teams:resolve', ['--apply' => true])
        ->expectsOutputToContain('No candidate at all')
        ->assertSuccessful();

    expect(TeamAlias::where('origin', 'auto')->count())->toBe(0)
        ->and(UnmatchedName::count())->toBe(1);
});

it('never prefix-matches one word into a different school', function () {
    // The exactly-one-candidate rule did not save this: widening is what
    // made the wrong team the one candidate.
    espnTeam('North Carolina Central Eagles', '2428');
    unmatched('Northwest University Eagles', 'ncaaf');

    espnTeam('Mississippi Valley State Delta Devils', '2400');
    unmatched('Delta State Statesmen', 'ncaaf');

    $this->artisan('edge:teams:resolve', ['--apply' => true])->assertSuccessful();

    expect(TeamAlias::where('origin', 'auto')->count())->toBe(0)
        ->and(UnmatchedName::count())->toBe(2);
});

it('takes the truncations from the curated list instead', function () {
    espnTeam('App State Mountaineers', '2026');
    espnTeam('Southern Miss Golden Eagles', '2572');
    unmatched('Appalachian State Mountaineers', 'ncaaf');
    unmatched('Southern Mississippi Golden Eagles', 'ncaaf');

    $this->seed(CollegeAliasSeeder::class);

    $resolver = app(TeamResolver::class);

    expect($resolver->resolve(Sport::Ncaaf, 'Appalachian State Mountaineers', 'the_odds_api')?->display_name)
        ->toBe('App State Mountaineers')
        ->and($resolver->resolve(Sport::Ncaaf, 'Southern Mississippi Golden Eagles', 'the_odds_api')?->display_name)
        ->toBe('Southern Miss Golden Eagles')
        ->and(UnmatchedName::count())->toBe(0);
});

it('refuses to guess when a word has no counterpart', function () {
    // Sam Houston State lost a word rather than shortening one, and UMass
    // is not a truncation of Massachusetts. Neither is derivable, so the
    // command must leave both alone instead of picking something close.
    espnTeam('Sam Houston Bearkats', '2534');
    espnTeam('Massachusetts Minutemen', '113');
    unmatched('Sam Houston State Bearkats', 'ncaaf');
    unmatched('UMass Minutemen', 'ncaaf');

    $this->artisan('edge:teams:resolve', ['--apply' => true])
        ->expectsOutputToContain('No candidate at all')
        ->assertSuccessful();

    expect(TeamAlias::count())->toBe(0)
        ->and(UnmatchedName::count())->toBe(2);
});

it('seeds the aliases no rule can derive', function () {
    espnTeam('Sam Houston Bearkats', '2534');
    espnTeam('Massachusetts Minutemen', '113');
    unmatched('Sam Houston State Bearkats', 'ncaaf');
    unmatched('UMass Minutemen', 'ncaaf');

    $this->seed(CollegeAliasSeeder::class);

    $resolver = app(TeamResolver::class);

    expect($resolver->resolve(Sport::Ncaaf, 'UMass Minutemen', 'the_odds_api')?->display_name)
        ->toBe('Massachusetts Minutemen')
        ->and($resolver->resolve(Sport::Ncaaf, 'Sam Houston State Bearkats', 'the_odds_api')?->display_name)
        ->toBe('Sam Houston Bearkats')
        // Cleared from the log, which is there to be worked through rather
        // than to accumulate rows that have already been dealt with.
        ->and(UnmatchedName::count())->toBe(0);
});

it('says what it skipped when the colleges are not imported yet', function () {
    // A fresh install seeds before any college exists, so this has to be a
    // no-op that explains itself rather than a failure.
    $this->seed(CollegeAliasSeeder::class);

    expect(TeamAlias::count())->toBe(0);
});

it('keeps a team through a change in how its name is normalised', function () {
    // The slug is a label and the source id is the identity. Keying the
    // import on the slug meant any normalizer change re-imported every
    // affected team as a second row holding the same ESPN id.
    $team = espnTeam('Hawai i Rainbow Warriors', '62');

    // What the import now does: find by id, then bring the label up to date.
    $found = app(TeamResolver::class)->byExternalId('espn', '62', Sport::Ncaaf);

    expect($found?->id)->toBe($team->id);

    $found->slug = NameNormalizer::slug("Hawai'i Rainbow Warriors");
    $found->save();

    expect(Team::where('sport', 'ncaaf')->count())->toBe(1)
        ->and(Team::find($team->id)->slug)->toBe('hawaii-rainbow-warriors');
});

it('clears a log row for a name that resolves on its own', function () {
    // Hawai'i came good because the normalizer stopped splitting on the
    // apostrophe, not because anything was aliased. Nothing in the hot
    // path clears the row - doing a delete on every successful resolve
    // would cost a query per team per pull - so the command that works
    // the log is where it belongs.
    espnTeam("Hawai'i Rainbow Warriors", '62');
    unmatched('Hawaii Rainbow Warriors', 'ncaaf');

    $this->artisan('edge:teams:resolve', ['--apply' => true])
        ->expectsOutputToContain('resolves now, no alias needed')
        ->assertSuccessful();

    expect(UnmatchedName::count())->toBe(0)
        // No alias written: there was nothing to learn.
        ->and(TeamAlias::where('origin', 'auto')->count())->toBe(0);
});

it('leaves the row alone while only reporting', function () {
    espnTeam("Hawai'i Rainbow Warriors", '62');
    unmatched('Hawaii Rainbow Warriors', 'ncaaf');

    $this->artisan('edge:teams:resolve')->assertSuccessful();

    expect(UnmatchedName::count())->toBe(1);
});
