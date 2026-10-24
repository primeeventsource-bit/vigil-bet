<?php

declare(strict_types=1);

use App\Data\LiveGameState;
use App\Enums\EventStatus;
use App\Enums\Sport;
use App\Exceptions\AppendOnlyViolation;
use App\Models\LiveFeedProbe;
use App\Services\Live\EspnLiveSource;
use App\Services\Live\FeedProbe;
use App\Services\Live\LiveSourceRegistry;
use App\Services\Live\NhlLiveSource;
use Illuminate\Support\Facades\Http;

it('excludes the clock from what counts as a change', function () {
    // The clock advances on every reading. Folding it into the digest would
    // mark every poll as a change and bury the one measurement that matters.
    $first = new LiveGameState('1', EventStatus::InProgress, 108, 105, 4, 134);
    $later = new LiveGameState('1', EventStatus::InProgress, 108, 105, 4, 97);
    $scored = new LiveGameState('1', EventStatus::InProgress, 111, 105, 4, 97);

    expect($first->materialDigest())->toBe($later->materialDigest())
        ->and($scored->materialDigest())->not->toBe($first->materialDigest());
});

it('counts a possession change as material', function () {
    // In football it moves a win probability as sharply as a score does.
    $ours = new LiveGameState('1', EventStatus::InProgress, 21, 20, 4, 90, possession: 'home');
    $theirs = new LiveGameState('1', EventStatus::InProgress, 21, 20, 4, 90, possession: 'away');

    expect($ours->materialDigest())->not->toBe($theirs->materialDigest());
});

it('matches the same game across feeds that name teams differently', function () {
    $espn = new LiveGameState('401', EventStatus::InProgress, homeName: 'Montréal Canadiens', awayName: 'Boston Bruins');
    $nhl = new LiveGameState('2026', EventStatus::InProgress, homeName: 'Montreal Canadiens', awayName: 'Boston Bruins');

    expect($espn->gameKey())->toBe($nhl->gameKey())->and($espn->gameKey())->not->toBeNull();
});

it('has no game key without both team names', function () {
    expect((new LiveGameState('1', EventStatus::InProgress, homeName: 'Boston Bruins'))->gameKey())->toBeNull();
});

it('writes one reading per live game per source', function () {
    Http::fake([
        'site.api.espn.com/*' => Http::response(espnScoreboard(21, 20)),
        'api-web.nhle.com/*' => Http::response(nhlScore(3, 2)),
    ]);

    $result = app(FeedProbe::class)->sweepGameState(Sport::Nhl, 'session-a');

    // Both feeds answer for the NHL, which is the point: one checks the other.
    expect($result['probes'])->toBe(2)
        ->and($result['live'])->toBe(2)
        ->and($result['changes'])->toBe(0)
        ->and(LiveFeedProbe::query()->pluck('source')->all())->toContain('espn', 'nhl_api');
});

it('records a null staleness rather than inventing one', function () {
    // Neither of these feeds says when its reading was true. That is a
    // finding, and a zero would hide it.
    Http::fake([
        'site.api.espn.com/*' => Http::response(espnScoreboard(21, 20)),
        'api-web.nhle.com/*' => Http::response(nhlScore(3, 2)),
    ]);

    app(FeedProbe::class)->sweepGameState(Sport::Nhl, 'session-a');

    expect(LiveFeedProbe::query()->whereNotNull('staleness_ms')->count())->toBe(0)
        ->and(LiveFeedProbe::query()->whereNotNull('source_reported_at')->count())->toBe(0);
});

it('marks a reading changed only when something material moved', function () {
    Http::fake([
        'site.api.espn.com/*' => Http::response(['events' => []]),
        'api-web.nhle.com/*' => Http::sequence()
            ->push(nhlScore(3, 2))
            // Same score, the clock has simply run on.
            ->push(nhlScore(3, 2))
            // A goal.
            ->push(nhlScore(4, 2)),
    ]);

    $probe = app(FeedProbe::class);

    $first = $probe->sweepGameState(Sport::Nhl, 'session-a');
    $second = $probe->sweepGameState(Sport::Nhl, 'session-a', $first['digests']);
    $third = $probe->sweepGameState(Sport::Nhl, 'session-a', $second['digests']);

    expect($first['changes'])->toBe(0)
        ->and($second['changes'])->toBe(0)
        ->and($third['changes'])->toBe(1)
        ->and(LiveFeedProbe::query()->where('changed', true)->first()->source)->toBe('nhl_api');
});

