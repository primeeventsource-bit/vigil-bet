<?php

declare(strict_types=1);

use App\Models\Book;
use App\Models\Event;
use App\Models\Team;
use App\Models\TeamAlias;
use App\Models\TeamExternalId;
use App\Services\Teams\NameNormalizer;
use App\Services\Teams\TeamResolver;
use Illuminate\Support\Facades\DB;

/*
 * The real case: an import keyed on the slug left a husk row behind when
 * the normalizer changed, and the next odds pull attached the live game to
 * the new row. The husk is what has to go, not the row with the game on it.
 */

function hawaii(string $slug): Team
{
    return Team::create([
        'sport' => 'ncaaf',
        'name' => "Hawai'i Rainbow Warriors",
        'display_name' => "Hawai'i Rainbow Warriors",
        'slug' => $slug,
        'abbreviation' => 'HAW',
    ]);
}

function opponentFor(string $name = 'Fresno State Bulldogs'): Team
{
    return Team::create([
        'sport' => 'ncaaf',
        'name' => $name,
        'display_name' => $name,
        'slug' => NameNormalizer::slug($name),
    ]);
}

/**
 * Give a team an append-only snapshot, which is what makes it the keeper.
 *
 * Without this a test is relying on the tie-break, and the tie-break is
 * about which row is older rather than about whatever the test is checking.
 */
function holdsHistory(Team $team, Team $opponent): void
{
    $event = Event::create([
        'sport' => 'ncaaf',
        'home_team_id' => $opponent->id,
        'away_team_id' => $team->id,
        'starts_at' => now()->addDay(),
        'status' => 'scheduled',
    ]);

    DB::table('odds_snapshots')->insert([
        'event_id' => $event->id,
        'book_id' => Book::firstOrCreate(['key' => 'hardrockbet'], ['title' => 'Hard Rock Bet'])->id,
        'market' => 'h2h',
        'side' => 'away',
        'selection_team_id' => $team->id,
        'price_american' => -110,
        'price_decimal' => 1.909,
        'pulled_at' => now(),
        'created_at' => now(),
    ]);
}
it('finds two rows for one team however their slugs differ', function () {
    hawaii('hawai-i-rainbow-warriors');
    hawaii('hawaii-rainbow-warriors');

    $this->artisan('edge:teams:dedupe')
        ->expectsOutputToContain('Rainbow Warriors')
        ->assertSuccessful();

    // Reported, not touched, without --apply.
    expect(Team::where('sport', 'ncaaf')->count())->toBe(2);
});

it('keeps the copy that holds immutable history', function () {
    // odds_snapshots is append-only behind a trigger, so the row it points
    // at cannot be merged away - its snapshots could be neither moved nor
    // nulled. That decides the keeper regardless of which row is older.
    // Deliberately the newer row, and deliberately the one with the data:
    // that is how it happened here, and the oldest-wins tie-break must not
    // get a say when something immutable has an opinion.
    $husk = hawaii('hawai-i-rainbow-warriors');
    $live = hawaii('hawaii-rainbow-warriors');

    holdsHistory($live, opponentFor());

    $this->artisan('edge:teams:dedupe', ['--apply' => true])->assertSuccessful();

    expect(Team::find($live->id))->not->toBeNull()
        ->and(Team::find($husk->id))->toBeNull()
        ->and(DB::table('odds_snapshots')->where('selection_team_id', $live->id)->count())->toBe(1);
});

it('moves the games off the copy that is going', function () {
    $husk = hawaii('hawai-i-rainbow-warriors');
    $live = hawaii('hawaii-rainbow-warriors');
    $other = opponentFor();

    holdsHistory($live, $other);

    // A game filed against the husk back when the odds feed resolved to it.
    $stray = Event::create([
        'sport' => 'ncaaf',
        'home_team_id' => $other->id,
        'away_team_id' => $husk->id,
        'starts_at' => now()->addDays(8),
        'status' => 'scheduled',
    ]);

    $this->artisan('edge:teams:dedupe', ['--apply' => true])->assertSuccessful();

    // An event cannot be left pointing at a deleted team: the foreign key
    // restricts the delete, so a missed reference fails loudly rather than
    // leaving a game with no team on one side.
    expect(Team::find($husk->id))->toBeNull()
        ->and($stray->fresh()->away_team_id)->toBe($live->id)
        ->and(Team::where('sport', 'ncaaf')->whereKeyNot($other->id)->count())->toBe(1);
});

