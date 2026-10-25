<?php

declare(strict_types=1);

use App\Services\Ingestion\CfbdFootballSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * The hourly results pull asks CFBD about the last few days. It used to
 * do that by downloading the whole season and filtering in PHP, which
 * measured at 442 seconds a run in production with 8 of 19 runs dying on
 * max attempts - the games the job existed to read were the ones it
 * could not finish reading.
 *
 * CFBD publishes a calendar: sixteen rows for a season, each a week with
 * a start and an end. One cheap request says which weeks a date range
 * touches, and then only those weeks need fetching.
 */
beforeEach(function (): void {
    config()->set('edge.sources.cfbd.key', 'test-key');
    config()->set('edge.sources.cfbd.base_url', 'https://api.collegefootballdata.com');
    Cache::flush();
});

function cfbdCalendar(): array
{
    return [
        ['season' => 2026, 'week' => 7, 'seasonType' => 'regular',
            'startDate' => '2026-10-12T07:00:00.000Z', 'endDate' => '2026-10-19T06:59:00.000Z'],
        ['season' => 2026, 'week' => 8, 'seasonType' => 'regular',
            'startDate' => '2026-10-19T07:00:00.000Z', 'endDate' => '2026-10-26T06:59:00.000Z'],
        ['season' => 2026, 'week' => 9, 'seasonType' => 'regular',
            'startDate' => '2026-10-26T07:00:00.000Z', 'endDate' => '2026-11-02T07:59:00.000Z'],
    ];
}

function cfbdGameRow(int $week, string $start, int $id): array
{
    return [
        'id' => $id, 'season' => 2026, 'week' => $week, 'seasonType' => 'regular',
        'startDate' => $start, 'completed' => true,
        'homeId' => 1, 'homeTeam' => 'Home '.$id, 'homePoints' => 21,
        'awayId' => 2, 'awayTeam' => 'Away '.$id, 'awayPoints' => 14,
    ];
}

function fakeCfbd(array $calendar, array $gamesByWeek): void
{
    Http::fake([
        'api.collegefootballdata.com/calendar*' => Http::response($calendar, 200),
        'api.collegefootballdata.com/games*' => function ($request) use ($gamesByWeek) {
            $week = (int) ($request->data()['week'] ?? 0);

            return Http::response($gamesByWeek[$week] ?? [], 200);
        },
    ]);
}

function weeksRequested(): array
{
    $weeks = [];

    foreach (Http::recorded() as [$request]) {
        if (str_contains($request->url(), '/games') && isset($request->data()['week'])) {
            $weeks[] = (int) $request->data()['week'];
        }
    }

    sort($weeks);

    return array_values(array_unique($weeks));
}

it('asks only for the weeks the date range touches', function (): void {
    fakeCfbd(cfbdCalendar(), [
        8 => [cfbdGameRow(8, '2026-10-24T20:00:00.000Z', 401907532)],
    ]);

    $games = collect(app(CfbdFootballSource::class)->games(
        CarbonImmutable::parse('2026-10-23T00:00:00Z'),
        CarbonImmutable::parse('2026-10-25T00:00:00Z'),
    ))->all();

    expect(weeksRequested())->toBe([8])
        ->and($games)->toHaveCount(1)
        ->and($games[0]->sourceEventId)->toBe('401907532');
});

it('spans a range that crosses a week boundary', function (): void {
    fakeCfbd(cfbdCalendar(), [
        8 => [cfbdGameRow(8, '2026-10-25T20:00:00.000Z', 1)],
        9 => [cfbdGameRow(9, '2026-10-27T20:00:00.000Z', 2)],
    ]);

    $games = collect(app(CfbdFootballSource::class)->games(
        CarbonImmutable::parse('2026-10-25T00:00:00Z'),
        CarbonImmutable::parse('2026-10-28T00:00:00Z'),
    ))->all();

    expect(weeksRequested())->toBe([8, 9])
        ->and($games)->toHaveCount(2);
});

it('does not fetch a week the range never reaches', function (): void {
    fakeCfbd(cfbdCalendar(), [
        8 => [cfbdGameRow(8, '2026-10-24T20:00:00.000Z', 1)],
        7 => [cfbdGameRow(7, '2026-10-15T20:00:00.000Z', 99)],
    ]);

    collect(app(CfbdFootballSource::class)->games(
        CarbonImmutable::parse('2026-10-23T00:00:00Z'),
        CarbonImmutable::parse('2026-10-25T00:00:00Z'),
    ))->all();

    expect(weeksRequested())->not->toContain(7);
});

it('reads the calendar once across repeated pulls, because a season schedule does not move hourly', function (): void {
    fakeCfbd(cfbdCalendar(), [8 => [cfbdGameRow(8, '2026-10-24T20:00:00.000Z', 1)]]);

    $source = app(CfbdFootballSource::class);
    $range = [CarbonImmutable::parse('2026-10-23T00:00:00Z'), CarbonImmutable::parse('2026-10-25T00:00:00Z')];

    collect($source->games(...$range))->all();
    collect($source->games(...$range))->all();

    $calendarCalls = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/calendar'))
        ->count();

    expect($calendarCalls)->toBe(1);
});

/*
 * The fallback matters more than the optimisation. A calendar that fails
 * must not mean no results at all - the old whole-season query is slow,
 * not wrong, so it stays as the path of last resort.
 */
it('falls back to the whole season when the calendar cannot be read', function (): void {
    Http::fake([
        'api.collegefootballdata.com/calendar*' => Http::response([], 500),
        'api.collegefootballdata.com/games*' => Http::response(
            [cfbdGameRow(8, '2026-10-24T20:00:00.000Z', 401907532)], 200
        ),
    ]);

    $games = collect(app(CfbdFootballSource::class)->games(
        CarbonImmutable::parse('2026-10-23T00:00:00Z'),
        CarbonImmutable::parse('2026-10-25T00:00:00Z'),
    ))->all();

    expect($games)->toHaveCount(1)
        ->and(weeksRequested())->toBe([]);
});

it('falls back when the calendar has no week covering the range', function (): void {
    Http::fake([
        'api.collegefootballdata.com/calendar*' => Http::response([
            ['season' => 2026, 'week' => 1, 'seasonType' => 'regular',
                'startDate' => '2026-08-30T07:00:00.000Z', 'endDate' => '2026-09-06T06:59:00.000Z'],
        ], 200),
        'api.collegefootballdata.com/games*' => Http::response(
            [cfbdGameRow(8, '2026-10-24T20:00:00.000Z', 401907532)], 200
        ),
    ]);

    expect(collect(app(CfbdFootballSource::class)->games(
        CarbonImmutable::parse('2026-10-23T00:00:00Z'),
        CarbonImmutable::parse('2026-10-25T00:00:00Z'),
    ))->all())->toHaveCount(1);
});