it('records a feed that falls over instead of skipping it', function () {
    // How often a source breaks is one of the things being measured.
    Http::fake([
        'site.api.espn.com/*' => Http::response('', 503),
        'api-web.nhle.com/*' => Http::response(nhlScore(3, 2)),
    ]);

    $result = app(FeedProbe::class)->sweepGameState(Sport::Nhl, 'session-a');

    $failed = LiveFeedProbe::query()->where('ok', false)->first();

    expect($failed)->not->toBeNull()
        ->and($failed->source)->toBe('espn')
        ->and($failed->error)->not->toBeNull()
        ->and($result['live'])->toBe(1);
});

it('ignores a game that is not under way', function () {
    Http::fake([
        'site.api.espn.com/*' => Http::response(espnScoreboard(0, 0, state: 'STATUS_SCHEDULED')),
        'api-web.nhle.com/*' => Http::response(nhlScore(0, 0, state: 'FUT')),
    ]);

    expect(app(FeedProbe::class)->sweepGameState(Sport::Nhl, 'session-a')['live'])->toBe(0);
});

it('keeps possession and down from the football feed', function () {
    Http::fake(['site.api.espn.com/*' => Http::response(espnScoreboard(21, 20))]);

    $states = (new EspnLiveSource(Sport::Nfl))->inPlay();

    expect($states)->toHaveCount(1)
        // Possession is reported as a team id; it only means anything once
        // resolved to a side.
        ->and($states[0]->possession)->toBe('home')
        ->and($states[0]->situation)->toBe('3rd & 7')
        ->and($states[0]->clockSeconds)->toBe(134);
});

it('reads the clock the NHL publishes directly', function () {
    Http::fake(['api-web.nhle.com/*' => Http::response(nhlScore(3, 2))]);

    $states = (new NhlLiveSource)->inPlay();

    expect($states[0]->clockSeconds)->toBe(254)
        ->and($states[0]->period)->toBe(3)
        ->and($states[0]->homeName)->toBe('Boston Bruins');
});

it('says a sport has two feeds only when it really does', function () {
    $registry = new LiveSourceRegistry;

    expect($registry->for(Sport::Nhl))->toHaveCount(2)
        ->and($registry->for(Sport::Nba))->toHaveCount(1)
        ->and(array_map(fn (Sport $s) => $s->value, $registry->comparable()))
        ->toBe(['mlb', 'nhl']);
});

it('reads no price at all without a key', function () {
    config()->set('edge.odds_api.key', null);

    $result = app(FeedProbe::class)->sweepOdds(Sport::Nhl, 'session-a');

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toContain('ODDS_API_KEY')
        ->and(LiveFeedProbe::query()->odds()->count())->toBe(0);
});

it('keeps only the games already under way', function () {
    config()->set('edge.odds_api.key', 'test-key');

    Http::fake(['api.the-odds-api.com/*' => Http::response([
        [
            'id' => 'live-game',
            'sport_key' => 'icehockey_nhl',
            'commence_time' => now()->subMinutes(40)->toIso8601String(),
            'home_team' => 'Boston Bruins',
            'away_team' => 'Montreal Canadiens',
            'bookmakers' => [[
                'key' => 'draftkings',
                'title' => 'DraftKings',
                'markets' => [[
                    'key' => 'h2h',
                    'last_update' => now()->subSeconds(12)->toIso8601String(),
                    'outcomes' => [
                        ['name' => 'Boston Bruins', 'price' => -180],
                        ['name' => 'Montreal Canadiens', 'price' => 150],
                    ],
                ]],
            ]],
        ],
        [
            'id' => 'later-game',
            'sport_key' => 'icehockey_nhl',
            'commence_time' => now()->addHours(3)->toIso8601String(),
            'home_team' => 'Toronto Maple Leafs',
            'away_team' => 'Ottawa Senators',
            'bookmakers' => [[
                'key' => 'draftkings',
                'title' => 'DraftKings',
                'markets' => [[
                    'key' => 'h2h',
                    'last_update' => now()->toIso8601String(),
                    'outcomes' => [
                        ['name' => 'Toronto Maple Leafs', 'price' => -140],
                        ['name' => 'Ottawa Senators', 'price' => 120],
                    ],
                ]],
            ]],
        ],
    ])]);

    $result = app(FeedProbe::class)->sweepOdds(Sport::Nhl, 'session-a');

    expect($result['in_play'])->toBe(1)
        ->and($result['upcoming'])->toBe(1)
        ->and(LiveFeedProbe::query()->odds()->count())->toBe(2)
        ->and(LiveFeedProbe::query()->odds()->pluck('external_ref')->unique()->all())->toBe(['live-game']);
});

