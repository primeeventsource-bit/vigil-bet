<?php

declare(strict_types=1);

use App\Enums\JobStatus;
use App\Enums\Sport;
use App\Models\Event;
use App\Models\JobRun;
use App\Models\Team;
use App\Services\Odds\PullCadence;

function scheduleGameIn(int $minutes, string $sport = 'nfl'): Event
{
    $home = Team::create(['sport' => $sport, 'name' => 'H'.$minutes, 'display_name' => 'H'.$minutes, 'slug' => 'h'.$minutes.$sport]);
    $away = Team::create(['sport' => $sport, 'name' => 'A'.$minutes, 'display_name' => 'A'.$minutes, 'slug' => 'a'.$minutes.$sport]);

    return Event::create([
        'sport' => $sport,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'starts_at' => now()->addMinutes($minutes),
        'status' => 'scheduled',
    ]);
}

function lastPull(int $minutesAgo): void
{
    JobRun::create([
        'job_key' => 'odds:pull:nfl',
        'status' => JobStatus::Success,
        'started_at' => now()->subMinutes($minutesAgo),
        'created_at' => now(),
    ]);
}

beforeEach(fn () => $this->cadence = app(PullCadence::class));

it('pulls every other minute inside the last hour', function () {
    // Close to kickoff the line moves fastest and the closing price is
    // what CLV is measured against, so this is where frequency matters.
    scheduleGameIn(30);

    expect($this->cadence->intervalMinutes(Sport::Nfl))->toBe(2);
});

it('pulls quarter hourly inside twelve hours', function () {
    scheduleGameIn(300);

    expect($this->cadence->intervalMinutes(Sport::Nfl))->toBe(15);
});

it('is still quarter hourly at the far edge of that window', function () {
    scheduleGameIn(11 * 60);

    expect($this->cadence->intervalMinutes(Sport::Nfl))->toBe(15);
});

it('pulls hourly a day out', function () {
    scheduleGameIn(20 * 60);

    expect($this->cadence->intervalMinutes(Sport::Nfl))->toBe(60);
});

it('pulls hourly when games are days away', function () {
    scheduleGameIn(3 * 24 * 60);

    expect($this->cadence->intervalMinutes(Sport::Nfl))->toBe(60);
});

it('does not pull at all when nothing is on the board', function () {
    expect($this->cadence->intervalMinutes(Sport::Nfl))->toBeNull()
        ->and($this->cadence->isDue(Sport::Nfl))->toBeFalse();
});

it('does not pull for games beyond the furthest window', function () {
    scheduleGameIn(30 * 24 * 60);

    expect($this->cadence->isDue(Sport::Nfl))->toBeFalse();
});

it('is due when no pull has happened yet', function () {
    scheduleGameIn(30);

    expect($this->cadence->isDue(Sport::Nfl))->toBeTrue();
});

it('waits out the interval after a recent pull', function () {
    scheduleGameIn(300);
    lastPull(1);

    expect($this->cadence->isDue(Sport::Nfl))->toBeFalse();

    // Fifteen minutes later that pull is stale and the next one is due.
    $this->travel(15)->minutes();

    expect($this->cadence->isDue(Sport::Nfl))->toBeTrue();
});

/*
 * Props run on their own clock and their own job key, so a board pull can
 * never satisfy a props pull or the other way round. They cost a call per
 * game rather than a call per league, which is the whole reason for the
 * second cadence.
 */
it('runs props on a looser clock than the board', function () {
    scheduleGameIn(30, 'nba');

    expect($this->cadence->propsIntervalMinutes(Sport::Nba))->toBe(10)
        ->and($this->cadence->intervalMinutes(Sport::Nba))->toBe(2);
});

it('has no props clock for a sport with no prop markets', function () {
    scheduleGameIn(30);

    expect($this->cadence->propsIntervalMinutes(Sport::Nhl))->toBeNull()
        ->and($this->cadence->isDueForProps(Sport::Nhl))->toBeFalse();
});

it('counts a board pull and a props pull separately', function () {
    scheduleGameIn(30);

    JobRun::create([
        'job_key' => 'odds:pull:nfl',
        'status' => JobStatus::Success,
        'started_at' => now(),
        'created_at' => now(),
    ]);

    // The board is satisfied. Props, which have their own key, are not -
    // and NFL has prop markets, so they are due.
    expect($this->cadence->isDue(Sport::Nfl))->toBeFalse()
        ->and($this->cadence->isDueForProps(Sport::Nfl))->toBeTrue();
});
