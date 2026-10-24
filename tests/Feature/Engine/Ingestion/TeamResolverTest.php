<?php

declare(strict_types=1);

use App\Enums\Sport;
use App\Models\Team;
use App\Models\TeamAlias;
use App\Models\TeamExternalId;
use App\Models\UnmatchedName;
use App\Services\Teams\TeamResolver;

beforeEach(function () {
    $this->resolver = app(TeamResolver::class);

    $this->bills = Team::create([
        'sport' => Sport::Nfl->value,
        'name' => 'Buffalo Bills',
        'display_name' => 'Buffalo Bills',
        'slug' => 'buffalo-bills',
        'abbreviation' => 'BUF',
    ]);
});

it('resolves an exact external id first', function () {
    TeamExternalId::create(['team_id' => $this->bills->id, 'source' => 'espn', 'external_id' => '2']);

    $team = $this->resolver->resolve(Sport::Nfl, 'Completely Wrong Name', 'espn', '2');

    expect($team->id)->toBe($this->bills->id);
});

it('resolves by registered alias', function () {
    TeamAlias::create([
        'sport' => Sport::Nfl->value,
        'source' => null,
        'alias_raw' => 'BUF',
        'alias_norm' => 'buf',
        'team_id' => $this->bills->id,
        'origin' => 'seed',
    ]);

    expect($this->resolver->resolve(Sport::Nfl, 'BUF', 'nflverse')->id)->toBe($this->bills->id);
});

it('resolves by abbreviation when no alias exists', function () {
    expect($this->resolver->resolve(Sport::Nfl, 'buf', 'nflverse')->id)->toBe($this->bills->id);
});

it('learns the external id after a name match so the next lookup is exact', function () {
    $this->resolver->resolve(Sport::Nfl, 'Buffalo Bills', 'espn', '2');

    expect(TeamExternalId::where('source', 'espn')->where('external_id', '2')->value('team_id'))
        ->toBe($this->bills->id);
});

it('logs an unknown name instead of guessing', function () {
    $team = $this->resolver->resolve(Sport::Nfl, 'Buffalo Bills Practice Squad XI', 'oddsapi', context: ['role' => 'home']);

    expect($team)->toBeNull()
        ->and(UnmatchedName::count())->toBe(1);

    $logged = UnmatchedName::first();

    expect($logged->raw_name)->toBe('Buffalo Bills Practice Squad XI')
        ->and($logged->source)->toBe('oddsapi')
        ->and($logged->hits)->toBe(1);
});

it('counts repeat sightings of the same unknown name', function () {
    foreach (range(1, 3) as $ignored) {
        app(TeamResolver::class)->resolve(Sport::Nfl, 'Mystery Team', 'oddsapi');
    }

    expect(UnmatchedName::count())->toBe(1)
        ->and(UnmatchedName::first()->hits)->toBe(3);
});

it('does not cross sports', function () {
    expect($this->resolver->resolve(Sport::Nba, 'Buffalo Bills', 'espn'))->toBeNull();
});

/*
 * Source ids are only unique inside one league.
 */

it('does not hand back another sport team that claimed the same source id', function () {
    // The production bug this replaced. ESPN numbers teams per league, so
    // espn:14 is a real id in the NHL and a different real id in the NBA.
    // The NHL sync ran first and claimed it; every NBA game that referred
    // to team 14 afterwards was quietly given the Ottawa Senators, which
    // is how "UAB Blazers at Tampa Bay Lightning" reached an NBA board.
    $senators = Team::create([
        'sport' => 'nhl', 'name' => 'Ottawa Senators',
        'display_name' => 'Ottawa Senators', 'slug' => 'ottawa-senators',
    ]);
    $knicks = Team::create([
        'sport' => 'nba', 'name' => 'New York Knicks',
        'display_name' => 'New York Knicks', 'slug' => 'new-york-knicks',
    ]);

    $resolver = app(TeamResolver::class);
    $resolver->linkExternalId($senators, 'espn', '14');
    $resolver->linkExternalId($knicks, 'espn', '14');

    expect($resolver->byExternalId('espn', '14', Sport::Nhl)->id)->toBe($senators->id)
        ->and($resolver->byExternalId('espn', '14', Sport::Nba)->id)->toBe($knicks->id);
});

it('resolves by source id within the sport it was asked about', function () {
    $senators = Team::create([
        'sport' => 'nhl', 'name' => 'Ottawa Senators',
        'display_name' => 'Ottawa Senators', 'slug' => 'ottawa-senators',
    ]);
    $knicks = Team::create([
        'sport' => 'nba', 'name' => 'New York Knicks',
        'display_name' => 'New York Knicks', 'slug' => 'new-york-knicks',
    ]);

    $resolver = app(TeamResolver::class);
    $resolver->linkExternalId($senators, 'espn', '14');
    $resolver->linkExternalId($knicks, 'espn', '14');

    // The whole path, as the schedule sync calls it.
    expect($resolver->resolve(Sport::Nba, 'New York Knicks', 'espn', '14')->id)->toBe($knicks->id);
});

it('refuses rather than guessing when only another sport holds that id', function () {
    // No NBA team owns espn:14. Returning the NHL one is worse than
    // returning nothing: nothing gets logged as unmatched and looked at.
    $senators = Team::create([
        'sport' => 'nhl', 'name' => 'Ottawa Senators',
        'display_name' => 'Ottawa Senators', 'slug' => 'ottawa-senators',
    ]);

    $resolver = app(TeamResolver::class);
    $resolver->linkExternalId($senators, 'espn', '14');

    expect($resolver->byExternalId('espn', '14', Sport::Nba))->toBeNull();
});

it('answers without logging or remembering when only asked', function () {
    // resolve() is the pipeline's door: a miss is logged for a human and
    // memoised so the next row does not repeat the work. Both are wrong
    // for code that merely wants to know, and asking the impure version
    // poisoned the memo with a null that outlived the alias fixing it.
    expect($this->resolver->matches(Sport::Nfl, 'Nobody At All', 'espn'))->toBeNull()
        ->and(UnmatchedName::count())->toBe(0);
});

it('forgets a remembered miss once the alias is learned', function () {
    // The resolver is container-scoped, so one process can ask, miss,
    // learn, and ask again. The second answer has to reflect the alias.
    expect($this->resolver->resolve(Sport::Nfl, 'Bflo', 'espn'))->toBeNull();

    $this->resolver->learnAlias($this->bills, 'Bflo', 'espn');

    expect($this->resolver->resolve(Sport::Nfl, 'Bflo', 'espn')?->id)->toBe($this->bills->id);
});

it('forgets a miss even when the alias is learned for no source', function () {
    // The memo is keyed on the source and an alias may have none, so the
    // one cannot look up the other.
    $this->resolver->resolve(Sport::Nfl, 'Bflo', 'the_odds_api');

    $this->resolver->learnAlias($this->bills, 'Bflo');

    expect($this->resolver->resolve(Sport::Nfl, 'Bflo', 'the_odds_api')?->id)->toBe($this->bills->id);
});
