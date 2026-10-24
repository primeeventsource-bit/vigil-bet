<?php

declare(strict_types=1);

use App\Data\ScheduleGame;
use App\Enums\EventStatus;
use App\Enums\Sport;
use App\Models\Event;
use App\Models\EventExternalId;
use App\Models\Team;
use App\Services\Ingestion\EventSynchronizer;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->sync = app(EventSynchronizer::class);

    Team::create(['sport' => 'mlb', 'name' => 'Milwaukee Brewers', 'display_name' => 'Milwaukee Brewers', 'slug' => 'milwaukee-brewers', 'abbreviation' => 'MIL']);
    Team::create(['sport' => 'mlb', 'name' => 'Tampa Bay Rays', 'display_name' => 'Tampa Bay Rays', 'slug' => 'tampa-bay-rays', 'abbreviation' => 'TB']);
});

function mlbGame(string $sourceId, CarbonImmutable $startsAt): ScheduleGame
{
    return new ScheduleGame(
        sport: Sport::Mlb,
        sourceEventId: $sourceId,
        homeTeamName: 'Milwaukee Brewers',
        awayTeamName: 'Tampa Bay Rays',
        startsAt: $startsAt,
    );
}

it('keeps consecutive games of a series apart', function () {
    $day = CarbonImmutable::parse('2026-10-25 00:00:00');

    $stats = $this->sync->sync([
        mlbGame('849802', $day),
        mlbGame('849805', $day->addDay()),
    ], 'mlb_statsapi');

    expect($stats['created'])->toBe(2)
        ->and(Event::count())->toBe(2);
});

it('keeps both halves of a doubleheader apart', function () {
    $first = CarbonImmutable::parse('2026-07-04 17:00:00');

    $this->sync->sync([
        mlbGame('700001', $first),
        mlbGame('700002', $first->addHours(4)),
    ], 'mlb_statsapi');

    expect(Event::count())->toBe(2);
});

it('updates the same game rather than duplicating it', function () {
    $start = CarbonImmutable::parse('2026-10-25 00:00:00');

    $this->sync->sync([mlbGame('849802', $start)], 'mlb_statsapi');

    $final = new ScheduleGame(
        sport: Sport::Mlb,
        sourceEventId: '849802',
        homeTeamName: 'Milwaukee Brewers',
        awayTeamName: 'Tampa Bay Rays',
        startsAt: $start,
        status: EventStatus::Final,
        homeScore: 5,
        awayScore: 3,
    );

    $stats = $this->sync->sync([$final], 'mlb_statsapi');

    expect(Event::count())->toBe(1)
        ->and($stats['updated'])->toBe(1);

    $event = Event::first();

    expect($event->status)->toBe(EventStatus::Final)
        ->and($event->final_home_score)->toBe(5)
        ->and($event->margin())->toBe(2);
});

it('absorbs a rescheduled start time for the same source id', function () {
    $start = CarbonImmutable::parse('2026-10-25 00:00:00');

    $this->sync->sync([mlbGame('849802', $start)], 'mlb_statsapi');
    $this->sync->sync([mlbGame('849802', $start->addHours(2))], 'mlb_statsapi');

    expect(Event::count())->toBe(1)
        ->and(Event::first()->starts_at->toDateTimeString())->toBe('2026-10-25 02:00:00');
});

it('skips a game whose teams cannot be resolved', function () {
    $stats = $this->sync->sync([
        new ScheduleGame(
            sport: Sport::Mlb,
            sourceEventId: '999',
            homeTeamName: 'Nowhere Nobodies',
            awayTeamName: 'Tampa Bay Rays',
            startsAt: CarbonImmutable::parse('2026-10-25 00:00:00'),
        ),
    ], 'mlb_statsapi');

    expect($stats['unresolved'])->toBe(1)
        ->and(Event::count())->toBe(0);
});

it('keeps two leagues apart when a source reuses a game id', function () {
    // ESPN numbers events per league, so the same id is a real and
    // different game in more than one of them. The failure this guards
    // against is the quiet kind: without the sport in the key the second
    // league reads the first league's event, files its lines and results
    // against a game from another sport, and nothing anywhere complains.
    Team::create(['sport' => 'nhl', 'name' => 'Tampa Bay Lightning', 'display_name' => 'Tampa Bay Lightning', 'slug' => 'tampa-bay-lightning', 'abbreviation' => 'TBL']);
    Team::create(['sport' => 'nhl', 'name' => 'Ottawa Senators', 'display_name' => 'Ottawa Senators', 'slug' => 'ottawa-senators', 'abbreviation' => 'OTT']);

    $start = CarbonImmutable::parse('2026-11-02 23:00:00');

    $this->sync->sync([mlbGame('401547417', $start)], 'espn');

    $this->sync->sync([
        new ScheduleGame(
            sport: Sport::Nhl,
            sourceEventId: '401547417',
            homeTeamName: 'Tampa Bay Lightning',
            awayTeamName: 'Ottawa Senators',
            startsAt: $start,
        ),
    ], 'espn');

    expect(Event::count())->toBe(2);

    $bySport = EventExternalId::query()
        ->where('source', 'espn')
        ->where('external_id', '401547417')
        ->get()
        ->keyBy('sport');

    expect($bySport)->toHaveCount(2)
        ->and(Event::find($bySport['mlb']->event_id)->sport)->toBe(Sport::Mlb)
        ->and(Event::find($bySport['nhl']->event_id)->sport)->toBe(Sport::Nhl);
});

it('stamps the sport on a link without being told', function () {
    // Denormalised from the event purely so it can sit in the unique
    // index, so it is derived rather than passed: the two can then never
    // disagree, whichever call site writes the row.
    $this->sync->sync([mlbGame('849802', CarbonImmutable::parse('2026-10-25 00:00:00'))], 'mlb_statsapi');

    expect(EventExternalId::query()->sole()->sport)->toBe('mlb');
});