it('carries over a source id the keeper does not have', function () {
    $husk = hawaii('hawai-i-rainbow-warriors');
    $live = hawaii('hawaii-rainbow-warriors');

    holdsHistory($live, opponentFor());

    app(TeamResolver::class)->linkExternalId($husk, 'cfbd', '62');

    $this->artisan('edge:teams:dedupe', ['--apply' => true])->assertSuccessful();

    expect(TeamExternalId::where('source', 'cfbd')->where('external_id', '62')->value('team_id'))
        ->toBe($live->id);
});

it('drops a source id the keeper already holds rather than colliding', function () {
    // One row per source id per sport, so the loser's copy cannot be moved
    // on top of it. It says the same thing, so it goes.
    $husk = hawaii('hawai-i-rainbow-warriors');
    $live = hawaii('hawaii-rainbow-warriors');

    holdsHistory($live, opponentFor());

    app(TeamResolver::class)->linkExternalId($husk, 'espn', '62');
    app(TeamResolver::class)->linkExternalId($live, 'espn', '62');

    $this->artisan('edge:teams:dedupe', ['--apply' => true])->assertSuccessful();

    expect(TeamExternalId::where('source', 'espn')->where('external_id', '62')->count())->toBe(1)
        ->and(TeamExternalId::where('source', 'espn')->where('external_id', '62')->value('team_id'))->toBe($live->id);
});

it('keeps the aliases from both copies', function () {
    $husk = hawaii('hawai-i-rainbow-warriors');
    $live = hawaii('hawaii-rainbow-warriors');

    holdsHistory($live, opponentFor());

    app(TeamResolver::class)->learnAlias($husk, 'Hawaii', 'cfbd');
    app(TeamResolver::class)->learnAlias($live, 'Rainbow Warriors', 'the_odds_api');

    $this->artisan('edge:teams:dedupe', ['--apply' => true])->assertSuccessful();

    expect(TeamAlias::where('team_id', $live->id)->count())->toBe(2)
        ->and(TeamAlias::where('team_id', $husk->id)->count())->toBe(0);
});

it('refuses when both copies hold immutable history', function () {
    // Then the snapshots on one of them would have to be rewritten, and
    // which team really played is a question about the world rather than a
    // cleanup. Left for a person, loudly.
    $a = hawaii('hawai-i-rainbow-warriors');
    $b = hawaii('hawaii-rainbow-warriors');

    foreach ([$a, $b] as $team) {
        DB::table('injury_reports')->insert([
            'team_id' => $team->id,
            'sport' => 'ncaaf',
            'source' => 'espn',
            'player_name' => 'A Player',
            'status' => 'out',
            'reported_at' => now(),
            'created_at' => now(),
        ]);
    }

    $this->artisan('edge:teams:dedupe', ['--apply' => true])
        ->expectsOutputToContain('needs a person')
        ->assertSuccessful();

    expect(Team::where('sport', 'ncaaf')->count())->toBe(2);
});

it('says nothing is wrong when nothing is', function () {
    hawaii('hawaii-rainbow-warriors');
    opponentFor();

    $this->artisan('edge:teams:dedupe')
        ->expectsOutputToContain('No duplicate teams')
        ->assertSuccessful();
});

it('does not confuse two teams that merely share a mascot', function () {
    Team::create(['sport' => 'ncaaf', 'name' => 'App State Mountaineers', 'display_name' => 'App State Mountaineers', 'slug' => 'app-state-mountaineers']);
    Team::create(['sport' => 'ncaaf', 'name' => 'West Virginia Mountaineers', 'display_name' => 'West Virginia Mountaineers', 'slug' => 'west-virginia-mountaineers']);

    $this->artisan('edge:teams:dedupe')
        ->expectsOutputToContain('No duplicate teams')
        ->assertSuccessful();
});

it('leaves the other sport alone when narrowed to one', function () {
    // ESPN numbers teams per league and the same college exists in both, so
    // a sport-scoped run must not reach across.
    hawaii('hawai-i-rainbow-warriors');
    hawaii('hawaii-rainbow-warriors');

    Team::create(['sport' => 'ncaab', 'name' => "Hawai'i Rainbow Warriors", 'display_name' => "Hawai'i Rainbow Warriors", 'slug' => 'hawaii-rainbow-warriors']);

    $this->artisan('edge:teams:dedupe', ['--sport' => 'ncaab', '--apply' => true])->assertSuccessful();

    expect(Team::where('sport', 'ncaaf')->count())->toBe(2)
        ->and(Team::where('sport', 'ncaab')->count())->toBe(1);
});