it('measures how old the book said its own price was', function () {
    // The one staleness in this whole audit that can be read off directly
    // rather than inferred from a second feed.
    config()->set('edge.odds_api.key', 'test-key');

    Http::fake(['api.the-odds-api.com/*' => Http::response([[
        'id' => 'live-game',
        'sport_key' => 'icehockey_nhl',
        'commence_time' => now()->subMinutes(40)->toIso8601String(),
        'home_team' => 'Boston Bruins',
        'away_team' => 'Montreal Canadiens',
        'bookmakers' => [[
            'key' => 'draftkings',
            'title' => 'DraftKings',
            'markets' => [[
                'key' => 'h2h',
                'last_update' => now()->subSeconds(20)->toIso8601String(),
                'outcomes' => [
                    ['name' => 'Boston Bruins', 'price' => -180],
                    ['name' => 'Montreal Canadiens', 'price' => 150],
                ],
            ]],
        ]],
    ]])]);

    app(FeedProbe::class)->sweepOdds(Sport::Nhl, 'session-a');

    $probe = LiveFeedProbe::query()->odds()->first();

    expect($probe->staleness_ms)->toBeGreaterThanOrEqual(19_000)
        ->and($probe->staleness_ms)->toBeLessThan(22_000);
});

it('joins a price to the game state by the same key', function () {
    config()->set('edge.odds_api.key', 'test-key');

    Http::fake([
        'api-web.nhle.com/*' => Http::response(nhlScore(3, 2)),
        'api.the-odds-api.com/*' => Http::response([[
            'id' => 'live-game',
            'sport_key' => 'icehockey_nhl',
            'commence_time' => now()->subMinutes(40)->toIso8601String(),
            'home_team' => 'Montreal Canadiens',
            'away_team' => 'Boston Bruins',
            'bookmakers' => [[
                'key' => 'draftkings',
                'title' => 'DraftKings',
                'markets' => [[
                    'key' => 'h2h',
                    'last_update' => now()->toIso8601String(),
                    'outcomes' => [
                        ['name' => 'Montreal Canadiens', 'price' => 150],
                        ['name' => 'Boston Bruins', 'price' => -180],
                    ],
                ]],
            ]],
        ]]),
        'site.api.espn.com/*' => Http::response(['events' => []]),
    ]);

    $probe = app(FeedProbe::class);
    $probe->sweepGameState(Sport::Nhl, 'session-a');
    $probe->sweepOdds(Sport::Nhl, 'session-a');

    // The NHL feed has Boston at home, the odds feed has Montreal. The keys
    // must not collide: these are different fixtures of the same two teams.
    $stateKey = LiveFeedProbe::query()->gameState()->value('game_key');
    $oddsKey = LiveFeedProbe::query()->odds()->value('game_key');

    expect($stateKey)->toBe('montreal-canadiens-at-boston-bruins')
        ->and($oddsKey)->toBe('boston-bruins-at-montreal-canadiens');
});

it('never rewrites a reading', function () {
    Http::fake(['api-web.nhle.com/*' => Http::response(nhlScore(3, 2)), 'site.api.espn.com/*' => Http::response(['events' => []])]);

    app(FeedProbe::class)->sweepGameState(Sport::Nhl, 'session-a');

    $probe = LiveFeedProbe::query()->first();

    expect(fn () => $probe->update(['home_score' => 99]))
        ->toThrow(AppendOnlyViolation::class);
});
