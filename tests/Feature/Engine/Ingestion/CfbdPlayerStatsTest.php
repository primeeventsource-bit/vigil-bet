<?php

declare(strict_types=1);

use App\Enums\Sport;
use App\Services\Ingestion\CfbdFootballSource;
use Illuminate\Support\Facades\Http;

/*
 * NCAAF player lines stood at 0 of 10,139 finished games, written off as
 * a CFBD limitation. The limitation was real but narrower than recorded:
 * `/games/teams` carries no player lines, and `/games/players` carries
 * them for a whole week in one request.
 *
 * The alternative was ESPN, one request per game, for ten thousand games
 * that have no ESPN id yet. This is sixteen requests a season.
 */
beforeEach(function (): void {
    config()->set('edge.sources.cfbd.key', 'test-key');
    config()->set('edge.sources.cfbd.base_url', 'https://api.collegefootballdata.com');
});

function cfbdPlayerPayload(): array
{
    return [[
        'id' => 401858275,
        'teams' => [[
            'team' => 'North Carolina',
            'conference' => 'ACC',
            'homeAway' => 'home',
            'points' => 27,
            'categories' => [
                ['name' => 'passing', 'types' => [
                    ['name' => 'C/ATT', 'athletes' => [
                        ['id' => '4592532', 'name' => 'Billy Edwards', 'stat' => '27/44'],
                    ]],
                    ['name' => 'YDS', 'athletes' => [
                        ['id' => '4592532', 'name' => 'Billy Edwards', 'stat' => '271'],
                    ]],
                    ['name' => 'TD', 'athletes' => [
                        ['id' => '4592532', 'name' => 'Billy Edwards', 'stat' => '2'],
                    ]],
                ]],
                ['name' => 'rushing', 'types' => [
                    ['name' => 'YDS', 'athletes' => [
                        ['id' => '4687890', 'name' => 'Davion Gause', 'stat' => '88'],
                        ['id' => '4592532', 'name' => 'Billy Edwards', 'stat' => '-3'],
                    ]],
                ]],
            ],
        ]],
    ]];
}

function cfbdPlayerLines(?array $payload = null): array
{
    Http::fake([
        'api.collegefootballdata.com/games/players*' => Http::response($payload ?? cfbdPlayerPayload(), 200),
    ]);

    return collect(app(CfbdFootballSource::class)->playerGameStats(2026, 8))->all();
}

it('reads a week of player lines in one request', function (): void {
    $lines = cfbdPlayerLines();

    $requests = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/games/players'))
        ->count();

    expect($requests)->toBe(1)
        ->and($lines)->not->toBeEmpty();
});

it('keeps a player’s categories apart, because a passing yard is not a rushing yard', function (): void {
    $lines = collect(cfbdPlayerLines())
        ->filter(fn ($line) => $line->playerExternalId === '4592532')
        ->keyBy(fn ($line) => $line->statGroup);

    expect($lines->keys()->sort()->values()->all())->toBe(['passing', 'rushing'])
        ->and($lines['passing']->stats['YDS'])->toBe('271')
        ->and($lines['rushing']->stats['YDS'])->toBe('-3');
});

it('gathers every stat in a category onto one line for the player', function (): void {
    $passing = collect(cfbdPlayerLines())
        ->first(fn ($line) => $line->playerExternalId === '4592532' && $line->statGroup === 'passing');

    expect($passing->stats)->toBe(['C/ATT' => '27/44', 'YDS' => '271', 'TD' => '2'])
        ->and($passing->playerName)->toBe('Billy Edwards')
        ->and($passing->sourceTeamName)->toBe('North Carolina')
        ->and($passing->sourceEventId)->toBe('401858275')
        ->and($passing->sport)->toBe(Sport::Ncaaf)
        ->and($passing->source)->toBe('cfbd');
});

it('keeps the stat exactly as printed, since "27/44" is not a number', function (): void {
    $passing = collect(cfbdPlayerLines())
        ->first(fn ($line) => $line->statGroup === 'passing');

    expect($passing->stats['C/ATT'])->toBeString()->toBe('27/44');
});

it('yields one line per player per category, not one per stat', function (): void {
    expect(cfbdPlayerLines())->toHaveCount(3);
});

it('skips an athlete with no id, because a line we cannot attribute is noise', function (): void {
    $payload = cfbdPlayerPayload();
    $payload[0]['teams'][0]['categories'][0]['types'][0]['athletes'][] =
        ['id' => null, 'name' => 'Unknown', 'stat' => '1'];

    $lines = collect(cfbdPlayerLines($payload));

    expect($lines->contains(fn ($line) => $line->playerName === 'Unknown'))->toBeFalse();
});

it('walks past a week with nothing in it rather than failing', function (): void {
    expect(cfbdPlayerLines([]))->toBe([]);
});

it('asks for every week of a season when given no week', function (): void {
    Http::fake(['api.collegefootballdata.com/games/players*' => Http::response([], 200)]);

    collect(app(CfbdFootballSource::class)->playerGameStats(2026))->all();

    $weeks = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0]->data()['week'] ?? null)
        ->filter()
        ->unique()
        ->values();

    /*
     * Sixteen regular weeks covers championship week, and the
     * postseason is its own season type - the same range teamGameStats
     * walks, for the same reasons.
     */
    expect($weeks)->toHaveCount(16)
        ->and($weeks->first())->toBe(1)
        ->and($weeks->last())->toBe(16);
});

it('refuses to pretend when the key is missing', function (): void {
    config()->set('edge.sources.cfbd.key', null);

    expect(fn () => collect(app(CfbdFootballSource::class)->playerGameStats(2026, 8))->all())
        ->toThrow(RuntimeException::class);
});
